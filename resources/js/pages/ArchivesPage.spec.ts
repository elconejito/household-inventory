import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { useSessionStore } from '../stores/session';
import ArchivesPage from './ArchivesPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));

describe('archived inventory management', () => {
    let pinia: ReturnType<typeof createPinia>;
    let client: QueryClient;
    let router: ReturnType<typeof createRouter>;
    let wrapper: ReturnType<typeof mount> | undefined;

    beforeEach(async () => {
        vi.clearAllMocks();
        pinia = createPinia();
        setActivePinia(pinia);
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/settings/archives', name: 'archives', component: ArchivesPage },
            { path: '/settings', name: 'settings', component: { template: '<p>Settings</p>' } },
        ] });
        await router.push('/settings/archives');
        vi.mocked(http.get).mockImplementation(async (url) => ({ data: {
            data: [{ type: url === '/items' ? 'items' : url === '/categories' ? 'categories' : 'locations', id: '8', name: 'Archived record' }],
            meta: { current_page: 1, last_page: 1, total: 1 },
        } }) as never);
        useSessionStore(pinia).$patch({ user: { id: '3', name: 'Sam', email: 'sam@example.com', membership: { role: 'owner', household: { id: '4', name: 'Home' } } }, status: 'authenticated' });
        vi.mocked(http.post).mockResolvedValue(undefined as never);
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    function mountPage(): void {
        wrapper = mount(ArchivesPage, { global: { plugins: [pinia, router, [VueQueryPlugin, { queryClient: client }]] } });
    }

    it('clears destructive confirmation when switching resource tabs', async () => {
        mountPage();
        await flushPromises();
        await wrapper!.get('[aria-label="Permanently delete Archived record"]').trigger('click');
        await wrapper!.get('[aria-label="Type DELETE to confirm"]').setValue('DELETE');
        expect(wrapper!.text()).toContain('Type DELETE to confirm.');

        await wrapper!.findAll('button').find((button) => button.text() === 'Categories')!.trigger('click');
        await flushPromises();

        expect(wrapper!.find('[aria-label="Type DELETE to confirm"]').exists()).toBe(false);
        expect(wrapper!.text()).not.toContain('permanently deleted.');
    });

    it('lets members restore archived records but hides permanent deletion', async () => {
        useSessionStore(pinia).$patch({ user: { id: '3', name: 'Sam', email: 'sam@example.com', membership: { role: 'member', household: { id: '4', name: 'Home' } } }, status: 'authenticated' });
        mountPage();
        await flushPromises();

        expect(wrapper!.find('[aria-label="Permanently delete Archived record"]').exists()).toBe(false);
        await wrapper!.findAll('button').find((button) => button.text() === 'Restore')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/items/8/restore');
        expect(wrapper!.text()).toContain('Archived record restored.');
    });
});
