import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { computed, defineComponent, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { useItemsQuery } from './items';
import type { ItemListParams } from '../api/items';

vi.mock('../lib/http', () => ({ http: { get: vi.fn() } }));

describe('inventory item queries', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], links: {}, meta: {} } } as never);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    it('includes inventory alerts independently with each inventory item page', async () => {
        const component = defineComponent({
            setup() {
                useItemsQuery(computed(() => ({ search: '', page: 1, perPage: 10 })));
                return () => null;
            },
        });
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/items', { params: {
            page: 1,
            per_page: 10,
            sort: 'name',
            include: 'categories,images,inventory_levels.location,active_alerts',
        } });
    });

    it('uses independent cached list keys for stock and attention filters', async () => {
        const params = ref<ItemListParams>({ search: '', page: 1, perPage: 10 });
        const component = defineComponent({
            setup() {
                useItemsQuery(computed(() => params.value));
                return () => null;
            },
        });
        wrapper = mount(component, { global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();
        params.value = { ...params.value, stockStatus: 'empty', attentionStatus: 'needs_attention' };
        await flushPromises();
        params.value = { ...params.value, stockStatus: 'in_stock', attentionStatus: 'none' };
        await flushPromises();

        const listParams = queryClient.getQueryCache().findAll().map((query) => query.queryKey)
            .filter((key) => key[0] === 'items' && key[1] === 'list')
            .map((key) => key[2] as ItemListParams);
        expect(listParams.map(({ stockStatus, attentionStatus }) => `${stockStatus ?? 'all'}:${attentionStatus ?? 'all'}`).sort()).toEqual([
            'all:all',
            'empty:needs_attention',
            'in_stock:none',
        ]);
        expect(http.get).toHaveBeenCalledTimes(3);
    });
});
