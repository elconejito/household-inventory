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
const { routerPush } = vi.hoisted(() => ({ routerPush: vi.fn() }));
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: routerPush }) }));

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

    async function clickButton(label: string): Promise<void> {
        const button = wrapper!.findAll('button').find((candidate) => candidate.text() === label);
        expect(button, `button labeled ${label}`).toBeDefined();
        await button!.trigger('click');
    }

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
        expect(routerPush).toHaveBeenCalledWith({ name: 'inventory-item', params: { item: '31' } });
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
        await wrapper.get('#item-stock-filter').setValue('in_stock');
        await wrapper.get('#item-attention-filter').setValue('none');
        await wrapper.get('#item-sort').setValue('-name');
        await wrapper.get('#item-page-size').setValue('25');
        await flushPromises();

        expect(wrapper.text()).toContain('Stock and attention filters evaluate the whole item across every active location.');
        expect(wrapper.text()).toContain('The Location filter limits the list to items tracked there');
        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({
            'filter[category_id]': '4',
            'filter[location_id]': '9',
            'filter[stock_status]': 'in_stock',
            'filter[attention_status]': 'none',
            sort: '-name',
            per_page: 25,
            include: 'categories,images,inventory_levels.location,active_alerts',
        }) });
    });

    it('resets pagination and explains empty stock-filtered results with clear filters', async () => {
        const item = { id: '12', name: 'Paper towels', counting_unit: 'roll', description: null, categories: [], images: [], inventory_levels: [], total_quantity: 0 };
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/items') {
                const params = config?.params as { page?: number } | undefined;
                return { data: { data: [item], links: {}, meta: { current_page: params?.page ?? 1, last_page: 3, total: 25 } } } as never;
            }
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();
        await clickButton('Next');
        await flushPromises();
        await wrapper.get('#item-stock-filter').setValue('empty');
        await flushPromises();
        const itemRequests = vi.mocked(http.get).mock.calls.filter(([url]) => url === '/items');
        expect(itemRequests.at(-1)?.[1]).toEqual(expect.objectContaining({ params: expect.objectContaining({ page: 1, 'filter[stock_status]': 'empty' }) }));

        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        await wrapper.get('#item-attention-filter').setValue('low');
        await flushPromises();

        expect(wrapper.text()).toContain('No items match these filters');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Add your first item')).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'Clear filters')).toBe(true);
        await clickButton('Clear filters');
        await flushPromises();
        expect((wrapper.get('#item-stock-filter').element as HTMLSelectElement).value).toBe('');
        expect((wrapper.get('#item-attention-filter').element as HTMLSelectElement).value).toBe('');
    });

    it('keeps the all-location item total when an exact location is selected with stock filters', async () => {
        const item = {
            id: '12', name: 'Paper towels', counting_unit: 'roll', counting_unit_plural: 'rolls', description: null, categories: [], images: [], total_quantity: 4,
            inventory_levels: [
                { id: 'level-1', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored', location: { id: '9', name: 'Pantry', description: null } },
                { id: 'level-2', quantity: 4, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '10', name: 'Closet', description: null } },
            ],
        };
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [item], links: {}, meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [{ id: '9', name: 'Pantry', description: null }, { id: '10', name: 'Closet', description: null }], meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();
        await wrapper.get('#item-location-filter').setValue('9');
        await wrapper.get('#item-stock-filter').setValue('in_stock');
        await wrapper.get('#item-attention-filter').setValue('empty');
        await flushPromises();

        const row = wrapper.get('li article');
        expect(row.text()).toContain('4 rolls total on hand');
        expect(row.text()).toContain('At Pantry: 0 rolls');
        expect(http.get).toHaveBeenCalledWith('/items', expect.objectContaining({ params: expect.objectContaining({
            'filter[location_id]': '9',
            'filter[stock_status]': 'in_stock',
            'filter[attention_status]': 'empty',
        }) }));
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

    it('keeps rows aligned when only some items on a page have primary images', async () => {
        const items = [
            { id: '21', name: 'Item with photo', counting_unit: 'box', description: null, categories: [], images: [{ id: 'image-21', is_primary: true, thumbnail_url: '/photo.jpg', caption: null }], inventory_levels: [], total_quantity: 0 },
            { id: '22', name: 'Item without photo', counting_unit: 'box', description: null, categories: [], images: [], inventory_levels: [], total_quantity: 0 },
        ];
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: items, links: {}, meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });

        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        const rows = wrapper.findAll('li article');
        expect(rows).toHaveLength(2);
        expect(rows[0].classes()).toEqual(rows[1].classes());
        expect(rows[0].find('img').exists()).toBe(true);
        expect(rows[1].find('img').exists()).toBe(false);
        const thumbnailSpacer = rows[1].find('[aria-hidden="true"].size-14');
        expect(thumbnailSpacer.exists()).toBe(true);
        expect(thumbnailSpacer.classes()).toEqual(expect.arrayContaining(['hidden', 'sm:block']));
    });

    it('shows monitored stock attention and Buy soon without promoting unmonitored empty locations', async () => {
        const itemWithIndicators = {
            id: '31', name: 'Batteries', counting_unit: 'pack', description: null, categories: [], images: [], total_quantity: 2,
            active_alerts: [{ type: 'inventory-alerts', id: 'alert-1', alert_type: 'buy_soon', created_at: '2026-10-04T12:00:00Z', resolved_at: null }],
            inventory_levels: [
                { id: 'level-1', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored', location: { id: '1', name: 'Bathroom', description: null } },
                { id: 'level-2', quantity: 1, alert_threshold: 2, stock_status: 'in_stock', alert_status: 'low', location: { id: '2', name: 'Pantry', description: null } },
                { id: 'level-3', quantity: 0, alert_threshold: 0, stock_status: 'empty', alert_status: 'empty', location: { id: '3', name: 'Closet', description: null } },
            ],
        };
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [itemWithIndicators], links: {}, meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });

        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        const row = wrapper.get('li article');
        expect(row.text()).toContain('Low stock · Pantry');
        expect(row.text()).toContain('Out of stock · Closet');
        expect(row.text()).toContain('Buy soon');
        expect(row.text()).not.toContain('Bathroom');
    });
});
