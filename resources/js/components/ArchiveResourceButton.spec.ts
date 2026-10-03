import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import ArchiveResourceButton from './ArchiveResourceButton.vue';

vi.mock('../lib/http', () => ({ http: { delete: vi.fn() } }));

describe('archive resource button', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;
    let router: ReturnType<typeof createRouter>;

    beforeEach(async () => {
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/items/:item', name: 'inventory-item', component: { template: '<div />' } },
            { path: '/inventory', name: 'inventory', component: { template: '<div />' } },
        ] });
        await router.push('/items/7');
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        vi.mocked(http.delete).mockResolvedValue(undefined as never);
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    function mountButton(): void {
        wrapper = mount(ArchiveResourceButton, { props: { type: 'items', id: '7', label: 'Item' }, global: { plugins: [router, [VueQueryPlugin, { queryClient: client }]] } });
    }

    it('archives with reversible confirmation and returns to inventory', async () => {
        const routeAtInvalidation: string[] = [];
        const invalidate = vi.spyOn(client, 'invalidateQueries').mockImplementation(async () => {
            routeAtInvalidation.push(String(router.currentRoute.value.name));
            return undefined;
        });
        mountButton();
        await wrapper!.get('button').trigger('click');
        await flushPromises();

        expect(window.confirm).toHaveBeenCalledWith('Archive Item? You can restore it later from Archived inventory.');
        expect(http.delete).toHaveBeenCalledWith('/items/7');
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['items'] });
        expect(router.currentRoute.value.name).toBe('inventory');
        expect(routeAtInvalidation).toEqual(['inventory']);
    });
});
