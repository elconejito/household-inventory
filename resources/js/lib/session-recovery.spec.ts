import axios, { AxiosError, AxiosHeaders } from 'axios';
import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { describe, expect, it, vi } from 'vitest';
import { installSessionRecovery } from './session-recovery';

function response(config: InternalAxiosRequestConfig): AxiosResponse {
    return { config, data: {}, status: 200, statusText: 'OK', headers: new AxiosHeaders() };
}

function unauthorized(config: InternalAxiosRequestConfig, status = 401): never {
    throw new AxiosError('Unauthenticated', 'ERR_BAD_REQUEST', config, undefined, {
        ...response(config), status,
    });
}

function setup(recover = vi.fn(async () => true)) {
    const attempts = new Map<string, number>();
    const client = axios.create({ adapter: async (config) => {
        const count = attempts.get(config.url!) ?? 0;
        attempts.set(config.url!, count + 1);
        if (!count) {
            unauthorized(config);
        }
        return response(config);
    } });
    const expired = vi.fn(async () => undefined);
    installSessionRecovery(client, recover, expired);
    return { client, recover, expired, attempts };
}

describe('session recovery', () => {
    it('shares one recovery across simultaneous failures and retries each request', async () => {
        const { client, recover, expired, attempts } = setup();
        await Promise.all([client.get('/items'), client.get('/locations'), client.patch('/items/1', { name: 'Updated' })]);
        expect(recover).toHaveBeenCalledTimes(1);
        expect([...attempts.values()]).toEqual([2, 2, 2]);
        expect(expired).not.toHaveBeenCalled();
    });

    it('redirects once when recovery fails and does not retry queued requests', async () => {
        const { client, recover, expired, attempts } = setup(vi.fn(async () => false));
        const results = await Promise.allSettled([client.get('/items'), client.get('/locations')]);
        expect(results.every((result) => result.status === 'rejected')).toBe(true);
        expect(recover).toHaveBeenCalledTimes(1);
        expect(expired).toHaveBeenCalledTimes(1);
        expect([...attempts.values()]).toEqual([1, 1]);
        await expect(client.get('/categories')).rejects.toThrow();
        expect(recover).toHaveBeenCalledTimes(1);
    });

    it('redirects once if the recovery request rejects', async () => {
        const { client, recover, expired } = setup(vi.fn(async () => { throw new Error('Offline'); }));
        await Promise.allSettled([client.get('/items'), client.get('/locations')]);
        expect(recover).toHaveBeenCalledTimes(1);
        expect(expired).toHaveBeenCalledTimes(1);
    });

    it('retries a late 401 with the recovered session without another recovery', async () => {
        const { client, recover } = setup();
        let release: () => void = () => undefined;
        const delayed = new Promise<void>((resolve) => { release = resolve; });
        const attempts = new Map<string, number>();
        client.defaults.adapter = async (config) => {
            const count = attempts.get(config.url!) ?? 0;
            attempts.set(config.url!, count + 1);
            if (!count) {
                if (config.url === '/locations') {
                    await delayed;
                }
                unauthorized(config);
            }
            return response(config);
        };
        const late = client.get('/locations');
        await client.get('/items');
        release();
        await late;
        expect(recover).toHaveBeenCalledTimes(1);
    });

    it('does not loop when a retried request is still unauthorized', async () => {
        const { client, recover, expired } = setup();
        client.defaults.adapter = async (config) => unauthorized(config);
        await expect(client.get('/items')).rejects.toThrow();
        expect(recover).toHaveBeenCalledTimes(1);
        expect(expired).toHaveBeenCalledTimes(1);
    });

    it.each(['/login', '/register', '/user', '/logout'])('does not intercept the session endpoint %s', async (url) => {
        const { client, recover, expired } = setup();
        await expect(client.get(url)).rejects.toThrow();
        expect(recover).not.toHaveBeenCalled();
        expect(expired).not.toHaveBeenCalled();
    });

    it('allows recovery again after a successful login', async () => {
        const { client, recover, expired } = setup(vi.fn(async () => false));
        await expect(client.get('/items')).rejects.toThrow();
        await expect(client.post('/login')).rejects.toThrow();
        await client.post('/login');
        recover.mockResolvedValue(true);
        await client.get('/locations');
        expect(recover).toHaveBeenCalledTimes(2);
        expect(expired).toHaveBeenCalledTimes(1);
    });

    it('recovers expired CSRF tokens too', async () => {
        const { client, recover } = setup();
        let attempts = 0;
        client.defaults.adapter = async (config) => {
            if (attempts++ === 0) {
                unauthorized(config, 419);
            }
            return response(config);
        };
        await client.post('/items');
        expect(recover).toHaveBeenCalledTimes(1);
    });
});
