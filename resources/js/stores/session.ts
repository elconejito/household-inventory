import { defineStore } from 'pinia';
import axios from 'axios';
import { ref } from 'vue';
import { http, requestCsrfCookie } from '../lib/http';

type HouseholdResource = {
    id: string;
    name: string;
};

type MembershipResource = {
    role: 'owner' | 'member';
    household?: HouseholdResource | null;
};

export type SessionUser = {
    id: string;
    name: string;
    email: string;
    membership?: MembershipResource | null;
};

type UserResponse = {
    data: SessionUser;
};

type Credentials = {
    email: string;
    password: string;
};

type RegistrationDetails = Credentials & {
    name: string;
    password_confirmation: string;
    household_name: string;
};

type SessionStatus = 'unknown' | 'loading' | 'authenticated' | 'guest';

export const useSessionStore = defineStore('session', () => {
    const user = ref<SessionUser | null>(null);
    const status = ref<SessionStatus>('unknown');
    let loadingCurrentUser: Promise<SessionUser | null> | null = null;

    function clearSession(): void {
        user.value = null;
        status.value = 'guest';
    }

    async function loadCurrentUser(): Promise<SessionUser | null> {
        try {
            const response = await http.get<UserResponse>('/user', {
                params: { include: 'membership.household' },
            });

            user.value = response.data.data;
            status.value = 'authenticated';

            return user.value;
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 401) {
                clearSession();

                return null;
            }

            status.value = 'unknown';
            throw error;
        }
    }

    async function ensureLoaded(): Promise<SessionUser | null> {
        if (status.value === 'authenticated') {
            return user.value;
        }

        if (status.value === 'guest') {
            return null;
        }

        if (loadingCurrentUser) {
            return loadingCurrentUser;
        }

        status.value = 'loading';
        loadingCurrentUser = loadCurrentUser();

        try {
            return await loadingCurrentUser;
        } finally {
            loadingCurrentUser = null;
        }
    }

    async function login(credentials: Credentials): Promise<void> {
        await requestCsrfCookie();
        await http.post('/login', { data: credentials });
        status.value = 'unknown';
        await loadCurrentUser();
    }

    async function register(details: RegistrationDetails): Promise<void> {
        await requestCsrfCookie();
        await http.post('/register', { data: details });
        status.value = 'unknown';
        await loadCurrentUser();
    }

    async function logout(): Promise<void> {
        await requestCsrfCookie();
        await http.post('/logout');
        clearSession();
    }

    return {
        user,
        status,
        ensureLoaded,
        login,
        register,
        logout,
    };
});
