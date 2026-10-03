import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { AxiosError } from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { useSessionStore } from '../stores/session';
import SettingsPage from './SettingsPage.vue';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() }, requestCsrfCookie: vi.fn() }));

const memberPage = {
    data: [{ type: 'memberships', id: '5', role: 'owner', deleted_at: null, user: { type: 'users', id: '2', name: 'Owner', email: 'owner@example.com' } }],
    meta: { current_page: 1, last_page: 1, total: 1 },
};

describe('household settings', () => {
    let pinia: ReturnType<typeof createPinia>;
    let client: QueryClient;
    let wrapper: ReturnType<typeof mount> | undefined;
    let router: ReturnType<typeof createRouter>;

    beforeEach(async () => {
        vi.clearAllMocks();
        pinia = createPinia();
        setActivePinia(pinia);
        client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
        router = createRouter({ history: createMemoryHistory(), routes: [
            { path: '/settings', name: 'settings', component: SettingsPage },
            { path: '/settings/archives', name: 'archives', component: { template: '<p>Archive</p>' } },
            { path: '/invitations/accept', name: 'invitation-accept', component: { template: '<p>Accept</p>' } },
        ] });
        await router.push('/settings');
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') return { data: memberPage } as never;
            if (url === '/household-invitations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
    });

    afterEach(() => { wrapper?.unmount(); wrapper = undefined; client.clear(); vi.restoreAllMocks(); });

    function setMembership(role: 'owner' | 'member'): void {
        useSessionStore(pinia).$patch({
            user: { id: '2', name: 'Avery', email: 'avery@example.com', membership: { id: '5', role, household: { id: '4', name: 'Ramos Home' } } },
            status: 'authenticated',
        });
    }

    function mountPage(): void {
        wrapper = mount(SettingsPage, { global: { plugins: [pinia, router, [VueQueryPlugin, { queryClient: client }]] } });
    }

    it('does not request owner-only household or membership admin data for members', async () => {
        setMembership('member');
        mountPage();
        await flushPromises();

        expect(wrapper!.text()).toContain('Ramos Home');
        expect(wrapper!.text()).toContain('Leave household');
        expect(wrapper!.find('a[href="/settings/archives"]').exists()).toBe(true);
        expect(http.get).not.toHaveBeenCalledWith('/household', expect.anything());
        expect(http.get).not.toHaveBeenCalledWith('/memberships', expect.anything());
        expect(http.get).not.toHaveBeenCalledWith('/household-invitations', expect.anything());
    });

    it('shows the owner invitation one-time link after creation', async () => {
        setMembership('owner');
        vi.mocked(http.post).mockResolvedValue({ data: { data: { type: 'household-invitations', id: '10', email: 'new@example.com', role: 'member', created_at: '2026-10-01', expires_at: '2026-10-08', accepted_at: null, revoked_at: null, is_expired: false, invitation_url: 'https://example.test/invitations/accept#token=secret' } } } as never);
        mountPage();
        await flushPromises();
        await wrapper!.get('#invite-email').setValue('new@example.com');
        await wrapper!.findAll('form')[1].trigger('submit');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/household-invitations', { data: { email: 'new@example.com' } });
        expect((wrapper!.get('#one-time-invitation-link').element as HTMLInputElement).value).toBe('https://example.test/invitations/accept#token=secret');
    });

    it('paginates invitations by ten and keeps the one-time link separate from list pages', async () => {
        setMembership('owner');
        const currentPageInvites = [
            { type: 'household-invitations', id: 'accepted', email: 'accepted@example.com', role: 'member', created_at: '2026-10-01', expires_at: '2026-10-02', accepted_at: '2026-10-01', revoked_at: null, is_expired: true },
            { type: 'household-invitations', id: 'revoked', email: 'revoked@example.com', role: 'member', created_at: '2026-10-01', expires_at: '2026-10-02', accepted_at: null, revoked_at: '2026-10-01', is_expired: true },
            { type: 'household-invitations', id: 'pending', email: 'pending@example.com', role: 'member', created_at: '2026-10-01', expires_at: '2026-10-02', accepted_at: null, revoked_at: null, is_expired: true },
        ];
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') return { data: memberPage } as never;
            if (url === '/household-invitations') {
                const page = Number((config?.params as { page?: number } | undefined)?.page ?? 1);
                return { data: { data: page === 1 ? currentPageInvites : [{ ...currentPageInvites[2], id: 'older', email: 'older@example.com' }], meta: { current_page: page, last_page: 2, total: 11 } } } as never;
            }
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { type: 'household-invitations', id: '10', email: 'new@example.com', role: 'member', created_at: '2026-10-01', expires_at: '2026-10-08', accepted_at: null, revoked_at: null, is_expired: false, invitation_url: 'https://example.test/invitations/accept#token=secret' } } } as never);
        mountPage();
        await flushPromises();
        expect((wrapper!.get('[data-testid="invitation-row-accepted"]').findAll('button'))).toHaveLength(0);
        expect(wrapper!.get('[data-testid="invitation-row-revoked"]').findAll('button')).toHaveLength(0);
        expect(wrapper!.get('[data-testid="invitation-row-pending"]').findAll('button')).toHaveLength(2);
        expect(http.get).toHaveBeenCalledWith('/household-invitations', { params: { per_page: 10, page: 1 } });

        await wrapper!.get('#invite-email').setValue('new@example.com');
        await wrapper!.findAll('form')[1].trigger('submit');
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Next')!.trigger('click');
        await flushPromises();

        expect(http.get).toHaveBeenCalledWith('/household-invitations', { params: { per_page: 10, page: 2 } });
        expect(wrapper!.text()).toContain('older@example.com');
        expect((wrapper!.get('#one-time-invitation-link').element as HTMLInputElement).value).toBe('https://example.test/invitations/accept#token=secret');
    });

    it('restores the visible role when the server rejects removing the final owner', async () => {
        setMembership('owner');
        const conflict = new AxiosError('Cannot remove the final owner');
        Object.defineProperty(conflict, 'response', { value: { data: { errors: [{ detail: 'The final owner must remain an owner.' }] } } });
        vi.mocked(http.patch).mockRejectedValue(conflict);
        mountPage();
        await flushPromises();

        const role = wrapper!.get('select[aria-label="Role for Owner"]');
        await role.setValue('member');
        await flushPromises();
        await flushPromises();

        expect(http.patch).toHaveBeenCalledWith('/memberships/5', { data: { role: 'member' } });
        expect((role.element as HTMLSelectElement).value).toBe('owner');
        expect(wrapper!.get('[role="alert"]').text()).toContain('The final owner must remain an owner.');
    });
});
