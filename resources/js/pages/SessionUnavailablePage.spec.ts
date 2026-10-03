import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { installSessionGuards, routes } from '../router';
import { useSessionStore } from '../stores/session';
import SessionUnavailablePage from './SessionUnavailablePage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn() }, requestCsrfCookie: vi.fn() }));

describe('session recovery page', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('retries session loading and restores the original protected deep link', async () => {
        vi.mocked(http.get)
            .mockResolvedValueOnce({ data: { data: { id: '3', name: 'Taylor', email: 'taylor@example.test', membership: { role: 'owner', household: { id: '2', name: 'Home' } } } } } as never);
        const router = createRouter({ history: createMemoryHistory(), routes });
        installSessionGuards(router);
        useSessionStore().rememberPendingDestination('/inventory?create=1');
        await router.push('/session-unavailable');
        const wrapper = mount(SessionUnavailablePage, { global: { plugins: [router] } });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/inventory?create=1');
        expect(useSessionStore().pendingDestination).toBeNull();
        expect(wrapper.text()).not.toContain('We still couldn’t reach the server.');
        wrapper.unmount();
    });
});
