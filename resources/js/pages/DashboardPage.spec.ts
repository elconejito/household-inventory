import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { http } from '../lib/http';
import DashboardPage from './DashboardPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));

const location = { id: '2', name: 'Pantry', description: null, parent: null };
const item = {
    id: '42', name: 'Tea', counting_unit: 'box', counting_unit_plural: 'boxes', description: null, total_quantity: 4,
    inventory_levels: [
        { id: '90', quantity: 4, alert_threshold: 2, alert_status: 'above_threshold', location },
        { id: '91', quantity: 0, alert_threshold: 0, alert_status: 'empty', location: { id: '3', name: 'Closet', description: null, parent: null } },
    ],
};
let activeAlerts: Array<{ id: string; alert_type: string; created_at: string; resolved_at: string | null; item: typeof item }>;

describe('dashboard finder and attention', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        activeAlerts = [];
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 0 }, mutations: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            const params = config?.params as Record<string, string> | undefined;
            const collection = (data: unknown[], total = data.length) => {
                const page = Number(params?.page ?? 1);
                const perPage = Number(params?.per_page ?? 10);
                return {
                    data: data.slice((page - 1) * perPage, page * perPage),
                    meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), total },
                };
            };
            const value = url === '/items'
                ? collection(params?.['filter[search]'] ? [item] : [])
                : url === '/locations' ? collection([location, { id: '3', name: 'Closet', description: null, parent: null }], 2)
                    : url === '/inventory-levels' && params?.['filter[alert_status]'] === 'empty' ? collection([{ ...item.inventory_levels[1], item, stock_status: 'out' }])
                        : url === '/inventory-levels' && params?.['filter[alert_status]'] === 'low' ? collection([{ ...item.inventory_levels[0], quantity: 1, item }])
                            : url === '/inventory-alerts' ? collection(activeAlerts.filter((alert) => !params?.['filter[item_id]'] || alert.item.id === params['filter[item_id]']), activeAlerts.length)
                                : collection([]);
            return { data: value } as never;
        });
        vi.mocked(http.post).mockImplementation(async (url) => {
            if (String(url).endsWith('/resolve')) {
                const alertId = String(url).split('/').at(-2);
                activeAlerts = activeAlerts.filter((alert) => alert.id !== alertId);
            }
            return { data: { data: { id: '101' } } } as never;
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
        vi.useRealTimers();
    });

    function mountPage(): void {
        wrapper = mount(DashboardPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } } });
    }

    it('loads the inventory count without fetching full results until a nonempty search is entered', async () => {
        vi.useFakeTimers();
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({ per_page: 10 }) });
        expect(http.get).not.toHaveBeenCalledWith('/items', { params: expect.objectContaining({ 'filter[search]': 'Tea' }) });

        await wrapper!.get('#dashboard-search').setValue('Tea');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({ 'filter[search]': 'Tea', per_page: 10 }) });
        expect(http.get).toHaveBeenCalledWith('/items', { params: expect.objectContaining({ include: 'inventory_levels.location' }) });
        expect(wrapper!.text()).toContain('Tea');
    });

    it('offers consume only from positive locations and records Buy soon creation', async () => {
        vi.useFakeTimers();
        mountPage();
        await wrapper!.get('#dashboard-search').setValue('Tea');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        await wrapper!.findAll('button').find((button) => button.text() === 'Consume 1')!.trigger('click');
        await flushPromises();
        expect(wrapper!.get('#dashboard-location').text()).toContain('Pantry (4 available)');
        expect(wrapper!.get('#dashboard-location').text()).not.toContain('Closet');
        await wrapper!.get('#dashboard-location').setValue('2');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('section[aria-labelledby="quick-action-title"] form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: '42', location_id: '2', quantity: 1 } });

        await wrapper!.findAll('button').find((button) => button.text() === 'Buy soon')!.trigger('click');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/inventory-alerts', { data: { item_id: '42', alert_type: 'buy_soon' } });

        await wrapper!.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        await wrapper!.get('#dashboard-location').setValue('3');
        await wrapper!.get('#dashboard-quantity').setValue('2');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('section[aria-labelledby="quick-action-title"] form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'restock', item_id: '42', location_id: '3', quantity: 2 } });
        expect(http.post).not.toHaveBeenCalledWith('/inventory-alerts/101/resolve');
    });

    it('shows concise alert groups with scoped links instead of dashboard widget pagers', async () => {
        mountPage();
        await flushPromises();

        expect(wrapper!.get('#empty-heading').text()).toBe('Out of stock');
        expect(wrapper!.get('#low-heading').text()).toBe('Low stock');
        expect(wrapper!.get('#buysoon-heading').text()).toBe('Buy soon');
        expect(wrapper!.text()).toContain('Closet · 0 box');
        expect(wrapper!.text()).toContain('Pantry · 1 box');
        expect(wrapper!.get('[aria-labelledby="needs-attention-title"]').findAll('button[aria-label="Next"]')).toHaveLength(0);
        expect(wrapper!.findAll('a').some((link) => link.attributes('data-to')?.includes('"attentionStatus":"empty"'))).toBe(true);
        expect(wrapper!.findAll('a').some((link) => link.attributes('data-to')?.includes('"attentionStatus":"low"'))).toBe(true);
        expect(wrapper!.findAll('a').some((link) => link.attributes('data-to')?.includes('"attentionStatus":"buy_soon"'))).toBe(true);
        expect(wrapper!.get('.dashboard-workflows').attributes('open')).toBeUndefined();
    });

    it('shows the API field error when a selected source no longer has enough stock', async () => {
        const error = new AxiosError('Validation failed');
        Object.defineProperty(error, 'response', {
            value: { data: { errors: [{ source: { pointer: '/data/quantity' }, detail: 'There is not enough stock at this location.' }] } },
        });
        vi.mocked(http.post).mockRejectedValueOnce(error);
        vi.useFakeTimers();
        mountPage();
        await wrapper!.get('#dashboard-search').setValue('Tea');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Consume 1')!.trigger('click');
        await wrapper!.get('#dashboard-location').setValue('2');
        await wrapper!.get('input[type="checkbox"]').setValue(true);

        await wrapper!.get('section[aria-labelledby="quick-action-title"] form').trigger('submit');
        await flushPromises();

        expect(wrapper!.text()).toContain('There is not enough stock at this location.');
        expect(wrapper!.find('form').exists()).toBe(true);
        expect(http.post).toHaveBeenCalledTimes(1);
    });

    it('shows alert-load failures instead of treating them as empty sections', async () => {
        vi.mocked(http.get).mockRejectedValue(new Error('Network unavailable'));
        mountPage();
        await flushPromises();

        expect(wrapper!.text()).toContain('Out-of-stock alerts could not be loaded.');
        expect(wrapper!.text()).toContain('Low-stock alerts could not be loaded.');
        expect(wrapper!.text()).toContain('Buy-soon reminders could not be loaded.');
        expect(wrapper!.text()).not.toContain('Nothing is out of stock.');
        expect(wrapper!.text()).not.toContain('No low stock alerts.');
        expect(wrapper!.text()).not.toContain('Nothing marked to buy soon.');
    });

    it('shows active status and resolves a manual alert without a request body', async () => {
        activeAlerts = [{ id: '501', alert_type: 'buy_soon', created_at: '2026-10-03T12:00:00Z', resolved_at: null, item }];
        vi.useFakeTimers();
        mountPage();
        await wrapper!.get('#dashboard-search').setValue('Tea');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(wrapper!.text()).toContain('Tea');
        await wrapper!.findAll('button').find((button) => button.text() === 'Resolve')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-alerts/501/resolve');
    });

    it('keeps alert summaries short and links the full set into scoped inventory', async () => {
        activeAlerts = Array.from({ length: 11 }, (_, index) => ({
            id: `alert-${index}`,
            alert_type: 'buy_soon',
            created_at: '2026-10-03T12:00:00Z',
            resolved_at: null,
            item: { ...item, id: `${index + 100}`, name: `Alert ${index}` },
        }));
        mountPage();
        await flushPromises();

        const buySoonSection = wrapper!.get('[aria-labelledby="buysoon-heading"]');
        expect(buySoonSection.text()).toContain('Alert 0');
        expect(buySoonSection.text()).toContain('Alert 1');
        expect(buySoonSection.text()).not.toContain('Alert 10');
        expect(buySoonSection.find('a').attributes('data-to')).toContain('"attentionStatus":"buy_soon"');
        expect(buySoonSection.findAll('button[aria-label="Next"]')).toHaveLength(0);
    });
});
