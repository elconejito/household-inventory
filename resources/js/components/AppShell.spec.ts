import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import App from '../App.vue';
import { routes } from '../router';
import { useSessionStore } from '../stores/session';

describe('application shell', () => {
    it('navigates between the main sections and marks the current section', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes,
        });
        const pinia = createPinia();
        const queryClient = new QueryClient({
            defaultOptions: { queries: { retry: false } },
        });
        setActivePinia(pinia);
        useSessionStore(pinia).$patch({
            user: {
                id: '1',
                name: 'Jordan Ramos',
                email: 'jordan@example.com',
                membership: { role: 'owner', household: { id: '1', name: 'Ramos Home' } },
            },
            status: 'authenticated',
        });

        await router.push('/inventory');
        await router.isReady();

        const wrapper = mount(App, {
            attachTo: document.body,
            global: {
                plugins: [pinia, [VueQueryPlugin, { queryClient }], router],
            },
        });

        expect(document.activeElement).toBe(document.body);
        expect(wrapper.get('a[href="#main-content"]').text()).toBe('Skip to main content');
        expect(wrapper.get('main#main-content').attributes('tabindex')).toBe('-1');
        expect(wrapper.get('h1').text()).toBe('Inventory');
        expect(wrapper.get('a[href="/inventory"]').attributes('aria-current')).toBe('page');
        expect(wrapper.findAll('nav a').map((link) => link.text())).toContain('Activity');
        expect(wrapper.findAll('a[aria-label="Household Inventory home"] span').find((span) => span.text() === 'Home inventory')!.classes()).toContain('max-[360px]:hidden');
        expect(wrapper.get('header .bg-sage-soft').classes()).toContain('max-[360px]:hidden');

        await wrapper.get('a[href="/activity"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('h1').text()).toBe('Activity');
        expect(wrapper.get('h1').attributes('tabindex')).toBe('-1');
        expect(document.activeElement).toBe(wrapper.get('h1').element);
        expect(wrapper.get('a[href="/activity"]').attributes('aria-current')).toBe('page');

        const signOutButton = wrapper.get('header button').element as HTMLElement;
        signOutButton.focus();
        expect(document.activeElement).toBe(signOutButton);

        await router.push({ name: 'activity', query: { item_id: '7' } });
        await flushPromises();

        expect(document.activeElement).toBe(signOutButton);

        await router.push({ name: 'activity', query: { item_id: '7' }, hash: '#activity-filters' });
        await flushPromises();

        expect(document.activeElement).toBe(signOutButton);

        wrapper.unmount();
        queryClient.clear();
    });
});
