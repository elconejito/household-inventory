import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import type { CatalogInventoryLevel } from '../api/catalog';
import ContextDetailPage from './ContextDetailPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
const route = vi.hoisted(() => ({ name: 'category-detail' as string, params: { category: '12', location: '21' } }));
vi.mock('vue-router', async (importOriginal) => {
    const vue = await import('vue');
    const reactiveRoute = vue.reactive(route);
    return { ...(await importOriginal<typeof import('vue-router')>()), useRoute: () => reactiveRoute, setMockLocation: (id: string) => { reactiveRoute.params.location = id; }, setMockCategory: (id: string) => { reactiveRoute.params.category = id; } };
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
            if (String(url).startsWith('/categories/')) {
                const id = String(url).split('/').at(-1) ?? '12';
                return { data: { data: { id, name: id === '12' ? 'Pantry supplies' : 'Seasonal supplies' } } } as never;
            }
            if (String(url) === '/locations') return { data: { data: [
                { id: '20', name: 'House', description: null, parent: null },
                { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
                { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
            ], meta: { current_page: 1, last_page: 1, total: 3 } } } as never;
            if (String(url) === '/inventory-levels') return { data: { data: [{
                id: 'level-1', quantity: 6, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored',
                location: { id: route.params.location, name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
                item: { id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null },
            }, {
                id: 'level-2', quantity: 2, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored',
                location: { id: route.params.location, name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
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
        expect(wrapper!.get('nav[aria-label="Breadcrumb"] a').attributes('data-to')).toBe(JSON.stringify({ name: 'catalog-categories' }));
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

    it('adds and removes one item assignment through the targeted pivot endpoint', async () => {
        vi.mocked(http.patch).mockResolvedValue({ data: { data: null } } as never);
        mountPage();
        await flushPromises();

        await wrapper!.get('#category-item-to-add').setValue('item-1');
        await wrapper!.findAll('button').find((button) => button.text() === 'Add to category')!.trigger('click');
        await flushPromises();

        expect(http.patch).toHaveBeenCalledWith('/categories/12/items/item-1', { data: { assigned: true } });
        const itemRow = wrapper!.findAll('ul[aria-label="Items in this category"] > li').find((row) => row.text().includes('Hose'))!;
        await itemRow.findAll('button').find((button) => button.text() === 'Remove from category')!.trigger('click');
        await flushPromises();

        expect(http.patch).toHaveBeenLastCalledWith('/categories/12/items/item-1', { data: { assigned: false } });
        expect(vi.mocked(http.patch).mock.calls.every(([, payload]) => !JSON.stringify(payload).includes('category_ids'))).toBe(true);
    });

    it('keeps the selected item after assignment errors so the user can retry', async () => {
        vi.mocked(http.patch)
            .mockRejectedValueOnce(new Error('Offline'))
            .mockResolvedValueOnce({ data: { data: null } } as never);
        mountPage();
        await flushPromises();
        await wrapper!.get('#category-item-to-add').setValue('item-1');
        await wrapper!.findAll('button').find((button) => button.text() === 'Add to category')!.trigger('click');
        await flushPromises();

        expect(wrapper!.get('[role="alert"]').text()).toContain('Something went wrong. Please try again.');
        expect((wrapper!.get('#category-item-to-add').element as HTMLSelectElement).value).toBe('item-1');
        await wrapper!.findAll('button').find((button) => button.text() === 'Add to category')!.trigger('click');
        await flushPromises();

        expect(vi.mocked(http.patch)).toHaveBeenCalledTimes(2);
        expect(wrapper!.text()).toContain('Hose was added to this category.');
    });

    it('clamps to the new last page after removing the only item there', async () => {
        let assignmentExists = false;
        vi.mocked(http.patch).mockImplementation(async (_url, payload) => {
            assignmentExists = Boolean((payload as { data?: { assigned?: boolean } }).data?.assigned);
            return { data: { data: null } } as never;
        });
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            const params = config?.params as Record<string, unknown> | undefined;
            if (String(url) === '/items' && params?.['filter[category_id]']) {
                const page = Number(params.page);
                if (page === 2 && assignmentExists) return { data: { data: [{ id: 'item-1', name: 'Hose', counting_unit: 'hose', description: null }], meta: { current_page: 2, last_page: 2, total: 11 } } } as never;
                return { data: { data: [], meta: { current_page: page, last_page: assignmentExists ? 2 : 1, total: assignmentExists ? 11 : 10 } } } as never;
            }
            if (String(url) === '/items') return { data: { data: [{ id: 'item-1', name: 'Hose', counting_unit: 'hose', description: null, categories: [] }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/categories/')) return { data: { data: { id: '12', name: 'Pantry supplies' } } } as never;
            return { data: { data: [] } } as never;
        });
        mountPage();
        await flushPromises();

        await wrapper!.get('#category-item-to-add').setValue('item-1');
        await wrapper!.findAll('button').find((button) => button.text() === 'Add to category')!.trigger('click');
        await flushPromises();
        expect(wrapper!.text()).toContain('Page 1 of 2');
        const nextPage = wrapper!.findAll('button').find((button) => button.text() === 'Next')!;
        await nextPage.trigger('click');
        await flushPromises();
        expect(wrapper!.text()).toContain('Page 2 of 2');

        const categoryPageOneRequests = () => vi.mocked(http.get).mock.calls.filter(([url, config]) => {
            const params = config?.params as Record<string, unknown> | undefined;
            return String(url) === '/items' && params?.['filter[category_id]'] === '12' && params.page === 1;
        }).length;
        const pageOneRequestsBeforeRemoval = categoryPageOneRequests();
        await wrapper!.findAll('button').find((button) => button.text() === 'Remove from category')!.trigger('click');
        await flushPromises();

        expect(wrapper!.text()).not.toContain('Page 2 of 1');
        expect(categoryPageOneRequests()).toBeGreaterThan(pageOneRequestsBeforeRemoval);
        expect(wrapper!.find('ul[aria-label="Items in this category"]').exists()).toBe(false);
        expect(wrapper!.text()).toContain('No items in this category yet');
    });

    it('does not show completion feedback if a category assignment finishes after navigating away and back', async () => {
        let finishAssignment: ((response: { data: { data: null } }) => void) | undefined;
        vi.mocked(http.patch).mockImplementation(() => new Promise((resolve) => { finishAssignment = resolve; }) as never);
        mountPage();
        await flushPromises();
        const itemRow = wrapper!.findAll('ul[aria-label="Items in this category"] > li').find((row) => row.text().includes('Hose'))!;

        await itemRow.findAll('button').find((button) => button.text() === 'Remove from category')!.trigger('click');
        expect(itemRow.findAll('button').find((button) => button.text() === 'Removing…')!.attributes('disabled')).toBeDefined();
        await itemRow.findAll('button').find((button) => button.text() === 'Removing…')!.trigger('click');
        expect(vi.mocked(http.patch)).toHaveBeenCalledTimes(1);
        const router = await import('vue-router') as unknown as { setMockCategory: (id: string) => void };
        router.setMockCategory('13');
        await flushPromises();
        router.setMockCategory('12');
        await flushPromises();
        finishAssignment?.({ data: { data: null } });
        await flushPromises();

        expect(wrapper!.text()).not.toContain('Hose was removed from this category.');
        expect(wrapper!.findAll('[role="alert"]').some((alert) => alert.text().includes('could not be saved'))).toBe(false);
    });

    it('omits the redundant inventory scope selector at a leaf location', async () => {
        route.name = 'location-detail';
        (await import('vue-router') as unknown as { setMockLocation: (id: string) => void }).setMockLocation('22');
        mountPage();
        await flushPromises();

        expect(wrapper!.find('#inventory-scope').exists()).toBe(false);
        expect(http.get).toHaveBeenCalledWith('/inventory-levels', { params: { 'filter[location_id]': '22', include: 'item.active_alerts,location', page: 1, per_page: 100 } });
    });

    it('separates an unmonitored empty location from its item-wide Buy soon flag', async () => {
        route.name = 'location-detail';
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url !== '/inventory-levels') return originalGet(url, config);
            return { data: { data: [{ id: 'level-empty', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored',
                location: { id: '21', name: 'Kitchen', description: null },
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

    it('links all ancestors of a nested location back to the locations list', async () => {
        route.name = 'location-detail';
        route.params.location = '24';
        const locations = [
            { id: '20', name: 'House with a particularly long descriptive name', description: null, parent: null },
            { id: '21', name: 'First floor', description: null, parent: { id: '20', name: 'House', description: null } },
            { id: '23', name: 'Kitchen', description: null, parent: { id: '21', name: 'First floor', description: null } },
            { id: '24', name: 'Cupboard', description: null, parent: { id: '23', name: 'Kitchen', description: null } },
        ];
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/locations') return { data: { data: locations, meta: { current_page: 1, last_page: 1, total: 4 } } } as never;
            if (url === '/locations/24') return { data: { data: locations[3] } } as never;
            return originalGet(url, config);
        });
        mountPage();
        await flushPromises();

        const links = wrapper!.get('nav[aria-label="Breadcrumb"]').findAll('a');
        expect(links.map((link) => link.text())).toEqual(['← All locations', locations[0].name, 'First floor', 'Kitchen']);
        expect(links.map((link) => JSON.parse(link.attributes('data-to')!))).toEqual([
            { name: 'catalog-locations' },
            { name: 'location-detail', params: { location: '20' } },
            { name: 'location-detail', params: { location: '21' } },
            { name: 'location-detail', params: { location: '23' } },
        ]);
        expect(links[1].attributes('title')).toBe(locations[0].name);
        expect(wrapper!.get('h1').text()).toBe('Cupboard');
    });

    it('shows the location hierarchy and requests notes for that location', async () => {
        route.name = 'location-detail';
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/locations/21', { params: { include: 'parent' } });
        expect(wrapper!.get('h1').text()).toBe('Kitchen');
        expect(wrapper!.get('nav[aria-label="Breadcrumb"]').text()).toContain('House');
        expect(http.get).toHaveBeenCalledWith('/locations/21/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
        expect(http.get).toHaveBeenCalledWith('/inventory-levels', { params: { 'filter[location_id]': '21', include: 'item.active_alerts,location', page: 1, per_page: 100 } });
        expect(wrapper!.text()).toContain('6 hoses');
        const itemRow = wrapper!.findAll('li').find((row) => row.text().includes('Hose'))!;
        expect(itemRow.findAll('button').some((button) => button.text() === 'Restock')).toBe(true);
        expect(wrapper!.find('#item-to-restock').exists()).toBe(false);
        expect(wrapper!.text()).not.toContain('Restock here');
        expect(wrapper!.get('[aria-label="Record actions"]').find('archive-resource-button-stub').exists()).toBe(true);

    });

    it('records a one-unit use against an item stored directly in the viewed location', async () => {
        route.name = 'location-detail';
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'movement-1' } } } as never);
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: 'item-1', location_id: '21', quantity: 1 } });
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

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: 'item-2', location_id: '21', quantity: 1 } });
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

    it('combines child locations and direct items while excluding descendant stock', async () => {
        route.name = 'location-detail';
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            const response = await originalGet(url, config);
            if (url !== '/inventory-levels') return response;
            return { data: { data: [
                ...(response as { data: { data: CatalogInventoryLevel[] } }).data.data,
                { id: 'descendant-stock', quantity: 4, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '22', name: 'Bin', description: null }, item: { id: 'hidden', name: 'Stored inside Bin', counting_unit: 'box', description: null } },
            ], meta: { current_page: 1, last_page: 1, total: 3 } } } as never;
        });
        mountPage();
        await flushPromises();

        const list = wrapper!.get('ul[aria-label="Location contents"]');
        expect(list.findAll(':scope > li')).toHaveLength(3);
        expect(list.text()).toContain('BinChild location');
        expect(list.text()).toContain('HoseItem · 6 hoses');
        expect(list.text()).toContain('ScrewsItem · 2 boxes');
        expect(list.text()).not.toContain('Stored inside Bin');
        expect(wrapper!.text()).toContain('3 entries · Page 1 of 1');
        expect(wrapper!.find('#inventory-scope').exists()).toBe(false);
    });

    it('paginates and searches one combined collection across all direct-stock API pages', async () => {
        route.name = 'location-detail';
        const originalGet = vi.mocked(http.get).getMockImplementation()!;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url !== '/inventory-levels') return originalGet(url, config);
            const page = Number((config?.params as { page?: number } | undefined)?.page ?? 1);
            const ids = page === 1 ? Array.from({ length: 10 }, (_, index) => index + 1) : [11];
            return { data: { data: ids.map((id) => ({ id: `level-${id}`, quantity: id, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '21', name: 'Kitchen', description: null }, item: { id: String(id), name: `Direct ${id}`, counting_unit: 'box', description: null } })), meta: { current_page: page, last_page: 2, total: 11 } } } as never;
        });
        mountPage();
        await flushPromises();

        expect(wrapper!.get('ul[aria-label="Location contents"]').findAll(':scope > li')).toHaveLength(10);
        expect(wrapper!.text()).toContain('12 entries · Page 1 of 2');
        await wrapper!.get('section[aria-labelledby="location-contents-heading"] button[aria-label="Page 2"]').trigger('click');
        expect(wrapper!.get('ul[aria-label="Location contents"]').findAll(':scope > li')).toHaveLength(2);
        expect(http.get).toHaveBeenCalledWith('/inventory-levels', { params: { 'filter[location_id]': '21', include: 'item.active_alerts,location', page: 2, per_page: 100 } });
        await wrapper!.get('#location-contents-search').setValue('Direct 11');
        expect(wrapper!.text()).toContain('1 entry · Page 1 of 1');
        expect(wrapper!.get('ul[aria-label="Location contents"]').text()).toContain('Direct 11');
    });

    it('clears the stock action when the contents search changes', async () => {
        route.name = 'location-detail';
        mountPage();
        await flushPromises();
        const row = wrapper!.findAll('li').find((entry) => entry.text().includes('Hose'))!;
        await row.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(true);

        await wrapper!.get('#location-contents-search').setValue('Screws');
        await flushPromises();

        expect(wrapper!.findAll('form').some((form) => form.text().includes('Restock'))).toBe(false);
    });
});
