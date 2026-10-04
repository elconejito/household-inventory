import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CatalogInventoryLevel } from '../api/catalog';
import { http } from '../lib/http';
import LocationStockActions from './LocationStockActions.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

const locations = [
    { id: '20', name: 'House', description: null, parent: null },
    { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
    { id: '22', name: 'Bin', description: null, parent: { id: '21', name: 'Kitchen', description: null, parent: { id: '20', name: 'House', description: null, parent: null } } },
    { id: '30', name: 'Closet', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
    { id: '31', name: 'Garage', description: null, parent: { id: '20', name: 'House', description: null, parent: null } },
];
const level: CatalogInventoryLevel = {
    id: 'level-bin', quantity: 6, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: locations[2],
    item: { id: 'item-1', name: 'Hose', counting_unit: 'hose', counting_unit_plural: 'hoses', description: null },
};
const itemDetail = {
    ...level.item,
    inventory_levels: [
        { id: 'level-bin', quantity: 6, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: locations[2] },
        { id: 'level-closet', quantity: 3, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: locations[3] },
        { id: 'level-garage', quantity: 5, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: locations[4] },
        { id: 'level-empty', quantity: 0, alert_threshold: null, stock_status: 'out', alert_status: 'unmonitored', location: locations[0] },
    ],
};

describe('LocationStockActions', () => {
    beforeEach(() => vi.clearAllMocks());

    function mountActions() {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        const wrapper = mount(LocationStockActions, {
            props: { level, locations, activeAction: null, busy: false, errors: { fields: {}, form: '' }, locationsLoading: false, locationsError: false },
            global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient }]] },
        });
        return { wrapper, queryClient };
    }

    it('consumes and restocks at the stock row exact location', async () => {
        const { wrapper, queryClient } = mountActions();
        await wrapper.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        expect(wrapper.emitted('submit')?.[0]).toEqual(['level-bin', { movement_type: 'consumption', item_id: 'item-1', location_id: '22', quantity: 1 }]);

        await wrapper.findAll('button').find((button) => button.text() === 'Restock')!.trigger('click');
        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'restock' } });
        await wrapper.get('#stock-action-quantity-level-bin').setValue('4');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[1]).toEqual(['level-bin', { movement_type: 'restock', item_id: 'item-1', location_id: '22', quantity: 4 }]);
        wrapper.unmount();
        queryClient.clear();
    });

    it('offers active household destinations except the source and confirms move-out direction', async () => {
        const { wrapper, queryClient } = mountActions();
        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'move-out' } });

        const destination = wrapper.get('#move-out-destination-level-bin');
        expect(destination.findAll('option').map((option) => option.text())).toEqual(['Select a destination', 'House', 'House / Kitchen', 'House / Closet', 'House / Garage']);
        expect(destination.findAll('option').map((option) => option.element.value)).not.toContain('22');
        await destination.setValue('30');
        await wrapper.get('#stock-action-quantity-level-bin').setValue('2');
        expect(wrapper.text()).toContain('Move 2 hoses from House / Kitchen / Bin to House / Closet.');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[0]).toEqual(['level-bin', { movement_type: 'transfer', item_id: 'item-1', source_location_id: '22', destination_location_id: '30', quantity: 2 }]);
        wrapper.unmount();
        queryClient.clear();
    });

    it('loads full item stock only for move-in and transfers from a positive source into the exact row location', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: itemDetail } } as never);
        const { wrapper, queryClient } = mountActions();
        expect(http.get).not.toHaveBeenCalled();

        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'move-in' } });
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/items/item-1', { params: { include: 'categories,inventory_levels.location' } });
        const source = wrapper.get('#move-in-source-level-bin');
        expect(source.findAll('option').map((option) => option.text())).toEqual(['Select a source', 'House / Closet (3 hoses available)', 'House / Garage (5 hoses available)']);
        expect(source.findAll('option').map((option) => option.element.value)).not.toContain('22');
        await source.setValue('30');
        await wrapper.get('#stock-action-quantity-level-bin').setValue('4');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        expect((wrapper.get('button[type="submit"]').element as HTMLButtonElement).disabled).toBe(true);
        await wrapper.get('#stock-action-quantity-level-bin').setValue('3');
        await flushPromises();
        expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);
        expect(wrapper.text()).toContain('Move 3 hoses from House / Closet to House / Kitchen / Bin.');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[0]).toEqual(['level-bin', { movement_type: 'transfer', item_id: 'item-1', source_location_id: '30', destination_location_id: '22', quantity: 3 }]);
        wrapper.unmount();
        queryClient.clear();
    });

    it('requires a valid quantity and resets confirmation when transfer details change', async () => {
        const { wrapper, queryClient } = mountActions();
        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'move-out' } });
        await wrapper.get('#move-out-destination-level-bin').setValue('30');
        await wrapper.get('#stock-action-quantity-level-bin').setValue('7');
        await wrapper.get('input[type="checkbox"]').setValue(true);

        expect((wrapper.get('button[type="submit"]').element as HTMLButtonElement).disabled).toBe(true);
        await wrapper.get('#stock-action-quantity-level-bin').setValue('6');
        await flushPromises();
        expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('#move-out-destination-level-bin').setValue('31');
        await flushPromises();
        expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);

        wrapper.unmount();
        queryClient.clear();
    });

    it('shows location-choice loading and errors and emits a location retry request', async () => {
        const { wrapper, queryClient } = mountActions();
        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'move-out' }, locationsLoading: true });
        expect(wrapper.get('[role="status"]').text()).toContain('Loading locations');

        await wrapper.setProps({ locationsLoading: false, locationsError: true });
        expect(wrapper.get('[role="alert"]').text()).toContain('Locations could not be loaded');
        await wrapper.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        expect(wrapper.emitted('retryLocations')).toHaveLength(1);
        wrapper.unmount();
        queryClient.clear();
    });

    it('retries loading move-in choices after item detail fails', async () => {
        vi.mocked(http.get).mockRejectedValueOnce(new Error('Offline')).mockResolvedValueOnce({ data: { data: itemDetail } } as never);
        const { wrapper, queryClient } = mountActions();
        await wrapper.setProps({ activeAction: { levelId: 'level-bin', action: 'move-in' } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Available stock could not be loaded');
        await wrapper.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(wrapper.get('#move-in-source-level-bin').findAll('option')).toHaveLength(3);
        wrapper.unmount();
        queryClient.clear();
    });

    it('explains when move-out has no destination and leaves save unavailable', async () => {
        const { wrapper, queryClient } = mountActions();
        await wrapper.setProps({ locations: [locations[2]], activeAction: { levelId: 'level-bin', action: 'move-out' } });

        expect(wrapper.text()).toContain('No other active locations are available.');
        expect(wrapper.find('#move-out-destination-level-bin').exists()).toBe(false);
        expect((wrapper.get('button[type="submit"]').element as HTMLButtonElement).disabled).toBe(true);
        wrapper.unmount();
        queryClient.clear();
    });
});
