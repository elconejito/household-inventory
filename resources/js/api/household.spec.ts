import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http, requestCsrfCookie } from '../lib/http';
import { acceptInvitation, getInvitations } from './household';

vi.mock('../lib/http', () => ({
    http: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    requestCsrfCookie: vi.fn(),
}));

describe('household invitation API', () => {
    beforeEach(() => vi.clearAllMocks());

    it('sends the acceptance include as query parameters, not request data', async () => {
        const user = { type: 'users', id: '7', name: 'Sam', email: 'sam@example.com' };
        vi.mocked(http.post).mockResolvedValue({ data: { data: user } } as never);

        await acceptInvitation({ token: 'invite-secret', name: 'Sam', email: 'sam@example.com', password: 'test-password', password_confirmation: 'test-password' });

        expect(requestCsrfCookie).toHaveBeenCalledOnce();
        expect(http.post).toHaveBeenCalledWith('/household-invitations/accept', {
            data: { token: 'invite-secret', name: 'Sam', email: 'sam@example.com', password: 'test-password', password_confirmation: 'test-password' },
        }, { params: { include: 'membership.household' } });
    });

    it('requests a single invitations page at the supported page size', async () => {
        const result = { data: [], meta: { current_page: 3, last_page: 4, total: 31 } };
        vi.mocked(http.get).mockResolvedValue({ data: result } as never);

        await expect(getInvitations(3)).resolves.toEqual(result);

        expect(http.get).toHaveBeenCalledWith('/household-invitations', { params: { per_page: 10, page: 3 } });
    });
});
