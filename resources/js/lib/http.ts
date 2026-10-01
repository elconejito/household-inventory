import axios from 'axios';

export const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export function requestCsrfCookie(): Promise<void> {
    return axios.get('/sanctum/csrf-cookie', {
        withCredentials: true,
        withXSRFToken: true,
    }).then(() => undefined);
}
