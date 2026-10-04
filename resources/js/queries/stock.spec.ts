import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { computed, defineComponent, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { useAllItemsQuery, useCreateInventoryLevelMutation, useLocationsQuery, useMovementRecordersQuery } from './stock';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn() } }));

describe('catalog picker queries', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        client.clear();
    });

    it('does not load picker data until its view enables it', async () => {
        const enabled = ref(false);
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { last_page: 1 } } });
        const component = defineComponent({
            setup() {
                useLocationsQuery(computed(() => enabled.value));
                useAllItemsQuery(computed(() => enabled.value));
                return () => null;
            },
        });
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } });
        await flushPromises();
        expect(http.get).not.toHaveBeenCalled();

        enabled.value = true;
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/locations', { params: { include: 'parent', per_page: 100, page: 1 } });
        expect(http.get).toHaveBeenCalledWith('/items', expect.objectContaining({ params: expect.objectContaining({ page: 1, per_page: 100, include: '' }) }));
    });

    it('loads item choices beyond the first page without unrelated relationships', async () => {
        vi.mocked(http.get).mockImplementation(async (_url, config) => {
            const page = Number((config?.params as { page?: number } | undefined)?.page);
            return { data: { data: [{ id: String(page), name: page === 1 ? 'Hose' : 'Filter' }], meta: { last_page: 2 } } };
        });
        const component = defineComponent({
            setup() {
                const items = useAllItemsQuery();
                return () => items.data.value?.map((item) => item.name).join(', ') ?? '';
            },
        });
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } });
        await flushPromises();

        expect(wrapper.text()).toBe('Hose, Filter');
        expect(http.get).toHaveBeenCalledTimes(2);
        expect(http.get).toHaveBeenLastCalledWith('/items', expect.objectContaining({ params: expect.objectContaining({ page: 2, per_page: 100, include: '' }) }));
    });

    it('loads all recorder options through paginated privacy-safe results', async () => {
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/inventory-movement-recorders') {
                const page = Number((config?.params as { page?: number } | undefined)?.page);
                return { data: { data: [{ type: 'users', id: String(page), name: `Person ${page}` }], meta: { last_page: 2 } } };
            }
            return { data: { data: [], meta: { last_page: 1 } } };
        });
        const component = defineComponent({
            setup() {
                const recorders = useMovementRecordersQuery();
                return () => recorders.data.value?.map((recorder) => recorder.name).join(', ') ?? '';
            },
        });
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } });
        await flushPromises();

        expect(wrapper.text()).toBe('Person 1, Person 2');
        expect(http.get).toHaveBeenCalledTimes(2);
        expect(http.get).toHaveBeenCalledWith('/inventory-movement-recorders', { params: { per_page: 100, page: 1 } });
        expect(http.get).toHaveBeenCalledWith('/inventory-movement-recorders', { params: { per_page: 100, page: 2 } });
    });

    it('refreshes item, level, location, activity, and alert data after zero-stock setup', async () => {
        let createLevelMutation: ReturnType<typeof useCreateInventoryLevelMutation> | undefined;
        const component = defineComponent({
            setup() {
                createLevelMutation = useCreateInventoryLevelMutation();
                return () => null;
            },
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'level-1', quantity: 0 } } } as never);
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } });
        const invalidateQueries = vi.spyOn(client, 'invalidateQueries');

        await createLevelMutation!.mutateAsync({ item_id: '42', location_id: '5', alert_threshold: 0 });
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-levels', { data: { item_id: '42', location_id: '5', alert_threshold: 0 } });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['items'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['inventory-levels'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['locations'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['inventory-movements'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['activity'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['inventory-alerts'] });
    });
});
