import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import ContextDetailPage from './ContextDetailPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
const route = vi.hoisted(() => ({ name: 'category-detail' as string, params: { category: '12', location: '21' } }));
vi.mock('vue-router', () => ({ useRoute: () => route }));

describe('category and location note contexts', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(() => {
        vi.clearAllMocks();
        route.name = 'category-detail';
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (String(url).endsWith('/notes')) return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (String(url).startsWith('/categories/')) return { data: { data: { id: '12', name: 'Pantry supplies' } } } as never;
            return { data: { data: { id: '21', name: 'Kitchen', description: 'Lower cabinets', parent: { id: '20', name: 'House', description: null, parent: null } } } } as never;
        });
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); });

    function mountPage(): void {
        wrapper = mount(ContextDetailPage, { global: { plugins: [[VueQueryPlugin, { queryClient: client }]], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
    }

    it('loads a category detail identity and offers its notes', async () => {
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/categories/12');
        expect(wrapper!.get('h1').text()).toBe('Pantry supplies');
        expect(http.get).toHaveBeenCalledWith('/categories/12/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
    });

    it('shows the location hierarchy and requests notes for that location', async () => {
        route.name = 'location-detail';
        mountPage();
        await flushPromises();
        expect(http.get).toHaveBeenCalledWith('/locations/21', { params: { include: 'parent' } });
        expect(wrapper!.get('h1').text()).toBe('Kitchen');
        expect(wrapper!.text()).toContain('House / Kitchen');
        expect(http.get).toHaveBeenCalledWith('/locations/21/notes', { params: { include: 'created_by', per_page: 10, page: 1 } });
    });
});
