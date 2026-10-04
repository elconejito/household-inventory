import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import ItemEditor from './ItemEditor.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));

const item = { id: '7', name: 'Soap', counting_unit: 'bottle', description: 'Under sink\nleft side', categories: [{ id: '2', name: 'Cleaning' }] };

describe('item editor', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: [{ id: '2', name: 'Cleaning' }, { id: '1', name: 'Household' }], meta: { last_page: 1 } } } as never);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    function mountEditor(props: { item: typeof item | null; errors?: { fields: Record<string, string>; form: string }; saving?: boolean }, slots?: Record<string, string>) {
        wrapper = mount(ItemEditor, { props, slots, global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient }]] } });
    }

    it('preserves a same-item draft when refreshed props arrive', async () => {
        mountEditor({ item });
        await flushPromises();
        await wrapper!.get('#item-name').setValue('My draft');

        await wrapper!.setProps({ item: { ...item, name: 'Server refreshed name' } });
        await flushPromises();

        expect((wrapper!.get('#item-name').element as HTMLInputElement).value).toBe('My draft');
        expect((wrapper!.get('#item-description').element as HTMLTextAreaElement).value).toBe('Under sink\nleft side');
    });

    it('shows category assignment validation errors accessibly', async () => {
        mountEditor({ item, errors: { fields: { 'category_ids/0': 'Choose an active category.' }, form: '' } });
        await flushPromises();

        expect(wrapper!.get('fieldset').attributes('aria-invalid')).toBe('true');
        expect(wrapper!.get('fieldset').attributes('aria-describedby')).toBe('item-categories-error');
        expect(wrapper!.get('#item-categories-error').text()).toBe('Choose an active category.');
    });

    it('keeps editing disabled until category assignments can be checked and retries the options query', async () => {
        vi.mocked(http.get)
            .mockRejectedValueOnce(new Error('Network unavailable'))
            .mockResolvedValueOnce({ data: { data: [{ id: '2', name: 'Cleaning' }], meta: { last_page: 1 } } } as never);
        mountEditor({ item });
        await flushPromises();

        expect(wrapper!.text()).toContain('Assignments are unchanged until the list is available.');
        expect(wrapper!.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper!.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(wrapper!.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
        expect(http.get).toHaveBeenCalledTimes(2);
    });

    it('preserves description line breaks and normalizes selected category IDs', async () => {
        mountEditor({ item: null });
        await flushPromises();
        await wrapper!.get('#item-name').setValue('Dish soap');
        await wrapper!.get('#item-counting-unit').setValue('bottle');
        await wrapper!.get('#item-description').setValue('Under sink\nleft side');
        await wrapper!.get('input[type="checkbox"][value="2"]').setValue(true);
        await wrapper!.get('input[type="checkbox"][value="1"]').setValue(true);
        await wrapper!.get('form').trigger('submit');

        expect(wrapper!.emitted('submit')?.[0]?.[0]).toEqual({
            name: 'Dish soap',
            counting_unit: 'bottle',
            description: 'Under sink\nleft side',
            category_ids: ['1', '2'],
        });
        expect(wrapper!.emitted('submit')?.[0]).toHaveLength(3);
        expect(wrapper!.emitted('submit')?.[0]?.[2]).toBe('detail');
    });

    it('emits stock intent only from its submit button and defaults Enter submissions to detail', async () => {
        mountEditor({ item: null });
        await flushPromises();
        const form = wrapper!.get('form').element as HTMLFormElement;
        const stockButton = wrapper!.findAll('button[type="submit"]').find((button) => button.text() === 'Save and add stock')!.element as HTMLButtonElement;

        form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: stockButton }));
        form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));

        expect(wrapper!.emitted('submit')?.map((event) => event[2])).toEqual(['stock', 'detail']);
    });

    it('keeps the create-fields slot create-only and disables editable fields and cancel while saving', async () => {
        mountEditor({ item: null, saving: true }, { 'create-fields': '<label>Photo<input type="file" /></label>' });
        await flushPromises();

        expect(wrapper!.find('input[type="file"]:disabled').exists()).toBe(true);
        expect(wrapper!.get('#item-name').attributes('disabled')).toBeDefined();
        expect(wrapper!.get('#item-counting-unit').attributes('disabled')).toBeDefined();
        expect(wrapper!.get('#item-description').attributes('disabled')).toBeDefined();
        expect(wrapper!.find('input[type="checkbox"]:disabled').exists()).toBe(true);
        expect(wrapper!.findAll('button').find((button) => button.text() === 'Cancel')!.attributes('disabled')).toBeDefined();
        const form = wrapper!.get('form').element as HTMLFormElement;
        form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
        expect(wrapper!.emitted('submit')).toBeUndefined();

        await wrapper!.setProps({ item });
        expect(wrapper!.find('[type="file"]').exists()).toBe(false);
    });
});
