import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import InventoryPage from './InventoryPage.vue';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
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
        };
        const refreshedCollection = {
            ...emptyCollection,
            data: [createdItem],
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 10, to: 1, total: 1 },
        };
        vi.mocked(http.get)
            .mockResolvedValueOnce({ data: emptyCollection })
            .mockResolvedValue({ data: refreshedCollection });
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
            },
        });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['items'] });
        await vi.waitFor(() => expect(http.get).toHaveBeenCalledTimes(2));
        await flushPromises();
        expect(wrapper.text()).toContain('Dish soap was added to your inventory.');
        expect(wrapper.get('li').text()).toContain('Dish soap');
    });
});
