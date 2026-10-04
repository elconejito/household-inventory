import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import { http } from '../lib/http';
import ItemCreationPanel from './ItemCreationPanel.vue';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    },
}));

const savedItem = { id: '31', name: 'Dish soap', counting_unit: 'bottle', description: null, categories: [], images: [] };

describe('item creation panel', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;
    let router: ReturnType<typeof createRouter>;

    beforeEach(async () => {
        vi.clearAllMocks();
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        router = createRouter({
            history: createMemoryHistory(),
            routes: [
                { path: '/', name: 'inventory', component: RouterView },
                { path: '/items/:item', name: 'inventory-item', component: RouterView },
            ],
        });
        await router.push('/');
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (String(url).endsWith('/images')) return { data: { data: [], meta: { last_page: 1 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: savedItem } } as never;
            if (url === '/items/31/images') return { data: { data: { id: 'photo-31' } } } as never;
            throw new Error(`Unexpected POST ${String(url)}`);
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    async function mountPanel() {
        wrapper = mount(ItemCreationPanel, { global: { plugins: [[VueQueryPlugin, { queryClient }], router] } });
        await flushPromises();
        await wrapper.get('#item-name').setValue('Dish soap');
        await wrapper.get('#item-counting-unit').setValue('bottle');
        return wrapper;
    }

    async function choosePhoto(file: File) {
        const input = wrapper!.get('#create-item-photo');
        Object.defineProperty(input.element, 'files', { configurable: true, value: [file] });
        await input.trigger('change');
    }

    async function submitWithIntent(value: 'detail' | 'stock') {
        const button = wrapper!.get(`button[value="${value}"]`);
        await wrapper!.get('form').trigger('submit', { submitter: button.element });
    }

    async function clickButton(label: string) {
        const button = wrapper!.findAll('button').find((candidate) => candidate.text() === label);
        expect(button, `button labeled ${label}`).toBeDefined();
        await button!.trigger('click');
    }

    it('creates the item once, uploads the selected photo, then opens the requested stock flow', async () => {
        await mountPanel();
        await choosePhoto(new File(['image'], 'soap.webp', { type: 'image/webp' }));

        await submitWithIntent('stock');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/items', { data: {
            name: 'Dish soap', counting_unit: 'bottle', description: null, category_ids: [],
        } });
        expect(http.post).toHaveBeenCalledTimes(2);
        const upload = vi.mocked(http.post).mock.calls.find(([url]) => url === '/items/31/images');
        expect(upload?.[1]).toBeInstanceOf(FormData);
        expect((upload?.[1] as FormData).get('image')).toBeInstanceOf(File);
        expect(router.currentRoute.value.fullPath).toBe('/items/31?restock=1');
    });

    it('keeps a failed photo recovery card and retry never posts the item again', async () => {
        await mountPanel();
        await choosePhoto(new File(['image'], 'soap.png', { type: 'image/png' }));
        vi.mocked(http.post).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: savedItem } } as never;
            if (url === '/items/31/images') throw new Error('upload unavailable');
            throw new Error(`Unexpected POST ${String(url)}`);
        });

        await submitWithIntent('detail');
        await flushPromises();
        expect(wrapper!.get('#saved-created-item-title').text()).toBe('Dish soap');
        expect(wrapper!.get('h2').text()).toBe('Dish soap');
        expect(wrapper!.text()).toContain('Choose a replacement photo');
        expect(wrapper!.text()).toContain('photo could not be uploaded');
        expect(http.get).not.toHaveBeenCalledWith('/items/31/images', expect.anything());

        vi.mocked(http.post).mockImplementation(async (url) => {
            if (url === '/items/31/images') return { data: { data: { id: 'photo-31' } } } as never;
            if (url === '/items') return { data: { data: savedItem } } as never;
            throw new Error(`Unexpected POST ${String(url)}`);
        });
        await clickButton('Retry photo upload');
        await flushPromises();

        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items/31/images')).toHaveLength(2);
        expect(router.currentRoute.value.fullPath).toBe('/items/31');
    });

    it('preserves the draft when item creation fails and does not attempt photo upload', async () => {
        await mountPanel();
        await choosePhoto(new File(['image'], 'soap.jpg', { type: 'image/jpeg' }));
        vi.mocked(http.post).mockRejectedValueOnce(Object.assign(new Error('failed'), {
            isAxiosError: true,
            response: { data: { errors: [{ detail: 'The item could not be saved.' }] } },
        }));

        await submitWithIntent('detail');
        await flushPromises();

        expect(wrapper!.find('#create-item-panel').exists()).toBe(true);
        expect(wrapper!.get('#item-name').element).toHaveProperty('value', 'Dish soap');
        expect(wrapper!.text()).toContain('The item could not be saved.');
        expect(vi.mocked(http.post).mock.calls.some(([url]) => url === '/items/31/images')).toBe(false);
    });

    it('keeps the submit lock and saved item when the route changes during creation', async () => {
        await mountPanel();
        let resolveCreate!: (value: unknown) => void;
        vi.mocked(http.post).mockImplementation((url) => {
            if (url === '/items') return new Promise((resolve) => { resolveCreate = resolve; }) as never;
            throw new Error(`Unexpected POST ${String(url)}`);
        });

        await submitWithIntent('stock');
        await flushPromises();
        await router.replace({ query: { source: 'test' } });
        await wrapper!.get('form').trigger('submit');
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);

        resolveCreate({ data: { data: savedItem } });
        await flushPromises();

        expect(wrapper!.get('#saved-created-item-title').text()).toBe('Dish soap');
        expect(wrapper!.text()).toContain('Open the saved item to continue.');
        expect(router.currentRoute.value.fullPath).toBe('/?source=test');
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
    });

    it('rejects unsupported and oversized photos while offering an explicit removal choice', async () => {
        await mountPanel();
        await choosePhoto(new File(['text'], 'notes.txt', { type: 'text/plain' }));
        expect(wrapper!.text()).toContain('Choose a JPEG, PNG, or WebP image.');
        expect(wrapper!.findAll('button').some((button) => button.text() === 'Remove photo')).toBe(true);

        await choosePhoto(new File([new Uint8Array(20 * 1024 * 1024 + 1)], 'large.jpg', { type: 'image/jpeg' }));
        expect(wrapper!.text()).toContain('Choose an image no larger than 20 MB.');
        await clickButton('Remove photo');
        expect(wrapper!.text()).not.toContain('large.jpg');
        expect(wrapper!.findAll('button').some((button) => button.text() === 'Remove photo')).toBe(false);
        await submitWithIntent('detail');
        await flushPromises();
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
        expect(vi.mocked(http.post).mock.calls.some(([url]) => url === '/items/31/images')).toBe(false);
    });

    it('does not navigate after a pending create resolves following unmount', async () => {
        await mountPanel();
        let resolveCreate!: (value: unknown) => void;
        vi.mocked(http.post).mockImplementation((url) => {
            if (url === '/items') return new Promise((resolve) => { resolveCreate = resolve; }) as never;
            throw new Error(`Unexpected POST ${String(url)}`);
        });

        await submitWithIntent('detail');
        await flushPromises();
        wrapper!.unmount();
        resolveCreate({ data: { data: savedItem } });
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/');
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
    });

    it('keeps the saved item recoverable when navigation fails', async () => {
        await mountPanel();
        const push = vi.spyOn(router, 'push').mockResolvedValue({} as never);

        await submitWithIntent('detail');
        await flushPromises();
        expect(wrapper!.text()).toContain('The item was saved, but its detail page could not be opened.');
        expect(wrapper!.findAll('button').some((button) => button.text() === 'Retry opening item')).toBe(true);

        push.mockRestore();
        await clickButton('Retry opening item');
        await flushPromises();
        expect(router.currentRoute.value.fullPath).toBe('/items/31');
        expect(vi.mocked(http.post).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
    });
});
