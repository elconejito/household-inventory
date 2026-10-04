import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import CategoryAssignmentsPanel from './CategoryAssignmentsPanel.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), patch: vi.fn() } }));

const items = [
    { id: '31', name: 'Paper towels', categories: [{ id: '8', name: 'Supplies' }] },
    { id: '32', name: 'Dish soap', categories: [{ id: '9', name: 'Cleaning' }] },
];

describe('category assignments panel', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockResolvedValue({ data: { data: items, meta: { current_page: 1, last_page: 1, total: items.length } } } as never);
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    function mountPanel(): void {
        wrapper = mount(CategoryAssignmentsPanel, { props: { categoryId: '8' }, global: { plugins: [[VueQueryPlugin, { queryClient }]] } });
    }

    it('shows assigned items as unavailable and emits assignment for an available selection', async () => {
        mountPanel();
        await flushPromises();

        const paperOption = wrapper!.find('option[value="31"]');
        expect(paperOption.attributes('disabled')).toBeDefined();
        await wrapper!.get('#category-item-to-add').setValue('32');
        await wrapper!.get('button').trigger('click');

        expect(wrapper!.emitted('assign')).toEqual([['32', true, 'Dish soap']]);
    });

    it('cannot assign a selected item after search filters it out of the visible results', async () => {
        mountPanel();
        await flushPromises();
        await wrapper!.get('#category-item-to-add').setValue('32');
        await wrapper!.get('#category-item-search').setValue('paper');

        expect(wrapper!.text()).toContain('Paper towels');
        expect(wrapper!.text()).not.toContain('Dish soap');
        expect(wrapper!.find('option[value="32"]').exists()).toBe(false);
        expect(wrapper!.get('button').attributes('disabled')).toBeDefined();
        await wrapper!.get('button').trigger('click');

        expect(wrapper!.emitted('assign')).toBeUndefined();
    });

    it('reports search results that are empty', async () => {
        mountPanel();
        await flushPromises();
        await wrapper!.get('#category-item-search').setValue('not in inventory');

        expect(wrapper!.get('[role="status"]').text()).toBe('No items match that search.');
        expect(wrapper!.get('button').attributes('disabled')).toBeDefined();
    });

    it('lets the user retry when active item options fail to load', async () => {
        vi.mocked(http.get)
            .mockRejectedValueOnce(new Error('Offline'))
            .mockResolvedValueOnce({ data: { data: items, meta: { current_page: 1, last_page: 1, total: items.length } } } as never);
        mountPanel();
        await flushPromises();
        expect(wrapper!.get('[role="alert"]').text()).toContain('Inventory items could not be loaded.');

        await wrapper!.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(wrapper!.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper!.findAll('option[value="32"]')).toHaveLength(1);
    });
});
