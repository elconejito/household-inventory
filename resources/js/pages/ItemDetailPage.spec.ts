import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { AxiosError } from 'axios';
import ItemDetailPage from './ItemDetailPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { item: '7' } }) }));

const item = {
    id: '7', name: 'Batteries', counting_unit: 'pack', counting_unit_plural: 'packs', description: null, total_quantity: 5,
    inventory_levels: [
        { type: 'inventory-levels', id: '9', quantity: 0, alert_threshold: 1, stock_status: 'out', alert_status: 'below_threshold', location: { id: '2', name: 'Pantry', description: null, parent: null } },
        { type: 'inventory-levels', id: '10', quantity: 5, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '3', name: 'Closet', description: null, parent: null } },
    ],
};

describe('item stock detail', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            return { data: { data: [{ id: '2', name: 'Pantry', description: null, parent: null }, { id: '3', name: 'Closet', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    function mountPage(): void {
        wrapper = mount(ItemDetailPage, { global: { plugins: [[VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
    }

    it('omits one-tap consumption at zero stock and offers transfer in from positive locations', async () => {
        mountPage();
        await flushPromises();

        const pantry = wrapper!.findAll('li').find((row) => row.text().includes('Pantry'))!;
        expect(pantry.text()).not.toContain('Use 1');
        await pantry.findAll('button').find((button) => button.text() === 'Move in')!.trigger('click');
        expect(wrapper!.find('#transfer-location').exists()).toBe(true);
        expect(wrapper!.get('#transfer-location').text()).toContain('Closet (5 available)');
    });

    it('submits a confirmed correction with observed quantity and displays API validation errors', async () => {
        const validationError = new AxiosError('Validation failed');
        Object.defineProperty(validationError, 'response', { value: { data: { errors: [{ source: { pointer: '/data/observed_quantity' }, detail: 'Use a whole number.' }] } } });
        vi.mocked(http.post).mockRejectedValueOnce(validationError);
        mountPage();
        await flushPromises();
        const pantry = wrapper!.findAll('li').find((row) => row.text().includes('Pantry'))!;
        await pantry.findAll('button').find((button) => button.text() === 'Correct')!.trigger('click');
        await wrapper!.get('#observed-quantity').setValue('4');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'correction', item_id: '7', location_id: '2', observed_quantity: 4 } });
        expect(wrapper!.get('[role="alert"]').text()).toContain('Use a whole number.');
    });

    it('restocks directly at a location without an existing stock level', async () => {
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '11' } } } as never);
        mountPage();
        await flushPromises();
        await wrapper!.get('button').trigger('click');
        await wrapper!.get('#new-level-location').setValue('3');
        await wrapper!.get('#new-level-quantity').setValue('6');
        expect(wrapper!.find('[role="status"]').exists()).toBe(false);
        expect(wrapper!.text()).toContain('Restock 6 packs at Closet.');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledTimes(1);
        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'restock', item_id: '7', location_id: '3', quantity: 6 } });
    });

    it('updates a changed threshold without asking for movement confirmation', async () => {
        mountPage();
        await flushPromises();
        const pantry = wrapper!.findAll('li').find((row) => row.text().includes('Pantry'))!;
        await pantry.findAll('button').find((button) => button.text() === 'Threshold')!.trigger('click');
        await wrapper!.get('#stock-threshold').setValue('2');
        expect(wrapper!.find('input[type="checkbox"]').exists()).toBe(false);
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(http.patch).toHaveBeenCalledWith('/inventory-levels/9', { data: { alert_threshold: 2 } });
    });

    it('records Use 1 immediately without opening a confirmation form', async () => {
        mountPage();
        await flushPromises();
        const closet = wrapper!.findAll('li').find((row) => row.text().includes('Closet'))!;
        await closet.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'consumption', item_id: '7', location_id: '3', quantity: 1 } });
        expect(wrapper!.find('form').exists()).toBe(false);
        expect(wrapper!.get('[role="status"]').text()).toContain('Used 1 pack from Closet.');
    });

    it('submits a confirmed transfer in from a positive location with the correct direction and limit', async () => {
        mountPage();
        await flushPromises();
        const pantry = wrapper!.findAll('li').find((row) => row.text().includes('Pantry'))!;
        await pantry.findAll('button').find((button) => button.text() === 'Move in')!.trigger('click');
        await wrapper!.get('#transfer-location').setValue('3');
        await wrapper!.get('#stock-quantity').setValue('2');

        expect(wrapper!.get('#stock-quantity').attributes('max')).toBe('5');
        expect(wrapper!.text()).toContain('Move 2 packs from Closet to Pantry.');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', {
            data: {
                movement_type: 'transfer',
                item_id: '7',
                source_location_id: '3',
                destination_location_id: '2',
                quantity: 2,
            },
        });
    });

    it('does not switch actions or submit another movement while Use 1 is pending', async () => {
        let finishMovement: ((response: { data: { data: { id: string } } }) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => {
            finishMovement = resolve;
        }) as never);
        mountPage();
        await flushPromises();
        const closet = wrapper!.findAll('li').find((row) => row.text().includes('Closet'))!;
        const useOneButton = closet.findAll('button').find((button) => button.text() === 'Use 1')!;
        await useOneButton.trigger('click');
        await flushPromises();

        expect(useOneButton.attributes('disabled')).toBeDefined();
        const pantry = wrapper!.findAll('li').find((row) => row.text().includes('Pantry'))!;
        const restockButton = pantry.findAll('button').find((button) => button.text() === 'Restock')!;
        expect(restockButton.attributes('disabled')).toBeDefined();
        await restockButton.trigger('click');

        expect(wrapper!.find('form').exists()).toBe(false);
        expect(http.post).toHaveBeenCalledTimes(1);

        finishMovement?.({ data: { data: { id: '22' } } });
        await flushPromises();
    });
});
