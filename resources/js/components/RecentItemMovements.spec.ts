import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import RecentItemMovements from './RecentItemMovements.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const movements = Array.from({ length: 10 }, (_, index) => ({
    id: `movement-${index + 1}`, movement_type: 'restock', recorded_at: `2026-05-${String(10 - index).padStart(2, '0')}T12:00:00Z`,
    item: { id: '7', name: 'Batteries', counting_unit: 'pack', counting_unit_plural: 'packs', description: null },
    entries: [{ id: `entry-${index + 1}`, location: { id: '3', name: 'Closet', description: null, parent: null }, quantity_delta: 1, balance_after: index + 1 }],
    recorded_by: null,
}));

describe('RecentItemMovements', () => {
    it('requests ten item records and presents only the five newest with the all activity link', async () => {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: movements, meta: { current_page: 1, last_page: 3, total: 21 } } } as never);
        const wrapper = mount(RecentItemMovements, { props: { itemId: '7' }, global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { props: ['to'], template: '<a :data-route="JSON.stringify(to)"><slot /></a>' } } } });
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/inventory-movements', { params: { include: 'item,entries.location,recorded_by', per_page: 10, page: 1, 'filter[item_id]': '7' } });
        expect(wrapper.findAll('article')).toHaveLength(5);
        expect(wrapper.text()).toContain('1 after');
        expect(wrapper.text()).toContain('5 after');
        expect(wrapper.text()).not.toContain('6 after');
        expect(wrapper.get('a').attributes('data-route')).toContain('"name":"activity"');
        expect(wrapper.get('a').attributes('data-route')).toContain('"item_id":"7"');
        wrapper.unmount();
        queryClient.clear();
    });

    it('does not request movement data when the item identity is empty', async () => {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        const wrapper = mount(RecentItemMovements, { props: { itemId: '' }, global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        expect(http.get).not.toHaveBeenCalled();
        wrapper.unmount();
        queryClient.clear();
    });

    it('shows loading and empty states, then retries a failed request successfully', async () => {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get)
            .mockRejectedValueOnce(new Error('Network unavailable'))
            .mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never);
        const wrapper = mount(RecentItemMovements, { props: { itemId: '7' }, global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
        expect(wrapper.get('[role="status"]').text()).toContain('Loading recent activity');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('could not be loaded');
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('No activity recorded for this item yet.');
        expect(http.get).toHaveBeenCalledTimes(2);
        wrapper.unmount();
        queryClient.clear();
    });

    it('switches to the new item query without retaining the previous item movements', async () => {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get)
            .mockResolvedValueOnce({ data: { data: movements, meta: { current_page: 1, last_page: 1, total: 1 } } } as never)
            .mockResolvedValueOnce({ data: { data: [{ ...movements[0], id: 'new-item-movement', item: { ...movements[0].item, id: '8', name: 'Flashlight' }, entries: [{ ...movements[0].entries[0], location: { ...movements[0].entries[0].location, name: 'Garage' }, balance_after: 99 }] }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never);
        const wrapper = mount(RecentItemMovements, { props: { itemId: '7' }, global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
        await flushPromises();
        expect(wrapper.text()).toContain('1 after');

        await wrapper.setProps({ itemId: '8' });
        await flushPromises();

        expect(http.get).toHaveBeenLastCalledWith('/inventory-movements', { params: { include: 'item,entries.location,recorded_by', per_page: 10, page: 1, 'filter[item_id]': '8' } });
        expect(wrapper.text()).toContain('Garage');
        expect(wrapper.text()).toContain('99 after');
        expect(wrapper.text()).not.toContain('1 after');
        wrapper.unmount();
        queryClient.clear();
    });
});
