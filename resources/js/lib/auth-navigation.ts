export function postAuthenticationPath(redirect: unknown): string {
    if (typeof redirect !== 'string' || !redirect.startsWith('/') || redirect.startsWith('//')) {
        return '/';
    }

    return redirect;
}
