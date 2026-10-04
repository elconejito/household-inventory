import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import ActivityPage from './ActivityPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn() } }));

describe('activity filters and URL state', () => {
    let client: QueryClient;
    let router: ReturnType<typeof createRouter>;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(async () => {
        vi.clearAllMocks();
        vi.stubEnv('TZ', 'America/New_York');
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/activity', name: 'activity', component: ActivityPage }] });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [{ id: '42', name: 'Rice' }], meta: { last_page: 1 } } };
            if (url === '/locations') return { data: { data: [{ id: 'location-1', name: 'Pantry', description: null }], meta: { last_page: 1 } } };
            if (url === '/inventory-movement-recorders') return { data: { data: [{ type: 'users', id: '8', name: 'Sam' }], meta: { last_page: 1 } } };
            return { data: { data: [{ id: 'movement-1', movement_type: 'restock', recorded_at: '2026-10-01T12:00:00Z', item: { id: 'item-1', name: 'Rice', counting_unit: 'bag', counting_unit_plural: 'bags' }, entries: [], recorded_by: null }], meta: { current_page: 1, last_page: 3, total: 30 } } };
        });
        await router.push('/activity');
        await router.isReady();
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); vi.unstubAllEnvs(); });

    function mountPage(): void {
        wrapper = mount(ActivityPage, { global: { plugins: [router, [VueQueryPlugin, { queryClient: client }]] } });
    }

    it('normalizes malformed URL values and restores valid filters from the URL', async () => {
        await router.replace({ query: { item_id: '42', recorded_by: 'script', location_mode: 'sideways', recorded_from: '2026-02-30', per_page: '9', page: '-2' } });
        mountPage();
        await flushPromises();

        expect((wrapper!.get('#activity-item').element as HTMLSelectElement).value).toBe('42');
        expect((wrapper!.get('#activity-location-mode').element as HTMLSelectElement).value).toBe('either');
        expect((wrapper!.get('#activity-from').element as HTMLInputElement).value).toBe('');
        expect((wrapper!.get('#activity-page-size').element as HTMLSelectElement).value).toBe('10');
        expect(router.currentRoute.value.query).toMatchObject({ item_id: '42' });
        const movementParams = vi.mocked(http.get).mock.calls.find(([url]) => url === '/inventory-movements')?.[1]?.params as Record<string, unknown>;
        expect(movementParams['filter[item_id]']).toBe('42');
        expect(movementParams['filter[recorded_by]']).toBeUndefined();

        await router.replace({ query: { item_id: 'not-an-id', per_page: '10' } });
        await flushPromises();
        expect((wrapper!.get('#activity-item').element as HTMLSelectElement).value).toBe('');
        const normalizedParams = vi.mocked(http.get).mock.calls.filter(([url]) => url === '/inventory-movements').at(-1)?.[1]?.params as Record<string, unknown>;
        expect(normalizedParams['filter[item_id]']).toBeUndefined();
    });

    it('pushes filter changes into browser history and restores them on back navigation', async () => {
        mountPage();
        await flushPromises();
        await wrapper!.get('#activity-item').setValue('42');
        await flushPromises();
        expect(router.currentRoute.value.query.item_id).toBe('42');
        await router.back();
        await flushPromises();

        expect((wrapper!.get('#activity-item').element as HTMLSelectElement).value).toBe('');
        await router.forward();
        await flushPromises();
        expect((wrapper!.get('#activity-item').element as HTMLSelectElement).value).toBe('42');
    });

    it('resets pagination when filters or page size change and sends local date boundaries as ISO timestamps', async () => {
        mountPage();
        await flushPromises();
        await router.push({ query: { per_page: '10', page: '2' } });
        await flushPromises();
        expect(router.currentRoute.value.query.page).toBe('2');

        await wrapper!.get('#activity-recorder').setValue('8');
        await wrapper!.get('#activity-from').setValue('2026-10-01');
        await wrapper!.get('#activity-until').setValue('2026-10-02');
        await wrapper!.get('#activity-page-size').setValue('25');
        await flushPromises();

        expect(router.currentRoute.value.query).toMatchObject({ recorded_by: '8', recorded_from: '2026-10-01', recorded_until: '2026-10-02', per_page: '25' });
        expect(router.currentRoute.value.query.page).toBeUndefined();
        const requested = vi.mocked(http.get).mock.calls.filter(([url]) => url === '/inventory-movements').at(-1)?.[1]?.params as Record<string, unknown>;
        expect(requested['filter[recorded_from]']).toBe('2026-10-01T04:00:00.000Z');
        expect(requested['filter[recorded_until]']).toBe('2026-10-03T03:59:59.999Z');
        expect(requested.per_page).toBe(25);
    });

    it('shows reversed date ranges and avoids querying them', async () => {
        await router.replace({ query: { recorded_from: '2026-10-03', recorded_until: '2026-10-01' } });
        mountPage();
        await flushPromises();

        expect(wrapper!.get('[role="alert"]').text()).toContain('start date must be on or before');
        expect(wrapper!.text()).toContain('Adjust the date range to view activity.');
        expect(vi.mocked(http.get).mock.calls.some(([url]) => url === '/inventory-movements')).toBe(false);
    });

    it('recovers from a recorder options failure when retried', async () => {
        let recorderRequests = 0;
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], meta: { last_page: 1 } } };
            if (url === '/locations') return { data: { data: [], meta: { last_page: 1 } } };
            if (url === '/inventory-movement-recorders') {
                recorderRequests++;
                if (recorderRequests === 1) throw new Error('Recorder service unavailable');
                return { data: { data: [{ type: 'users', id: '8', name: 'Sam' }], meta: { last_page: 1 } } };
            }
            return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } };
        });
        mountPage();
        await flushPromises();
        expect(wrapper!.text()).toContain('People could not be loaded.');

        await wrapper!.findAll('button').find((button) => button.text() === 'Retry')!.trigger('click');
        await flushPromises();

        expect((wrapper!.get('#activity-recorder').element as HTMLSelectElement).options[1].text).toBe('Sam');
        expect(recorderRequests).toBe(2);
    });

    it('recovers from an activity request failure when retried', async () => {
        let movementRequests = 0;
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/items') return { data: { data: [], meta: { last_page: 1 } } };
            if (url === '/locations') return { data: { data: [], meta: { last_page: 1 } } };
            if (url === '/inventory-movement-recorders') return { data: { data: [], meta: { last_page: 1 } } };
            movementRequests++;
            if (movementRequests === 1) throw new Error('Activity service unavailable');
            return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } };
        });
        mountPage();
        await flushPromises();
        expect(wrapper!.text()).toContain('Activity could not be loaded');

        await wrapper!.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(wrapper!.text()).toContain('No changes found');
        expect(movementRequests).toBe(2);
    });
});
