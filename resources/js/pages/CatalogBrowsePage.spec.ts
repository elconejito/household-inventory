import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import CatalogBrowsePage from './CatalogBrowsePage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));
const route = vi.hoisted(() => ({ name: 'catalog-locations' as string, query: {} as Record<string, string> }));
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ push: vi.fn() }) }));

describe('catalog browse page', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        route.name = 'catalog-locations';
        route.query = {};
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url) === '/locations') return { data: { data: [{ id: '21', name: 'House', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            if (String(url) === '/categories') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '55', name: 'Main pantry', description: null, parent: null } } } as never);
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); });

    function mountPage(): void {
        wrapper = mount(CatalogBrowsePage, { global: { plugins: [createPinia(), [VueQueryPlugin, { queryClient: client }]], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
    }

    it('browses top-level locations and never submits the root sentinel as a parent ID', async () => {
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/locations', { params: { include: 'parent', 'filter[parent_id]': 'root', sort: 'name', page: 1, per_page: 10 } });
        await wrapper!.get('button').trigger('click');
        await wrapper!.get('#location-name').setValue('Main pantry');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();
        expect(http.post).toHaveBeenCalledWith('/locations', { data: { name: 'Main pantry', description: null, parent_id: null } });
    });

    it('opens the child-location form from a location detail route', async () => {
        route.query = { parent: '21', create: '1' };
        mountPage();
        await flushPromises();
        expect(wrapper!.find('#location-name').exists()).toBe(true);
        expect((wrapper!.get('#location-parent').element as HTMLSelectElement).value).toBe('21');
    });

    it('keeps location rows visible and retries when hierarchy options fail', async () => {
        let hierarchyRequests = 0;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            const params = config?.params as Record<string, unknown> | undefined;
            if (String(url) === '/locations' && params?.per_page === 100) {
                hierarchyRequests++;
                if (hierarchyRequests === 1) throw new Error('Temporary hierarchy failure');

                return { data: { data: [{ id: '21', name: 'House', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            }
            if (String(url) === '/locations') {
                return { data: { data: [{ id: '22', name: 'Pantry', description: null, parent: { id: '21', name: 'House' } }], meta: { current_page: 1, last_page: 1, total: 1 } } } as never;
            }

            return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
        });
        mountPage();
        await flushPromises();

        expect(wrapper!.text()).toContain('Pantry');
        expect(wrapper!.get('[role="alert"]').text()).toContain('Location names may not show their full paths.');
        expect(hierarchyRequests).toBe(1);
        await wrapper!.findAll('button').find((button) => button.text() === 'Retry location hierarchy')!.trigger('click');
        await flushPromises();

        expect(hierarchyRequests).toBe(2);
        expect(wrapper!.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper!.text()).toContain('House / Pantry');
    });
});
