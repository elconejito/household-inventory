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

    it('reports a saved role when session refresh fails and retries refresh without repeating the write', async () => {
        setMembership('owner');
        vi.mocked(http.patch).mockResolvedValue({ data: { data: { ...memberPage.data[0], role: 'member' } } } as never);
        vi.mocked(http.get).mockImplementation(async (url) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') return { data: memberPage } as never;
            if (url === '/household-invitations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/user') {
                if (vi.mocked(http.get).mock.calls.filter(([calledUrl]) => calledUrl === '/user').length === 1) throw new Error('Network unavailable');
                return { data: { data: { id: '2', name: 'Avery', email: 'avery@example.com', membership: { id: '5', role: 'member', household: { id: '4', name: 'Ramos Home' } } } } } as never;
            }
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        mountPage();
        await flushPromises();

        await wrapper!.get('select[aria-label="Role for Owner"]').setValue('member');
        await flushPromises();

        expect(http.patch).toHaveBeenCalledTimes(1);
        expect(wrapper!.text()).toContain('Membership role saved.');
        expect(wrapper!.text()).toContain('Your change was saved, but your session could not be refreshed.');
        expect(wrapper!.text()).not.toContain('That role could not be changed.');

        await wrapper!.findAll('button').find((button) => button.text() === 'Retry session refresh')!.trigger('click');
        await flushPromises();

        expect(http.patch).toHaveBeenCalledTimes(1);
        expect(vi.mocked(http.get).mock.calls.filter(([url]) => url === '/user')).toHaveLength(2);
        expect(wrapper!.text()).not.toContain('Your change was saved, but your session could not be refreshed.');
    });

    it('clamps the membership page after removing its final row', async () => {
        setMembership('owner');
        const firstPage = Array.from({ length: 10 }, (_, index) => ({ type: 'memberships', id: String(index + 1), role: 'member' as const, deleted_at: null, user: { type: 'users', id: String(index + 1), name: `Member ${index + 1}`, email: `member${index + 1}@example.com` } }));
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') {
                const page = Number((config?.params as { page?: number } | undefined)?.page ?? 1);
                return { data: { data: page === 1 ? firstPage : [{ ...firstPage[0], id: '11', user: { ...firstPage[0].user, id: '11', name: 'Last member' } }], meta: { current_page: page, last_page: 2, total: 11 } } } as never;
            }
            if (url === '/household-invitations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        let finishRemoval: ((response: undefined) => void) | undefined;
        vi.mocked(http.delete).mockImplementation(() => new Promise((resolve) => { finishRemoval = resolve; }) as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        mountPage();
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Next')!.trigger('click');
        await flushPromises();
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') {
                const page = Number((config?.params as { page?: number } | undefined)?.page ?? 1);
                return { data: { data: page === 1 ? firstPage : [], meta: { current_page: page, last_page: 1, total: 10 } } } as never;
            }
            if (url === '/household-invitations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        await wrapper!.findAll('button').find((button) => button.text() === 'Remove')!.trigger('click');
        await flushPromises();
        expect(wrapper!.findAll('button').find((button) => button.text() === 'Previous')!.attributes('disabled')).toBeDefined();
        finishRemoval?.(undefined);
        await flushPromises();

        expect(http.delete).toHaveBeenCalledWith('/memberships/11');
        expect(http.get).toHaveBeenCalledWith('/memberships', expect.objectContaining({ params: expect.objectContaining({ page: 1 }) }));
        expect(wrapper!.text()).toContain('Member 1');
        expect(wrapper!.text()).not.toContain('Page 2 of 1');
    });

    it('clamps the archived membership page after restoring its final row', async () => {
        setMembership('owner');
        const archived = Array.from({ length: 10 }, (_, index) => ({ type: 'memberships', id: String(index + 1), role: 'member' as const, deleted_at: '2026-09-01', user: { type: 'users', id: String(index + 1), name: `Archived ${index + 1}`, email: `archived${index + 1}@example.com` } }));
        let restored = false;
        vi.mocked(http.get).mockImplementation(async (url, config) => {
            if (url === '/household') return { data: { data: { type: 'households', id: '4', name: 'Ramos Home' } } } as never;
            if (url === '/memberships') {
                const params = config?.params as { page?: number; 'filter[trashed]'?: string } | undefined;
                const page = Number(params?.page ?? 1);
                if (params?.['filter[trashed]'] === 'only') {
                    return { data: { data: page === 1 ? archived : restored ? [] : [{ ...archived[0], id: '11', user: { ...archived[0].user, id: '11', name: 'Last archived' } }], meta: { current_page: page, last_page: restored ? 1 : 2, total: restored ? 10 : 11 } } } as never;
                }
                return { data: memberPage } as never;
            }
            if (url === '/household-invitations') return { data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never;
            if (url === '/user') throw new Error('Session refresh unavailable');
            throw new Error(`Unexpected GET ${String(url)}`);
        });
        vi.mocked(http.post).mockImplementation(async (url) => {
            if (url === '/memberships/11/restore') {
                restored = true;
                return { data: { data: { ...archived[0], id: '11', deleted_at: null } } } as never;
            }
            throw new Error(`Unexpected POST ${String(url)}`);
        });
        mountPage();
        await flushPromises();
        await wrapper!.get('select').setValue('only');
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Next')!.trigger('click');
        await flushPromises();
        await wrapper!.findAll('button').find((button) => button.text() === 'Restore')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/memberships/11/restore');
        expect(http.get).toHaveBeenCalledWith('/memberships', expect.objectContaining({ params: expect.objectContaining({ page: 1, 'filter[trashed]': 'only' }) }));
        expect(wrapper!.text()).toContain('Archived 1');
        expect(wrapper!.text()).toContain('Membership restored.');
        expect(wrapper!.text()).toContain('Your change was saved, but your session could not be refreshed.');
        expect(wrapper!.text()).not.toContain('Page 2 of 1');
    });

    it('clears self membership and leaves protected settings after a successful leave', async () => {
        setMembership('member');
        vi.mocked(http.post).mockResolvedValue(undefined as never);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        mountPage();
        await flushPromises();

        await wrapper!.findAll('button').find((button) => button.text() === 'Leave household')!.trigger('click');
        await flushPromises();

        expect(http.post).toHaveBeenCalledWith('/membership/leave');
        expect(useSessionStore(pinia).user?.membership).toBeNull();
        expect(router.currentRoute.value.name).toBe('invitation-accept');
        expect(http.get).not.toHaveBeenCalledWith('/user', expect.anything());
    });
});
