import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import InventoryPage from './InventoryPage.vue';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    },
}));
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }));

describe('inventory page', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        queryClient = new QueryClient({
            defaultOptions: {
                queries: { retry: false },
                mutations: { retry: false },
            },
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    it('creates an item, invalidates the item list, and shows the new row', async () => {
        const emptyCollection = {
            data: [],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: null, last_page: 1, per_page: 10, to: null, total: 0 },
        };
        const createdItem = {
            id: '31',
            name: 'Dish soap',
            counting_unit: 'bottle',
            description: 'Keep under the sink.',
            categories: [],
            images: [{ id: 'image-31', is_primary: true, thumbnail_url: '/api/item-images/image-31/thumbnail', caption: null }],
        };
        const refreshedCollection = {
            ...emptyCollection,
            data: [createdItem],
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 },
        };
        let firstItemsPage = true;
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/items') {
                const data = firstItemsPage ? emptyCollection : refreshedCollection;
                firstItemsPage = false;
                return { data } as never;
            }
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: createdItem } });
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries');

        wrapper = mount(InventoryPage, {
            global: {
                plugins: [[VueQueryPlugin, { queryClient }]],
            },
        });

        await flushPromises();
        await wrapper.get('button[aria-controls="create-item-panel"]').trigger('click');
        await wrapper.get('#item-name').setValue('Dish soap');
        await wrapper.get('#item-counting-unit').setValue('bottle');
        await wrapper.get('#item-description').setValue('Keep under the sink.');
        await wrapper.get('#create-item-panel form').trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/items', {
            data: {
                name: 'Dish soap',
                counting_unit: 'bottle',
                description: 'Keep under the sink.',
                category_ids: [],
            },
        });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['items'] });
        await vi.waitFor(() => expect(vi.mocked(http.get).mock.calls.filter(([url]) => url === '/items')).toHaveLength(2));
        await flushPromises();
        expect(wrapper.text()).toContain('Dish soap was added to your inventory.');
        expect(wrapper.get('li').text()).toContain('Dish soap');
        expect(wrapper.get('li img').attributes('src')).toBe('/api/item-images/image-31/thumbnail');
    });

    it('applies category and location filters, sorting, and page size to the item list', async () => {
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/categories') return { data: { data: [{ id: '4', name: 'Cleaning' }], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [{ id: '9', name: 'Pantry', parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        await wrapper.get('#item-category-filter').setValue('4');
        await wrapper.get('#item-location-filter').setValue('9');
        await wrapper.get('#item-sort').setValue('-name');
        await wrapper.get('#item-page-size').setValue('25');
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({
            'filter[category_id]': '4',
            'filter[location_id]': '9',
            sort: '-name',
            per_page: 25,
            include: 'categories,images,inventory_levels.location',
        }) });
    });

    it('keeps no-photo item names in the wide first column on desktop', async () => {
        const longName = 'Long pantry item name that should remain easy to scan';
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [{ id: '12', name: longName, counting_unit: 'box', description: null, categories: [], images: [], inventory_levels: [], total_quantity: 0 }], links: {}, meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });

        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        const row = wrapper.get('li article');
        expect(row.text()).toContain(longName);
        expect(row.classes()).not.toContain('sm:grid-cols-[3.5rem_minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]');
        expect(row.classes()).toContain('sm:grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]');
        expect(wrapper.findAll('div[aria-hidden="true"]').some((element) => element.classes().includes('grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]'))).toBe(true);
    });
});
