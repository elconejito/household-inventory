import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it } from 'vitest';
import App from '../App.vue';
import { routes } from '../router';

describe('application shell', () => {
    it('navigates between the main sections and marks the current section', async () => {
        const router = createRouter({
            history: createMemoryHistory(),
            routes,
        });

        await router.push('/inventory');
        await router.isReady();

        const wrapper = mount(App, {
            global: {
                plugins: [router],
            },
        });

        expect(wrapper.get('h1').text()).toBe('Inventory');
        expect(wrapper.get('a[href="/inventory"]').attributes('aria-current')).toBe('page');
        expect(wrapper.findAll('nav a').map((link) => link.text())).toContain('Activity');

        await wrapper.get('a[href="/activity"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('h1').text()).toBe('Activity');
        expect(wrapper.get('a[href="/activity"]').attributes('aria-current')).toBe('page');
    });
});
