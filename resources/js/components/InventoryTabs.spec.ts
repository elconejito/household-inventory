import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import InventoryTabs from './InventoryTabs.vue';

const route = vi.hoisted(() => ({ name: 'inventory' as string }));
vi.mock('vue-router', () => ({ useRoute: () => route }));

describe('InventoryTabs', () => {
    it.each([
        ['inventory-item', 'Items'],
        ['location-detail', 'Locations'],
        ['category-detail', 'Categories'],
    ])('marks the %s context tab as current', (routeName, label) => {
        route.name = routeName;
        const wrapper = mount(InventoryTabs, { global: { stubs: { RouterLink: { template: '<a :aria-current="$attrs[\'aria-current\']"><slot /></a>' } } } });
        const current = wrapper.find('[aria-current="page"]');
        expect(wrapper.get('nav').attributes('aria-label')).toBe('Inventory browsing');
        expect(current.text()).toBe(label);
        wrapper.unmount();
    });
});
