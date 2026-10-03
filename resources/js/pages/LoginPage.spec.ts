import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import LoginPage from './LoginPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn() }, requestCsrfCookie: vi.fn() }));

describe('login invitation return', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;
    let router: ReturnType<typeof createRouter>;

    beforeEach(async () => {
        vi.clearAllMocks();
        client = new QueryClient();
        router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/login', name: 'login', component: LoginPage },
            { path: '/register', name: 'register', component: { template: '<p>Register</p>' } },
            { path: '/invitations/accept', name: 'invitation-accept', component: { template: '<p>Accept</p>' } },
        ] });
        vi.mocked(http.post).mockResolvedValue(undefined as never);
        vi.mocked(http.get).mockResolvedValue({ data: { data: { id: '9', name: 'Sam', email: 'sam@example.com', membership: null } } } as never);
        await router.push('/login?redirect=%2Finvitations%2Faccept#token=opaque-secret');
        wrapper = mount(LoginPage, { global: { plugins: [router, createPinia(), [VueQueryPlugin, { queryClient: client }]] } });
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    it('keeps the token in the invitation fragment after sign-in', async () => {
        await wrapper!.get('#login-email').setValue('sam@example.com');
        await wrapper!.get('#login-password').setValue('safe-password');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('invitation-accept');
        expect(router.currentRoute.value.hash).toBe('#token=opaque-secret');
        expect(router.currentRoute.value.query.redirect).toBeUndefined();
        expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('opaque-secret');
    });
});
