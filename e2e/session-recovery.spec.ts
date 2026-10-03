import { expect, test } from '@playwright/test';

test('recovers an initial session failure without loading protected data or losing the requested page', async ({ page }) => {
    let sessionChecks = 0;
    let protectedRequests = 0;
    await page.route('**/api/user?**', async (route) => {
        sessionChecks++;
        if (sessionChecks === 1) {
            await route.abort('failed');
            return;
        }
        await route.fulfill({ json: { data: {
            type: 'users', id: '1', name: 'Recovery Owner', email: 'recovery@example.test',
            membership: { type: 'memberships', id: '1', role: 'owner', household: { type: 'households', id: '1', name: 'Recovery Home' } },
        } } });
    });
    await page.route('**/api/items?**', async (route) => {
        protectedRequests++;
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null } } });
    });

    await page.goto('/inventory?create=1');
    await expect(page).toHaveURL('/session-unavailable');
    await expect(page.getByRole('heading', { name: 'We couldn’t check your session', exact: true })).toBeVisible();
    expect(protectedRequests).toBe(0);
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Retry', exact: true }).focus();
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/inventory?create=1');
    await expect(page.getByRole('heading', { name: 'Add an item', exact: true })).toBeVisible();
    await expect.poll(() => protectedRequests).toBe(1);
    expect(sessionChecks).toBe(2);
});

test('recovers an invitation session check while keeping its token out of the recovery URL', async ({ page }) => {
    const token = 'session-recovery-invitation-secret';
    let sessionChecks = 0;
    await page.route('**/api/user?**', async (route) => {
        sessionChecks++;
        if (sessionChecks === 1) {
            await route.abort('failed');
            return;
        }
        await route.fulfill({ status: 401, json: { errors: [{ status: '401', code: 'unauthenticated', detail: 'Authentication is required.' }] } });
    });

    await page.goto(`/invitations/accept#token=${token}`);
    await expect(page).toHaveURL('/session-unavailable');
    expect(page.url()).not.toContain(token);
    await page.getByRole('button', { name: 'Retry', exact: true }).click();

    await expect(page.getByLabel('Your name', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create account and join', exact: true })).toBeVisible();
    await expect(page).toHaveURL('/invitations/accept');
    expect(sessionChecks).toBe(2);
    expect(await page.evaluate((secret) => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }).includes(secret), token)).toBe(false);
});
