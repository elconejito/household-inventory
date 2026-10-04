import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import ContextDetailPage from './ContextDetailPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
const route = vi.hoisted(() => ({ name: 'category-detail' as string, params: { category: '12', location: '21' } }));
vi.mock('vue-router', async (importOriginal) => {
    const vue = await import('vue');
    const reactiveRoute = vue.reactive(route);
    return { ...(await importOriginal<typeof import('vue-router')>()), useRoute: () => reactiveRoute, setMockLocation: (id: string) => { reactiveRoute.params.location = id; } };
});

describe('category and location note contexts', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        route.name = 'category-detail';
        route.params.category = '12';
        route.params.location = '21';
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/categories/')) return { data: { data: { id: '12', name: 'Pantry supplies' } } } as never;
            if (String(url) === '/locations') return { data: { data: [
                { id: '20', name: 'House', description: null, parent: null },
                { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
                { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
            ], meta: { current_page: 1, last_page: 1, total: 3 } } } as never;
            if (String(url) === '/inventory-levels') return { data: { data: [{
                id: 'level-1', quantity: 6, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored',
                location: { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
                item: { id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null },
            }, {
                id: 'level-2', quantity: 2, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored',
                location: { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
                item: { id: 'item-2', name: 'Screws', counting_unit: 'box', counting_unit_plural: 'boxes', description: null },
            }], meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
            if (String(url) === '/items') return { data: { data: [{ id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (String(url) === '/items/item-1') return { data: { data: {
                id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null,
                inventory_levels: [
                    { id: 'level-1', quantity: 6, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } } },
                    { id: 'level-2', quantity: 4, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '23', name: 'Closet', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
                ],
            } } } as never;
            return { data: { data: { id: '21', name: 'Kitchen', description: 'Lower cabinets', parent: { id: '20', name: 'House', description: null, parent: null } } } } as never;
        });
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); });

    function mountPage(): void {
        wrapper = mount(ContextDetailPage, { global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient: client }]], stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }, ArchiveResourceButton: true } } });
    }

    it('loads a category detail identity and offers its notes', async () => {
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/categories/12');
        expect(wrapper!.get('h1').text()).toBe('Pantry supplies');
        expect(http.get).toHaveBeenCalledWith('/categories/12/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
        expect(http.get).toHaveBeenCalledWith('/items', { params: { 'filter[category_id]': '12', include: 'categories,images,inventory_levels.location,active_alerts', page: 1, per_page: 10 } });
    });

    it('shows category item photos, totals, exact paths, and independent attention flags', async () => {
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url !== '/items') return originalGet(url, config);
            return { data: { data: [
                {
                    id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null, total_quantity: 6,
                    images: [{ id: 'photo-1', is_primary: true, thumbnail_url: '/hose.webp', caption: 'Blue connector' }],
                    active_alerts: [{ alert_type: 'buy_soon', resolved_at: null }],
                    inventory_levels: [{ id: 'level-1', quantity: 6, alert_threshold: 10, stock_status: 'in_stock', alert_status: 'low', location: { id: '22', name: 'Bin', description: null } }],
                },
                { id: 'item-2', name: 'Towels', counting_unit: 'roll', counting_unit_plural: 'rolls', description: null, total_quantity: 0,
                    images: [], active_alerts: [], inventory_levels: [{ id: 'level-2', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored', location: { id: '21', name: 'Kitchen', description: null } }],
                },
            ], meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
        });
        mountPage();
        await flushPromises();

        const rows = wrapper!.findAll('ul[aria-label="Items in this category"] > li');
        expect(rows[0].get('img').attributes('src')).toBe('/hose.webp');
        expect(rows[0].text()).toContain('6 hoses total on hand');
        expect(rows[0].text()).toContain('House / Kitchen / Bin — 6 hoses');
        expect(rows[0].text()).toContain('Low stock');
        expect(rows[0].text()).toContain('Buy soon');
        expect(rows[1].text()).toContain('0 rolls total on hand');
        expect(rows[1].text()).not.toMatch(/Low stock|Out of stock|Buy soon/);
    });

    it('omits the redundant inventory scope selector at a leaf location', async () => {
        route.name = 'location-detail';
        (await import('vue-router') as unknown as { setMockLocation: (id: string) => void }).setMockLocation('22');
        mountPage();
        await flushPromises();

        expect(wrapper!.find('#inventory-scope').exists()).toBe(false);
        expect(http.get).toHaveBeenCalledWith('/inventory-levels', { params: { 'filter[location_id]': '22', include: 'item.active_alerts,location', page: 1, per_page: 10 } });
    });

    it('separates an unmonitored empty location from its item-wide Buy soon flag', async () => {
        route.name = 'location-detail';
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url !== '/inventory-levels') return originalGet(url, config);
            return { data: { data: [{ id: 'level-empty', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored',
                location: { id: '22', name: 'Bin', description: null },
                item: { id: 'item-1', name: 'Hose', counting_unit: 'hose', description: null, active_alerts: [{ alert_type: 'buy_soon', resolved_at: null }] },
            }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
        });
        mountPage();
        await flushPromises();

        const badges = wrapper!.get('[aria-label="Stock level status"]');
        expect(badges.text()).toContain('Empty');
        expect(badges.text()).toContain('Unmonitored');
        expect(badges.text()).toContain('Buy soon');
        expect(badges.find('.bg-rose-50').exists()).toBe(false);
        expect(wrapper!.findAll('button').some((button) => button.text() === 'Use 1')).toBe(false);
    });

    it('shows the location hierarchy and requests notes for that location', async () => {
        route.name = 'location-detail';
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/locations/21', { params: { include: 'parent' } });
        expect(wrapper!.get('h1').text()).toBe('Kitchen');
        expect(wrapper!.text()).toContain('House / Kitchen');
        expect(http.get).toHaveBeenCalledWith('/locations/21/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
        expect(http.get).toHaveBeenCalledWith('/inventory-levels', { params: { 'filter[location_id]': '21,22', include: 'item.active_alerts,location', page: 1, per_page: 10 } });
        expect(wrapper!.text()).toContain('6 hoses');
        const itemRow = wrapper!.findAll('li').find((row) => row.text().includes('Hose'))!;
        expect(itemRow.findAll('button').some((button) => button.text() === 'Restock')).toBe(true);
        await wrapper!.get('#item-to-restock').setValue('item-1');
        await flushPromises();
        const restockLinks = wrapper!.findAll('a').filter((link) => link.text() === 'Restock here');
        expect(restockLinks.at(-1)?.attributes('data-to')).toContain('"restock_location":"21"');
    });

    it('records a one-unit use against the row location instead of the viewed parent location', async () => {
        route.name = 'location-detail';
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'movement-1' } } } as never);
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: 'item-1', location_id: '22', quantity: 1 } });
    });

    it('closes another row panel when using one item from a different stock row', async () => {
        route.name = 'location-detail';
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'movement-2' } } } as never);
        mountPage();
        await flushPromises();
        const hoseRow = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await hoseRow.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        expect(wrapper!.get('form').text()).toContain('Restock');
        const screwsRow = wrapper!.findAll('li').find((entry) => entry.text().includes('Screws'))!;
        await screwsRow.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: 'item-2', location_id: '22', quantity: 1 } });
        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(false);
    });

    it('shows structured stock action validation errors', async () => {
        route.name = 'location-detail';
        const validationError = new AxiosError('Validation failed');
        Object.defineProperty(validationError, 'response', { value: { data: { errors: [{ source: { pointer: '/data/quantity' }, detail: 'Use a smaller quantity.' }] } } });
        vi.mocked(http.post).mockRejectedValueOnce(validationError);
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(wrapper!.get('[role="alert"]').text()).toContain('Use a smaller quantity.');
    });

    it('clears a pending stock action on location navigation and ignores its late result', async () => {
        route.name = 'location-detail';
        let finishMovement: ((response: { data: { data: { id: string } } }) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => { finishMovement = resolve; }) as never);
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        await wrapper!.get('#stock-action-quantity-level-1').setValue('2');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        const router = await import('vue-router') as unknown as { setMockLocation: (id: string) => void };
        router.setMockLocation('20');
        await flushPromises();
        finishMovement?.({ data: { data: { id: 'movement-late' } } });
        await flushPromises();

        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(false);
        expect(wrapper!.findAll('[role="status"]').some((status) => status.text() === 'Stock updated.')).toBe(false);
    });

    it('clears the inline action when the inventory scope changes', async () => {
        route.name = 'location-detail';
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(true);

        await wrapper!.get('#inventory-scope').setValue('direct');
        await flushPromises();

        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(false);
    });
});
