import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia } from 'pinia';
import { http } from '../lib/http';
import ItemImagesPanel from './ItemImagesPanel.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const primary = { type: 'item-images', id: 'image-1', caption: 'Box label', is_primary: true, uploaded_at: '2026-09-30T12:00:00Z', thumbnail_url: '/api/images/1/thumb', display_url: '/api/images/1/display', thumbnail_width: 320, thumbnail_height: 240, display_width: 1600, display_height: 1200, thumbnail_mime_type: 'image/webp', display_mime_type: 'image/jpeg' };
const secondary = { ...primary, id: 'image-2', caption: null, is_primary: false, thumbnail_url: '/api/images/2/thumb', display_url: '/api/images/2/display' };
const originalShowModal = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');

describe('item images panel', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: [primary, secondary] } } as never);
        vi.mocked(http.post).mockResolvedValue({ data: { data: { ...primary, id: 'image-3' } } } as never);
        vi.mocked(http.patch).mockResolvedValue({ data: { data: primary } } as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: function (this: HTMLDialogElement) { this.setAttribute('open', ''); } });
        Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value: function (this: HTMLDialogElement) { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); } });
    });

    afterEach(() => {
        wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks();
        if (originalShowModal) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', originalShowModal);
        else delete (HTMLDialogElement.prototype as Partial<HTMLDialogElement>).showModal;
        if (originalClose) Object.defineProperty(HTMLDialogElement.prototype, 'close', originalClose);
        else delete (HTMLDialogElement.prototype as Partial<HTMLDialogElement>).close;
    });

    function mountPanel(): void {
        wrapper = mount(ItemImagesPanel, { props: { itemId: '7' }, global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient: client }]] } });
    }

    it('shows the full primary image and secondary thumbnails, with an enlargement dialog', async () => {
        mountPanel();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/items/7/images', { params: { include: 'uploaded_by', per_page: 100, page: 1 } });
        expect(wrapper!.find('img[src="/api/images/1/display"]').attributes('src')).toBe('/api/images/1/display');
        expect(wrapper!.find('img[src="/api/images/2/thumb"]').attributes('src')).toBe('/api/images/2/thumb');
        await wrapper!.get('button[aria-label="Enlarge Box label"]').trigger('click');
        expect(wrapper!.get('dialog[open]').attributes('aria-labelledby')).toBe('photo-preview-title');
    });

    it('sets the primary photo and confirms deletion', async () => {
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        mountPanel();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Make primary')!.trigger('click');
        await flushPromises();
        expect(http.patch).toHaveBeenCalledWith('/item-images/image-2', { data: { is_primary: true } });
        await wrapper!.findAll('button').find((button) => button.text() === 'Remove photo')!.trigger('click');
        await flushPromises();
        expect(window.confirm).toHaveBeenCalledWith('Delete this photo?');
        expect(http.delete).toHaveBeenCalledWith('/item-images/image-1');
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['archived-child-records', 'item-images', 'items', '7'] });
    });

    it('uploads a selected image and optional caption as multipart form data', async () => {
        mountPanel();
        await flushPromises();
        const photo = new File(['pixels'], 'pantry.png', { type: 'image/png' });
        const input = wrapper!.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { configurable: true, value: [photo] });
        await input.trigger('change');
        await wrapper!.get('#image-upload-caption-7').setValue('Spare box');
        await wrapper!.findAll('form').at(-1)!.trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledTimes(1);
        const [url, form] = vi.mocked(http.post).mock.calls[0] as [string, FormData, unknown];
        expect(url).toBe('/items/7/images');
        expect(form.get('image')).toBe(photo);
        expect(form.get('data[caption]')).toBe('Spare box');
        expect(vi.mocked(http.post).mock.calls[0]).toHaveLength(2);
    });

    it('refreshes the original item when navigation happens during a primary-photo change', async () => {
        let finishUpdate: ((result: unknown) => void) | undefined;
        vi.mocked(http.patch).mockImplementation(() => new Promise((resolve) => { finishUpdate = resolve; }) as never);
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        mountPanel();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Make primary')!.trigger('click');
        await flushPromises();
        await wrapper!.setProps({ itemId: '8' });
        await flushPromises();
        await wrapper!.setProps({ itemId: '7' });
        await flushPromises();

        finishUpdate!({ data: { data: primary } });
        await flushPromises();

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['item-images', '7'] });
        expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ['item-images', '8'] });
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['items'] });
    });

    it('does not show an old item upload failure after navigation', async () => {
        let rejectUpload: ((cause: unknown) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((_resolve, reject) => { rejectUpload = reject; }) as never);
        mountPanel();
        await flushPromises();
        const photo = new File(['pixels'], 'pantry.png', { type: 'image/png' });
        const input = wrapper!.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { configurable: true, value: [photo] });
        await input.trigger('change');
        await wrapper!.findAll('form').at(-1)!.trigger('submit');
        await flushPromises();
        await wrapper!.setProps({ itemId: '8' });
        await flushPromises();

        rejectUpload!(new Error('Item seven upload failed'));
        await flushPromises();

        expect(wrapper!.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper!.find('#image-upload-8').exists()).toBe(true);
    });

    it('does not clear the next item photo selection when an old upload succeeds', async () => {
        let finishUpload: ((result: unknown) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => { finishUpload = resolve; }) as never);
        mountPanel();
        await flushPromises();
        const originalPhoto = new File(['original'], 'original.png', { type: 'image/png' });
        const originalInput = wrapper!.get('#image-upload-7');
        Object.defineProperty(originalInput.element, 'files', { configurable: true, value: [originalPhoto] });
        await originalInput.trigger('change');
        await wrapper!.findAll('form').at(-1)!.trigger('submit');
        await flushPromises();
        await wrapper!.setProps({ itemId: '8' });
        await flushPromises();

        const nextPhoto = new File(['next'], 'next.png', { type: 'image/png' });
        const nextInput = wrapper!.get('#image-upload-8');
        (nextInput.element as HTMLInputElement).disabled = false;
        Object.defineProperty(nextInput.element, 'files', { configurable: true, value: [nextPhoto] });
        await nextInput.trigger('change');
        const nextCaption = wrapper!.get('#image-upload-caption-8');
        (nextCaption.element as HTMLInputElement).disabled = false;
        await nextCaption.setValue('Photo for item eight');

        finishUpload!({ data: { data: { ...primary, id: 'image-3' } } });
        await flushPromises();

        expect((wrapper!.get('#image-upload-caption-8').element as HTMLInputElement).value).toBe('Photo for item eight');
        expect((wrapper!.findAll('form').at(-1)!.get('button[type="submit"]').element as HTMLButtonElement).disabled).toBe(false);
    });

    it('does not reopen an old caption editor when its update fails on the next item', async () => {
        let rejectUpdate: ((cause: unknown) => void) | undefined;
        vi.mocked(http.patch).mockImplementation(() => new Promise((_resolve, reject) => { rejectUpdate = reject; }) as never);
        mountPanel();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Edit caption')!.trigger('click');
        await wrapper!.get('#image-caption-image-1').setValue('Changed on item seven');
        await wrapper!.findAll('form')[0].trigger('submit');
        await flushPromises();
        await wrapper!.setProps({ itemId: '8' });
        await flushPromises();

        rejectUpdate!(new Error('Item seven caption update failed'));
        await flushPromises();

        expect(wrapper!.find('#image-caption-image-1').exists()).toBe(false);
        expect(wrapper!.find('[role="alert"]').exists()).toBe(false);
    });
});
