import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { AxiosError } from 'axios';
import ItemDetailPage from './ItemDetailPage.vue';

const { routeState } = vi.hoisted(() => ({ routeState: { params: { item: '7' }, query: {} as Record<string, string | string[]> } }));
vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
vi.mock('vue-router', async (importOriginal) => {
    const vue = await import('vue');
    const route = vue.reactive(routeState);
    return { ...(await importOriginal<typeof import('vue-router')>()), useRoute: () => route, setMockItem: (id: string) => { route.params.item = id; }, setMockQuery: (query: Record<string, string | string[]>) => { route.query = query; } };
});

const item = {
    id: '7', name: 'Batteries', counting_unit: 'pack', counting_unit_plural: 'packs', description: null, total_quantity: 5, categories: [{ id: '2', name: 'Pantry supplies' }, { id: '3', name: 'Emergency supplies' }],
    inventory_levels: [
        { type: 'inventory-levels', id: '9', quantity: 0, alert_threshold: 1, stock_status: 'empty', alert_status: 'empty', location: { id: '2', name: 'Pantry', description: null, parent: null } },
        { type: 'inventory-levels', id: '10', quantity: 5, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored', location: { id: '3', name: 'Closet', description: null, parent: null } },
    ],
};

describe('item stock detail', () => {
    let queryClient: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(async () => {
        vi.clearAllMocks();
        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('7');
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({});
        queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            return { data: { data: [{ id: '2', name: 'Pantry', description: null, parent: null }, { id: '3', name: 'Closet', description: null, parent: null }, { id: '4', name: 'Garage', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 3 } } } as never;
        });
    });

    afterEach(() => {
        wrapper?.unmount();
        wrapper = undefined;
        queryClient.clear();
    });

    function mountPage(): void {
        wrapper = mount(ItemDetailPage, { global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient }]], stubs: { RouterLink: { template: '<a><slot /></a>' }, ArchiveResourceButton: true } } });
    }

    function actionForm() {
        return wrapper!.findAll('form').find((form) => form.find('input[type="checkbox"]').exists() || form.find('#stock-threshold').exists())!;
    }

    async function openLocationSetup(): Promise<void> {
        await wrapper!.findAll('button').find((button) => button.text() === 'Set up a stock location')!.trigger('click');
        await flushPromises();
    }

    async function submitLocationSetup(): Promise<void> {
        await wrapper!.get('#setup-location-form').trigger('submit');
        await flushPromises();
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

    it('shows each location monitoring status and every category link at equal priority', async () => {
        mountPage();
        await flushPromises();

        expect(wrapper!.get('[aria-label="Item categories"]').text()).toContain('Pantry supplies');
        expect(wrapper!.get('[aria-label="Item categories"]').text()).toContain('Emergency supplies');
        expect(wrapper!.text()).toContain('Empty');
        expect(wrapper!.text()).toContain('Monitored');
        expect(wrapper!.text()).toContain('Unmonitored');
    });

    it('sets up a parent location with zero stock and a null threshold without creating a movement', async () => {
        const locations = [
            { id: '4', name: 'House', description: null, parent: null },
            { id: '5', name: 'Basement', description: null, parent: { id: '4', name: 'House', description: null } },
            { id: '6', name: 'Shelf', description: null, parent: { id: '5', name: 'Basement', description: null } },
            { id: '2', name: 'Pantry', description: null, parent: null },
            { id: '3', name: 'Closet', description: null, parent: null },
        ];
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: locations, meta: { current_page: 1, last_page: 1, total: locations.length } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'level-12', quantity: 0 } } } as never);
        mountPage();
        await flushPromises();
        await openLocationSetup();
        expect(wrapper!.get('#setup-location').text()).toContain('House / Basement');
        expect(wrapper!.get('#setup-location').text()).toContain('House / Basement / Shelf');
        await wrapper!.get('#setup-location').setValue('5');

        await submitLocationSetup();

        expect(http.post).toHaveBeenCalledTimes(1);
        expect(http.post).toHaveBeenCalledWith('/inventory-levels', { data: { item_id: '7', location_id: '5', alert_threshold: null } });
        expect(http.post).not.toHaveBeenCalledWith('/inventory-movements', expect.anything());
        expect(wrapper!.text()).toContain('Stock location set up with 0 packs. No stock was added; restock later');
    });

    it('accepts threshold zero for a nested child location', async () => {
        const locations = [
            { id: '4', name: 'House', description: null, parent: null },
            { id: '5', name: 'Basement', description: null, parent: { id: '4', name: 'House', description: null } },
            { id: '6', name: 'Shelf', description: null, parent: { id: '5', name: 'Basement', description: null } },
            { id: '2', name: 'Pantry', description: null, parent: null },
            { id: '3', name: 'Closet', description: null, parent: null },
        ];
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: locations, meta: { current_page: 1, last_page: 1, total: locations.length } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'level-13', quantity: 0 } } } as never);
        mountPage();
        await flushPromises();
        await openLocationSetup();
        await wrapper!.get('#setup-location').setValue('6');
        await wrapper!.get('#setup-threshold').setValue('0');

        await submitLocationSetup();

        expect(http.post).toHaveBeenCalledWith('/inventory-levels', { data: { item_id: '7', location_id: '6', alert_threshold: 0 } });
        expect(http.post).not.toHaveBeenCalledWith('/inventory-movements', expect.anything());
    });

    it('excludes locations already associated with the item', async () => {
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [item.inventory_levels[0].location, item.inventory_levels[1].location], meta: { current_page: 1, last_page: 1, total: 2 } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();

        expect(wrapper!.text()).toContain('Every active location already has a stock level for this item.');
        expect(wrapper!.find('#setup-location').exists()).toBe(false);
        expect(wrapper!.find('#setup-location-form button[type="submit"]').exists()).toBe(false);
    });

    it('offers the existing create-location flow when there are no active locations', async () => {
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();

        expect(wrapper!.text()).toContain('There are no active locations yet.');
        await wrapper!.findAll('button').find((button) => button.text() === 'Create a location')!.trigger('click');
        expect(wrapper!.get('#create-location-title').text()).toBe('Create a location');
    });

    it('shows location loading and then exposes eligible location choices', async () => {
        const locations = [
            { id: '5', name: 'Basement', description: null, parent: null },
        ];
        let finishLocations!: (response: unknown) => void;
        vi.mocked(http.get).mockImplementation((url) => {
            if (String(url) === '/locations') return new Promise((resolve) => { finishLocations = resolve; }) as never;
            if (String(url).includes('/images')) return Promise.resolve({ data: { data: [] } }) as never;
            if (String(url).endsWith('/notes')) return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }) as never;
            if (String(url) === '/inventory-movements') return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }) as never;
            if (String(url).startsWith('/items/')) return Promise.resolve({ data: { data: item } }) as never;
            if (String(url) === '/inventory-alerts') return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }) as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        expect(wrapper!.text()).toContain('Loading locations…');
        finishLocations({ data: { data: locations, meta: { current_page: 1, last_page: 1, total: 1 } } });
        await flushPromises();

        expect(wrapper!.get('#setup-location').text()).toContain('Basement');
    });

    it('shows a location-list error and retries loading choices without losing the setup panel', async () => {
        let locationRequests = 0;
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') {
                locationRequests++;
                if (locationRequests === 1) throw new Error('locations unavailable');
                return { data: { data: [{ id: '4', name: 'Garage', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            }
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        const setupPanel = wrapper!.get('[aria-labelledby="setup-location-title"]');
        expect(setupPanel.text()).toContain('Locations could not be loaded.');
        await setupPanel.findAll('button').find((button) => button.text() === 'Try again')!.trigger('click');
        await flushPromises();

        expect(locationRequests).toBe(2);
        expect(wrapper!.get('#setup-location').text()).toContain('Garage');
    });

    it('shows a helpful duplicate conflict, refreshes item levels, and retains the threshold draft', async () => {
        const conflict = new AxiosError('Duplicate stock level');
        Object.defineProperty(conflict, 'response', { value: { status: 409, data: { errors: [{ detail: 'duplicate' }] } } });
        vi.mocked(http.post).mockRejectedValueOnce(conflict);
        let itemRequests = 0;
        const itemWithGarage = {
            ...item,
            inventory_levels: [...item.inventory_levels, { id: '12', quantity: 0, alert_threshold: null, stock_status: 'empty', alert_status: 'unmonitored', location: { id: '4', name: 'Garage', description: null, parent: null } }],
        };
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [{ id: '2', name: 'Pantry', description: null, parent: null }, { id: '3', name: 'Closet', description: null, parent: null }, { id: '4', name: 'Garage', description: null, parent: null }, { id: '8', name: 'Spare room', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 4 } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) {
                itemRequests++;
                return { data: { data: itemRequests > 1 ? itemWithGarage : item } } as never;
            }
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        await wrapper!.get('#setup-location').setValue('4');
        await wrapper!.get('#setup-threshold').setValue('2');
        const itemReadCount = () => vi.mocked(http.get).mock.calls.filter(([url]) => String(url) === '/items/7').length;
        const readsBeforeSubmit = itemReadCount();

        await submitLocationSetup();

        expect(wrapper!.get('[aria-label="setup-location-error"]').text()).toContain('already has a stock level');
        expect((wrapper!.get('#setup-location').element as HTMLSelectElement).value).toBe('');
        expect((wrapper!.get('#setup-threshold').element as HTMLInputElement).value).toBe('2');
        expect(itemReadCount()).toBeGreaterThan(readsBeforeSubmit);
        expect(wrapper!.get('#setup-location').text()).not.toContain('Garage');
        expect(wrapper!.find('#setup-location-form').exists()).toBe(true);
    });

    it('guards pending setup against duplicates and ignores a response after item route changes away and back', async () => {
        let finishCreate!: (response: { data: { data: { id: string; quantity: number } } }) => void;
        vi.mocked(http.post).mockImplementation((url) => {
            if (String(url) === '/inventory-levels') return new Promise((resolve) => { finishCreate = resolve; }) as never;
            return Promise.resolve({ data: { data: {} } }) as never;
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        await wrapper!.get('#setup-location').setValue('4');
        await wrapper!.get('#setup-location-form').trigger('submit');
        await flushPromises();

        expect(wrapper!.find('#setup-location-form button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper!.get('#setup-location-form').trigger('submit');
        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('8');
        await flushPromises();
        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('7');
        await flushPromises();

        finishCreate({ data: { data: { id: 'level-14', quantity: 0 } } });
        await flushPromises();

        expect(vi.mocked(http.post).mock.calls.filter(([url]) => String(url) === '/inventory-levels')).toHaveLength(1);
        expect(wrapper!.find('#setup-location-form').exists()).toBe(false);
        expect(wrapper!.text()).not.toContain('Stock location set up with 0 packs.');
    });

    it('does not show setup completion after the page unmounts during the request', async () => {
        let finishCreate!: (response: { data: { data: { id: string; quantity: number } } }) => void;
        vi.mocked(http.post).mockImplementation((url) => {
            if (String(url) === '/inventory-levels') return new Promise((resolve) => { finishCreate = resolve; }) as never;
            return Promise.resolve({ data: { data: {} } }) as never;
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        await wrapper!.get('#setup-location').setValue('4');
        await wrapper!.get('#setup-location-form').trigger('submit');
        await flushPromises();
        wrapper!.unmount();
        finishCreate({ data: { data: { id: 'level-15', quantity: 0 } } });
        await flushPromises();

        expect(vi.mocked(http.post).mock.calls.filter(([url]) => String(url) === '/inventory-levels')).toHaveLength(1);
    });

    it('does not let a late location-creation result clear a new item location draft', async () => {
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        let finishLocation!: (response: { data: { data: { id: string; name: string; description: null; parent: null } } }) => void;
        vi.mocked(http.post).mockImplementation((url) => {
            if (String(url) === '/locations') return new Promise((resolve) => { finishLocation = resolve; }) as never;
            return Promise.resolve({ data: { data: {} } }) as never;
        });
        mountPage();
        await flushPromises();
        await openLocationSetup();
        await wrapper!.findAll('button').find((button) => button.text() === 'Create a location')!.trigger('click');
        await wrapper!.get('#location-name').setValue('Old storage');
        const locationForm = () => wrapper!.findAll('form').find((form) => form.find('#location-name').exists())!;
        await locationForm().trigger('submit');
        await flushPromises();

        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('8');
        await flushPromises();
        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('7');
        await flushPromises();
        await openLocationSetup();
        await wrapper!.findAll('button').find((button) => button.text() === 'Create a location')!.trigger('click');
        await wrapper!.get('#location-name').setValue('New storage');

        finishLocation({ data: { data: { id: '55', name: 'Old storage', description: null, parent: null } } });
        await flushPromises();

        expect(wrapper!.get('#create-location-title').text()).toBe('Create a location');
        expect((wrapper!.get('#location-name').element as HTMLInputElement).value).toBe('New storage');
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
        await actionForm().trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'correction', item_id: '7', location_id: '2', observed_quantity: 4 } });
        expect(wrapper!.get('[role="alert"]').text()).toContain('Use a whole number.');
    });

    it('refreshes recent item activity after a stock movement succeeds', async () => {
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: 'movement-11' } } } as never);
        mountPage();
        await flushPromises();
        const movementRequestCount = () => vi.mocked(http.get).mock.calls.filter(([url]) => String(url) === '/inventory-movements').length;
        expect(movementRequestCount()).toBe(1);

        const closet = wrapper!.findAll('li').find((row) => row.text().includes('Closet'))!;
        await closet.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        await flushPromises();

        expect(movementRequestCount()).toBe(2);
    });

    it('restocks directly at a location without an existing stock level', async () => {
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '11' } } } as never);
        mountPage();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Restock at another location')!.trigger('click');
        await wrapper!.get('#new-level-location').setValue('3');
        await wrapper!.get('#new-level-quantity').setValue('6');
        expect(wrapper!.text()).toContain('Restock 6 packs at Closet.');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await actionForm().trigger('submit');
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
        await actionForm().trigger('submit');
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
        expect(wrapper!.findAll('form').some((form) => form.find('input[type="checkbox"]').exists())).toBe(false);
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
        await actionForm().trigger('submit');
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

        expect(wrapper!.findAll('form').some((form) => form.find('input[type="checkbox"]').exists())).toBe(false);
        expect(http.post).toHaveBeenCalledTimes(1);

        finishMovement?.({ data: { data: { id: '22' } } });
        await flushPromises();
    });

    it('does not show a completed stock action on a different item after navigation', async () => {
        let finishMovement: ((response: { data: { data: { id: string } } }) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => {
            finishMovement = resolve;
        }) as never);
        mountPage();
        await flushPromises();
        const closet = wrapper!.findAll('li').find((row) => row.text().includes('Closet'))!;
        await closet.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');

        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('8');
        await flushPromises();
        finishMovement?.({ data: { data: { id: '23' } } });
        await flushPromises();

        expect(wrapper!.text()).not.toContain('Used 1 pack from Closet.');
        expect(wrapper!.text()).not.toContain('Stock updated.');
    });

    it('opens a restock at the requested existing stock location and does not reopen after save', async () => {
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock_location: '2', restock: '1' });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '24' } } } as never);
        mountPage();
        await flushPromises();

        expect(wrapper!.get('#action-title').text()).toBe('Restock');
        expect(wrapper!.findAll('#new-level-location')).toHaveLength(0);
        expect(wrapper!.findAll('#stock-quantity')).toHaveLength(1);
        expect(wrapper!.text()).toContain('Restock 1 pack at Pantry.');

        await wrapper!.get('#stock-quantity').setValue('3');
        await wrapper!.get('input[type="checkbox"]').setValue(true);
        await actionForm().trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/inventory-movements', { data: { movement_type: 'restock', item_id: '7', location_id: '2', quantity: 3 } });
        expect(wrapper!.findAll('#action-title')).toHaveLength(0);
    });

    it('prefills a restock at the requested location when no stock level exists yet', async () => {
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock_location: '4' });
        mountPage();
        await flushPromises();

        expect(wrapper!.get('#action-title').text()).toBe('Restock');
        expect((wrapper!.get('#new-level-location').element as HTMLSelectElement).value).toBe('4');
        expect(wrapper!.text()).toContain('Restock 1 pack at Garage.');
    });

    it('waits for a pending stock action before consuming the requested restock location', async () => {
        let finishMovement: ((response: { data: { data: { id: string } } }) => void) | undefined;
        vi.mocked(http.post).mockImplementation(() => new Promise((resolve) => { finishMovement = resolve; }) as never);
        mountPage();
        await flushPromises();
        const closet = wrapper!.findAll('li').find((row) => row.text().includes('Closet'))!;
        await closet.findAll('button').find((button) => button.text() === 'Use 1')!.trigger('click');
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock_location: '2' });
        await flushPromises();

        expect(wrapper!.findAll('#action-title')).toHaveLength(0);
        finishMovement?.({ data: { data: { id: '25' } } });
        await flushPromises();

        expect(wrapper!.get('#action-title').text()).toBe('Restock');
        expect(wrapper!.text()).toContain('Restock 1 pack at Pantry.');
    });

    it('opens a generic restock once for restock=1 and keeps it closed after cancel or refetch', async () => {
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock: '1' });
        mountPage();
        await flushPromises();

        expect(wrapper!.get('#action-title').text()).toBe('Restock');
        expect(wrapper!.find('#new-level-location').exists()).toBe(true);
        expect((wrapper!.get('#new-level-location').element as HTMLSelectElement).value).toBe('');
        await wrapper!.findAll('button').find((button) => button.text() === 'Cancel')!.trigger('click');
        const itemRequestCount = () => vi.mocked(http.get).mock.calls.filter(([url]) => String(url) === '/items/7').length;
        const requestsBeforeRefetch = itemRequestCount();
        await queryClient.invalidateQueries({ queryKey: ['items', 'detail', '7'] });
        await flushPromises();

        expect(itemRequestCount()).toBeGreaterThan(requestsBeforeRefetch);
        expect(wrapper!.findAll('#action-title')).toHaveLength(0);
    });

    it('does not apply a generic restock deep link to stale data from the previous item route', async () => {
        let returnCurrentItem = false;
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: returnCurrentItem ? { ...item, id: '8' } : item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            return { data: { data: [{ id: '2', name: 'Pantry', description: null, parent: null }, { id: '3', name: 'Closet', description: null, parent: null }, { id: '4', name: 'Garage', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 3 } } } as never;
        });
        mountPage();
        await flushPromises();

        (await import('vue-router') as unknown as { setMockItem: (id: string) => void }).setMockItem('8');
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock: '1' });
        await flushPromises();
        expect(wrapper!.findAll('#action-title')).toHaveLength(0);

        returnCurrentItem = true;
        await queryClient.invalidateQueries({ queryKey: ['items', 'detail', '8'] });
        await flushPromises();
        expect(wrapper!.get('#action-title').text()).toBe('Restock');
    });

    it('keeps malformed restock query values inert', async () => {
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock: ['1'] });
        mountPage();
        await flushPromises();

        expect(wrapper!.findAll('#action-title')).toHaveLength(0);
    });

    it('keeps generic restock open with no locations and disables saving until a location exists', async () => {
        (await import('vue-router') as unknown as { setMockQuery: (query: Record<string, string | string[]>) => void }).setMockQuery({ restock: '1' });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).includes('/images')) return { data: { data: [] } } as never;
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url) === '/inventory-movements') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/items/')) return { data: { data: item } } as never;
            if (String(url) === '/inventory-alerts') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            return { data: { data: [] } } as never;
        });
        mountPage();
        await flushPromises();

        expect(wrapper!.get('#action-title').text()).toBe('Restock');
        expect(wrapper!.text()).toContain('Add a location before recording this restock.');
        expect(actionForm().find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(actionForm().text()).toContain('Create a location');
    });

    it('does not send a patch when the item editor values are unchanged', async () => {
        mountPage();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Edit item')!.trigger('click');
        await flushPromises();
        await wrapper!.get('#edit-item-panel form').trigger('submit');
        await flushPromises();

        expect(http.patch).not.toHaveBeenCalledWith('/items/7', expect.anything());
        expect(wrapper!.text()).toContain('Item details are unchanged.');
    });

    it('patches only changed item fields and explicitly clears removed categories', async () => {
        vi.mocked(http.patch).mockResolvedValue({ data: { data: item } } as never);
        mountPage();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Edit item')!.trigger('click');
        await flushPromises();
        await wrapper!.get('#item-name').setValue('Rechargeable batteries');
        queryClient.setQueryData(['items', 'detail', '7'], { ...item, name: 'Remote name', counting_unit: 'case', description: 'Changed elsewhere' });
        await flushPromises();
        await wrapper!.get('#edit-item-panel form').trigger('submit');
        await flushPromises();

        expect(http.patch).toHaveBeenCalledWith('/items/7', { data: { name: 'Rechargeable batteries' } });

        await wrapper!.findAll('button').find((button) => button.text() === 'Edit item')!.trigger('click');
        await flushPromises();
        await wrapper!.get('input[type="checkbox"][value="2"]').setValue(false);
        await wrapper!.get('input[type="checkbox"][value="3"]').setValue(false);
        await wrapper!.get('#edit-item-panel form').trigger('submit');
        await flushPromises();

        expect(http.patch).toHaveBeenLastCalledWith('/items/7', { data: { category_ids: [] } });
    });
});
