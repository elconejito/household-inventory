import { AxiosError } from 'axios';
import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import InvitationAcceptPage from './InvitationAcceptPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn() }, requestCsrfCookie: vi.fn() }));

const acceptedUser = {
    id: '8', name: 'Sam', email: 'sam@example.com',
    membership: { id: '4', role: 'member' as const, household: { id: '3', name: 'Home' } },
};

describe('invitation acceptance', () => {
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;
    let router: ReturnType<typeof createRouter>;

    beforeEach(async () => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/invitations/accept', name: 'invitation-accept', component: InvitationAcceptPage },
            { path: '/', name: 'dashboard', component: { template: '<p>Dashboard</p>' } },
            { path: '/login', name: 'login', component: { template: '<p>Sign in</p>' } },
        ] });
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/user') {
                const error = new AxiosError('Unauthenticated');
                Object.defineProperty(error, 'response', { value: { status: 401 } });
                throw error;
            }
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockImplementation(async (url) => {
            if (url === '/household-invitations/accept') return { data: { data: acceptedUser } } as never;
            if (url === '/logout') return undefined as never;
            throw new Error(`Unexpected POST ${String(url)}`);
        });
        await router.push('/invitations/accept#token=invite-secret');
        await router.isReady();
        wrapper = mount(InvitationAcceptPage, { global: { plugins: [router, createPinia(), [VueQueryPlugin, { queryClient: client }]] } });
        await flushPromises();
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    it('keeps the token out of the visible URL and creates an account to join', async () => {
        const invalidate = vi.spyOn(client, 'clear');
        await wrapper!.get('#accept-name').setValue('Sam');
        await wrapper!.get('#accept-email').setValue('sam@example.com');
        await wrapper!.get('#accept-password').setValue('test-password');
        await wrapper!.get('#accept-password-confirmation').setValue('test-password');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        expect(window.location.hash).toBe('');
        expect(http.post).toHaveBeenCalledWith('/household-invitations/accept', expect.objectContaining({ data: expect.objectContaining({ token: 'invite-secret' }) }), expect.anything());
        expect(http.get).toHaveBeenCalledWith('/user', { params: { include: 'membership.household' } });
        expect(invalidate).toHaveBeenCalledOnce();
        expect(router.currentRoute.value.name).toBe('dashboard');
    });

    it('keeps an invitation token in the login fragment rather than a query parameter', async () => {
        await wrapper!.get('#accept-name').setValue('Sam');
        await wrapper!.get('#accept-email').setValue('sam@example.com');
        await wrapper!.get('#accept-password').setValue('test-password');
        await wrapper!.get('#accept-password-confirmation').setValue('test-password');
        await wrapper!.get('button[type="button"]').trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.hash).toBe('#token=invite-secret');
        expect(router.currentRoute.value.query.redirect).toBe('/invitations/accept');
        expect(JSON.stringify(router.currentRoute.value.query)).not.toContain('invite-secret');
    });

    it('associates validation messages with the matching invitation fields', async () => {
        const validationError = new AxiosError('Validation failed');
        Object.defineProperty(validationError, 'response', { value: { data: { errors: [{ source: { pointer: '/data/email' }, detail: 'Use the invited email address.' }] } } });
        vi.mocked(http.post).mockRejectedValueOnce(validationError);
        await wrapper!.get('#accept-name').setValue('Sam');
        await wrapper!.get('#accept-email').setValue('wrong@example.com');
        await wrapper!.get('#accept-password').setValue('test-password');
        await wrapper!.get('#accept-password-confirmation').setValue('test-password');
        await wrapper!.get('form').trigger('submit');
        await flushPromises();

        const emailInput = wrapper!.get('#accept-email');
        expect(emailInput.attributes('aria-invalid')).toBe('true');
        expect(emailInput.attributes('aria-describedby')).toBe('accept-email-error');
        expect(wrapper!.get('#accept-email-error').text()).toBe('Use the invited email address.');
    });
});
