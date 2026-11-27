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
const { routerPush, routerReplace, routeQuery } = vi.hoisted(() => ({ routerPush: vi.fn(), routerReplace: vi.fn(), routeQuery: {} as Record<string, unknown> }));
vi.mock('vue-router', () => ({ useRoute: () => ({ query: routeQuery }), useRouter: () => ({ push: routerPush, replace: routerReplace }) }));
vi.mock('../stores/session', () => ({ useSessionStore: () => ({ user: { membership: { household: { id: 'household-1' } } } }) }));

describe('inventory page', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        for (const key of Object.keys(routeQuery)) delete routeQuery[key];
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
        window.sessionStorage.removeItem('inventory-recently-viewed:household-1');
        window.sessionStorage.removeItem('inventory-recently-viewed:household-2');
        queryClient.clear();
    });

    async function clickButton(label: string): Promise<void> {
        const button = wrapper!.findAll('button').find((candidate) => candidate.text() === label || candidate.attributes('aria-label') === label);
        expect(button, `button labeled ${label}`).toBeDefined();
        await button!.trigger('click');
    }

    async function applyFilters(values: Array<[string, string]>): Promise<void> {
        await wrapper!.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').trigger('click');
        for (const [id, value] of values) {
            await wrapper!.get(`#${id}`).setValue(value);
        }
        await wrapper!.findAll('button').find((button) => button.text() === 'Apply filters')!.trigger('click');
    }

    async function openFilters(): Promise<void> {
        await wrapper!.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').trigger('click');
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
            if (url === '/categories') return { data: { data: [{ id: '4', name: 'Cleaning' }], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [{ id: '9', name: 'Pantry', parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
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
        expect(wrapper.get('dialog[open][aria-label="Add item"]').find('#create-item-panel').exists()).toBe(true);
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
        await wrapper.findAll('button').find((button) => button.text() === 'Open item')!.trigger('click');
        await flushPromises();
        expect(routerPush).toHaveBeenCalledWith({ name: 'inventory-item', params: { item: '31' }, query: {} });
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

        await applyFilters([
            ['item-category-filter', '4'],
            ['item-location-filter', '9'],
            ['item-stock-filter', 'in_stock'],
            ['item-attention-filter', 'none'],
            ['item-sort', '-name'],
        ]);
        await flushPromises();
        const perPageSelect = wrapper.findAll('select').find((select) => select.element.closest('label')?.textContent?.includes('Per page'));
        expect(perPageSelect).toBeDefined();
        await perPageSelect!.setValue('25');
        await flushPromises();
        await openFilters();

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

    it('restores browse filters and pagination from the item route query', async () => {
        Object.assign(routeQuery, {
            search: 'paper',
            category: '4',
            location: '9',
            stockStatus: 'in_stock',
            attentionStatus: 'buy_soon',
            sort: '-name',
            page: '3',
            perPage: '25',
        });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 3, last_page: 4, total: 90 } } } as never;
            if (url === '/categories') return { data: { data: [{ id: '4', name: 'Cleaning' }], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [{ id: '9', name: 'Pantry', parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });

        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        expect((wrapper.get('#item-search').element as HTMLInputElement).value).toBe('paper');
        await wrapper.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').trigger('click');
        expect((wrapper.get('#item-category-filter').element as HTMLSelectElement).value).toBe('4');
        expect((wrapper.get('#item-location-filter').element as HTMLSelectElement).value).toBe('9');
        expect(wrapper.text()).toContain('Page 3 of 4');
        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({
            'filter[search]': 'paper',
            'filter[category_id]': '4',
            'filter[location_id]': '9',
            'filter[stock_status]': 'in_stock',
            'filter[attention_status]': 'buy_soon',
            sort: '-name',
            page: 3,
            per_page: 25,
        }) });
    });

    it('discards filter edits when the filter dialog is cancelled', async () => {
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/categories') return { data: { data: [{ id: '4', name: 'Cleaning' }], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        await wrapper.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').trigger('click');
        await wrapper.get('#item-category-filter').setValue('4');
        await wrapper.findAll('button').find((button) => button.text() === 'Cancel')!.trigger('click');
        await flushPromises();

        expect(vi.mocked(http.get).mock.calls.filter(([url]) => url === '/items')).toHaveLength(1);
        expect(wrapper.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').text()).toBe('Filters');
    });

    it('keeps recently viewed items scoped to the signed-in household and paginates the list', async () => {
        const householdItems = Array.from({ length: 11 }, (_, index) => ({
            id: `item-${index}`,
            name: `Household item ${index}`,
            category: 'Paper Goods',
        }));
        window.sessionStorage.setItem('inventory-recently-viewed:household-1', JSON.stringify(householdItems));
        window.sessionStorage.setItem('inventory-recently-viewed:household-2', JSON.stringify([{ id: 'secret', name: 'Other household item', category: '' }]));
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });

        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        const recentItems = wrapper.get('aside[aria-labelledby="recently-viewed-title"]');
        expect(recentItems.text()).toContain('Household item 0');
        expect(recentItems.text()).not.toContain('Other household item');
        expect(recentItems.find('nav[aria-label="Recently viewed pagination"]').exists()).toBe(true);
        const recentPageSize = recentItems.findAll('select').find((select) => select.element.closest('label')?.textContent?.includes('Per page'));
        expect(recentPageSize).toBeDefined();
        expect(recentItems.text()).toContain('Page 1 of 2');

        await recentPageSize!.setValue('100');
        expect(recentItems.find('nav[aria-label="Recently viewed pagination"]').exists()).toBe(true);
        expect(recentItems.findAll('select').find((select) => select.element.closest('label')?.textContent?.includes('Per page'))!.element).toHaveProperty('value', '100');
        expect(recentItems.text()).toContain('Page 1 of 1');

        await recentItems.findAll('select').find((select) => select.element.closest('label')?.textContent?.includes('Per page'))!.setValue('10');
        expect(recentItems.findAll('select').find((select) => select.element.closest('label')?.textContent?.includes('Per page'))!.element).toHaveProperty('value', '10');
        expect(recentItems.text()).toContain('Page 1 of 2');
    });

    it('supports numbered pages and direct page entry with bounded navigation', async () => {
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: (config?.params as { page?: number } | undefined)?.page ?? 1, last_page: 20, total: 200 } } } as never;
            return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
        });
        wrapper = mount(InventoryPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        expect(wrapper.get('nav[aria-label="Inventory pagination"]').text()).toContain('…');
        await wrapper.get('button[aria-label="Page 20"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('button[aria-label="Page 20"]').attributes('aria-current')).toBe('page');
        expect((wrapper.get('#inventory-page-number').element as HTMLInputElement).value).toBe('20');

        await wrapper.get('#inventory-page-number').setValue('10');
        await wrapper.get('nav form').trigger('submit');
        await flushPromises();
        expect(http.get).toHaveBeenLastCalledWith('/items', expect.objectContaining({ params: expect.objectContaining({ page: 10 }) }));
        expect(wrapper.get('button[aria-label="Page 10"]').attributes('aria-current')).toBe('page');

        const requests = vi.mocked(http.get).mock.calls.length;
        for (const invalidPage of ['0', '21', '1.5', '']) {
            await wrapper.get('#inventory-page-number').setValue(invalidPage);
            await wrapper.get('nav form').trigger('submit');
            await flushPromises();
        }
        expect(http.get).toHaveBeenCalledTimes(requests);
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
        await applyFilters([['item-stock-filter', 'empty']]);
        await flushPromises();
        const itemRequests = vi.mocked(http.get).mock.calls.filter(([url]) => url === '/items');
        expect(itemRequests.at(-1)?.[1]).toEqual(expect.objectContaining({ params: expect.objectContaining({ page: 1, 'filter[stock_status]': 'empty' }) }));

        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/categories') return { data: { data: [], meta: { last_page: 1 } } } as never;
            if (url === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        await applyFilters([['item-attention-filter', 'low']]);
        await flushPromises();

        expect(wrapper.text()).toContain('No items match these filters');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Add your first item')).toBe(false);
        expect(wrapper.findAll('button').some((button) => button.text() === 'Clear filters')).toBe(true);
        await clickButton('Clear filters');
        await flushPromises();
        await openFilters();
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
        await applyFilters([
            ['item-location-filter', '9'],
            ['item-stock-filter', 'in_stock'],
            ['item-attention-filter', 'empty'],
        ]);
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
        expect(row.text()).not.toContain('Counted by');
        expect(wrapper.text()).not.toContain('Your items');
        expect(wrapper.get('button[aria-haspopup="dialog"]:not([aria-controls="create-item-panel"])').text()).toBe('Filters');
        expect(wrapper.text()).toContain('1 item · Page 1 of 1');
        expect(wrapper.findAll('button').find((button) => button.attributes('aria-label') === 'Previous')!.attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('button').find((button) => button.attributes('aria-label') === 'Next')!.attributes('disabled')).toBeDefined();
        expect(row.classes()).toContain('sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.75fr)]');
        expect(wrapper.text()).toContain('Household alerts');
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
        expect(rows[1].find('img').exists()).toBe(false);
        expect(rows[1].find('.ui-icon').exists()).toBe(true);
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
