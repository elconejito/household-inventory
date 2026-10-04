import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { InventoryItem } from '../api/items';
import ItemStockSummary from './ItemStockSummary.vue';

const locations = [
    { id: 'home', name: 'House', description: null, parent: null },
    { id: 'pantry', name: 'Pantry', description: null, parent: { id: 'home', name: 'House', description: null, parent: null } },
    { id: 'bathroom', name: 'Bathroom', description: null, parent: { id: 'home', name: 'House', description: null, parent: null } },
];

const item: InventoryItem = {
    id: 'item-1', name: 'Soap', counting_unit: 'bottle', counting_unit_plural: 'bottles', description: null, total_quantity: 4,
    inventory_levels: [
        { id: 'level-home', quantity: 1, alert_threshold: 2, stock_status: 'in_stock', alert_status: 'low', location: locations[0] },
        { id: 'level-pantry', quantity: 3, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: locations[1] },
        { id: 'level-bathroom', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored', location: locations[2] },
    ],
};

describe('ItemStockSummary', () => {
    it('shows the total and only the exact selected location quantity', () => {
        const wrapper = mount(ItemStockSummary, { props: { item, locations, exactLocationId: 'home' } });

        expect(wrapper.text()).toContain('4 bottles total on hand');
        expect(wrapper.text()).toContain('At House: 1 bottle');
        expect(wrapper.text()).not.toContain('At House: 4 bottles');
    });

    it('shows positive location paths and pluralizes each quantity', () => {
        const wrapper = mount(ItemStockSummary, { props: { item, locations } });

        expect(wrapper.text()).toContain('House — 1 bottle');
        expect(wrapper.text()).toContain('House / Pantry — 3 bottles');
        expect(wrapper.text()).not.toContain('House / Bathroom — 0 bottles');
    });

    it('shows monitored attention and keeps an unmonitored empty location neutral', () => {
        const wrapper = mount(ItemStockSummary, { props: { item, locations } });

        expect(wrapper.text()).toContain('Low stock · House');
        expect(wrapper.text()).not.toContain('Out of stock · House / Bathroom');
        expect(wrapper.text()).not.toContain('Low stock · House / Bathroom');
    });
});
