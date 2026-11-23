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

        await router.push('/items');
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
        expect(wrapper.get('h1').text()).toBe('Items');
        expect(wrapper.get('a[href="/items"]').attributes('aria-current')).toBe('page');
        expect(wrapper.findAll('nav a').map((link) => link.text())).toContain('Activity');
        expect(wrapper.get('a[aria-label="Household home"]').text()).toContain('Household');
        const searchButton = wrapper.get('header button[aria-label="Search all"]');
        expect(searchButton.attributes('aria-haspopup')).toBe('dialog');
        expect(wrapper.get('header summary').text()).toContain('Account');
        expect(wrapper.get('.app-footer').text()).toContain('Ramos Home');
        await wrapper.get('.mobile-more > summary').trigger('click');
        expect(wrapper.get('.mobile-more').attributes('open')).toBeDefined();
        expect(wrapper.get('.mobile-more-panel').text()).toContain('Account & settings');
        await wrapper.get('.mobile-more > summary').trigger('click');

        await wrapper.get('a[href="/activity"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('h1').text()).toBe('Activity');
        expect(wrapper.get('h1').attributes('tabindex')).toBe('-1');
        expect(document.activeElement).toBe(wrapper.get('h1').element);
        expect(wrapper.get('a[href="/activity"]').attributes('aria-current')).toBe('page');

        const signOutButton = wrapper.findAll('header button').find((button) => button.text() === 'Sign out')!.element as HTMLElement;
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
