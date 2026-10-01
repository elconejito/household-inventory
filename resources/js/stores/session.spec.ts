import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http, requestCsrfCookie } from '../lib/http';
import { useSessionStore } from './session';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
    },
    requestCsrfCookie: vi.fn(),
}));

describe('session store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('loads the current user with the household include and caches the session', async () => {
        vi.mocked(http.get).mockResolvedValue({
            data: {
                data: {
                    id: '8',
                    name: 'Avery Ramos',
                    email: 'avery@example.com',
                    membership: {
                        role: 'owner',
                        household: { id: '4', name: 'Ramos Home' },
                    },
                },
            },
        });
        const session = useSessionStore();

        await expect(session.ensureLoaded()).resolves.toMatchObject({
            name: 'Avery Ramos',
            membership: { household: { name: 'Ramos Home' } },
        });
        await session.ensureLoaded();

        expect(http.get).toHaveBeenCalledTimes(1);
        expect(http.get).toHaveBeenCalledWith('/user', {
            params: { include: 'membership.household' },
        });
        expect(session.status).toBe('authenticated');
    });

    it('gets a CSRF cookie before login and refreshes the full session', async () => {
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '2' } } });
        vi.mocked(http.get).mockResolvedValue({
            data: { data: { id: '2', name: 'Sam Lee', email: 'sam@example.com' } },
        });
        const session = useSessionStore();

        await session.login({ email: 'sam@example.com', password: 'safe-password' });

        expect(requestCsrfCookie).toHaveBeenCalledOnce();
        expect(http.post).toHaveBeenCalledWith('/login', {
            data: { email: 'sam@example.com', password: 'safe-password' },
        });
        expect(http.get).toHaveBeenCalledWith('/user', {
            params: { include: 'membership.household' },
        });
        expect(session.user?.name).toBe('Sam Lee');
    });

    it('clears session state after a successful logout', async () => {
        const session = useSessionStore();
        session.$patch({
            user: { id: '2', name: 'Sam Lee', email: 'sam@example.com' },
            status: 'authenticated',
        });
        vi.mocked(http.post).mockResolvedValue({ data: { data: { status: 'logged_out' } } });

        await session.logout();

        expect(requestCsrfCookie).toHaveBeenCalledOnce();
        expect(http.post).toHaveBeenCalledWith('/logout');
        expect(session.user).toBeNull();
        expect(session.status).toBe('guest');
    });
});
