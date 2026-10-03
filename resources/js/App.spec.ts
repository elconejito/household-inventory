import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { describe, expect, it } from 'vitest';
import App from './App.vue';
import { useSessionStore } from './stores/session';

describe('household query cache', () => {
    it('discards inventory when the session ends or changes household', () => {
        const pinia = createPinia();
        setActivePinia(pinia);
        const session = useSessionStore(pinia);
        const queryClient = new QueryClient();
        session.user = {
            id: '1', name: 'Member', email: 'member@example.test',
            membership: { role: 'owner', household: { id: '1', name: 'Home' } },
        };
        const wrapper = mount(App, {
            global: { plugins: [pinia, [VueQueryPlugin, { queryClient }]], stubs: { RouterView: true } },
        });

        queryClient.setQueryData(['items', 'detail', '7'], { name: 'Private stock' });
        session.user = null;
        expect(queryClient.getQueryData(['items', 'detail', '7'])).toBeUndefined();

        session.user = {
            id: '2', name: 'Other member', email: 'other@example.test',
            membership: { role: 'owner', household: { id: '2', name: 'Other home' } },
        };
        queryClient.setQueryData(['inventory-movements'], [{ id: '3' }]);
        session.user.membership!.household!.id = '3';
        expect(queryClient.getQueryData(['inventory-movements'])).toBeUndefined();

        wrapper.unmount();
        queryClient.clear();
    });
});
