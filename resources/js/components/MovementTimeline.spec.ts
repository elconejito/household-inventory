import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import MovementTimeline from './MovementTimeline.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const movement = {
    id: 'move-1', movement_type: 'restock', recorded_at: '2026-05-07T14:30:00Z',
    item: { id: '7', name: `UnbrokenItemName${'x'.repeat(180)} <script>alert(1)</script>`, counting_unit: 'pack', counting_unit_plural: 'packs', description: null },
    entries: [{ id: 'entry-1', location: { id: '3', name: `UnbrokenLocationName${'y'.repeat(180)} <img src=x onerror=alert(1)>`, description: null, parent: null }, quantity_delta: 2, balance_after: 5 }],
    recorded_by: { id: 'user-1', name: '<b>Recorder</b>' },
};

describe('MovementTimeline', () => {
    it('renders escaped movement details and does not request notes until expanded', async () => {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never);
        const wrapper = mount(MovementTimeline, { props: { movements: [movement] }, global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient }]] } });
        await flushPromises();

        expect(wrapper.get('article').text()).toContain('+2 packs');
        expect(wrapper.get('article').text()).toContain('5 after');
        const expectedTimestamp = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(movement.recorded_at));
        expect(wrapper.get('time').attributes('datetime')).toBe(movement.recorded_at);
        expect(wrapper.get('time').text()).toBe(expectedTimestamp);
        expect(wrapper.get('h3').classes()).toContain('min-w-0');
        expect(wrapper.get('h3').classes()).toContain('break-words');
        expect(wrapper.html()).not.toContain('<script>');
        expect(wrapper.html()).not.toContain('<img');
        expect(http.get).not.toHaveBeenCalled();

        const details = wrapper.get('details');
        (details.element as HTMLDetailsElement).open = true;
        await details.trigger('toggle');
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/inventory-movements/move-1/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
        wrapper.unmount();
        queryClient.clear();
    });
});
