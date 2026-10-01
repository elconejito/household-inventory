import { AxiosError } from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { installSessionGuards, routes } from './index';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
    },
    requestCsrfCookie: vi.fn(),
}));

describe('session route guards', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('redirects unauthenticated visitors to login and remembers the protected path', async () => {
        const unauthorized = new AxiosError('Unauthenticated');
        Object.defineProperty(unauthorized, 'response', { value: { status: 401 } });
        vi.mocked(http.get).mockRejectedValue(unauthorized);
        const router = createRouter({ history: createMemoryHistory(), routes });
        installSessionGuards(router);

        await router.push('/inventory');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/inventory');
        expect(http.get).toHaveBeenCalledWith('/user', {
            params: { include: 'membership.household' },
        });
    });

    it('redirects an authenticated visitor away from public auth pages', async () => {
        vi.mocked(http.get).mockResolvedValue({
            data: { data: { id: '3', name: 'Taylor Home', email: 'taylor@example.com' } },
        });
        const router = createRouter({ history: createMemoryHistory(), routes });
        installSessionGuards(router);

        await router.push('/register');

        expect(router.currentRoute.value.name).toBe('dashboard');
        expect(http.get).toHaveBeenCalledTimes(1);
    });
});
