import axios from 'axios';
import type { AxiosInstance, InternalAxiosRequestConfig } from 'axios';

type RecoveryRequest = InternalAxiosRequestConfig & {
    sessionGeneration?: number;
    sessionRetried?: boolean;
};

export function installSessionRecovery(
    client: AxiosInstance,
    recover: () => Promise<boolean>,
    onExpired: () => Promise<unknown>,
): void {
    let generation = 0;
    let recovery: Promise<boolean> | null = null;
    let expired = false;
    const sessionEndpoints = new Set(['/user', '/login', '/register', '/logout']);

    client.interceptors.request.use((config: RecoveryRequest) => {
        config.sessionGeneration = generation;

        return config;
    });

    client.interceptors.response.use((response) => {
        if (response.config.url === '/login' || response.config.url === '/register') {
            expired = false;
            generation++;
        }

        return response;
    }, async (error: unknown) => {
        if (!axios.isAxiosError(error) || !error.config || ![401, 419].includes(error.response?.status ?? 0)) {
            throw error;
        }

        const config = error.config as RecoveryRequest;
        if (sessionEndpoints.has(config.url ?? '')) {
            throw error;
        }

        async function expire(): Promise<void> {
            if (!expired) {
                expired = true;
                await onExpired();
            }
        }

        if (expired) {
            throw error;
        }

        if (config.sessionRetried) {
            await expire();
            throw error;
        }

        if (config.sessionGeneration === generation) {
            if (!recovery) {
                recovery = (async () => {
                    try {
                        const restored = await recover();
                        if (restored) {
                            generation++;
                        } else {
                            await expire();
                        }
                        return restored;
                    } catch {
                        await expire();
                        return false;
                    } finally {
                        recovery = null;
                    }
                })();
            }

            if (!await recovery) {
                throw error;
            }
        }

        config.sessionRetried = true;
        return client.request(config);
    });
}
