import { expect, test } from '@playwright/test';

test('recovers an initial session failure without loading protected data or losing the requested page', async ({ page }, testInfo) => {
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
    await page.route('**/api/categories?**', async (route) => {
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } } });
    });
    await page.route('**/api/locations?**', async (route) => {
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } } });
    });

    await page.goto('/items?create=1');
    await expect(page).toHaveURL('/session-unavailable');
    await expect(page.getByRole('heading', { name: 'We couldn’t check your session', exact: true })).toBeVisible();
    expect(protectedRequests).toBe(0);
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Retry', exact: true }).focus();
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL('/items?create=1');
    await expect(page.getByRole('heading', { name: 'Add item', exact: true })).toBeVisible();
    await expect.poll(() => protectedRequests).toBe(1);
    expect(sessionChecks).toBe(2);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Add item' })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 740 });
    const mobileNavigation = page.getByRole('navigation', { name: 'Mobile navigation', exact: true });
    await expect(mobileNavigation.getByRole('link')).toHaveCount(3);
    await expect(mobileNavigation.getByRole('link', { name: 'Home', exact: true })).toBeVisible();
    await expect(mobileNavigation.getByRole('link', { name: 'Items', exact: true })).toBeVisible();
    await expect(mobileNavigation.getByRole('link', { name: 'Locations', exact: true })).toBeVisible();
    await mobileNavigation.getByText('More', { exact: true }).click();
    await expect(mobileNavigation.getByRole('link', { name: 'Categories', exact: true })).toBeVisible();
    await expect(mobileNavigation.getByRole('link', { name: 'Activity', exact: true })).toBeVisible();
    await expect(mobileNavigation.getByRole('link', { name: 'Account & settings', exact: true })).toBeVisible();
    const linkBounds = await mobileNavigation.getByRole('link').evaluateAll((links) => links.map((link) => {
        const bounds = link.getBoundingClientRect();
        return { left: bounds.left, right: bounds.right, width: bounds.width, height: bounds.height };
    }));
    for (const bounds of linkBounds) {
        expect(bounds.left).toBeGreaterThanOrEqual(0);
        expect(bounds.right).toBeLessThanOrEqual(320);
        expect(bounds.width).toBeGreaterThanOrEqual(44);
        expect(bounds.height).toBeGreaterThanOrEqual(44);
    }
    await page.locator('.account-trigger').click();
    await expect(page.getByRole('link', { name: 'Settings', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('recovered-inventory-320.png'), fullPage: true });

    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 768, height: 800 });
    const tabletNavigation = page.getByRole('navigation', { name: 'Main navigation' });
    await expect(tabletNavigation.getByRole('link')).toHaveText(['Dashboard', 'Items', 'Locations', 'Categories', 'Activity']);
    const searchButton = page.getByRole('button', { name: 'Search all', exact: true });
    await expect(searchButton).toBeVisible();
    await searchButton.click();
    const searchDialog = page.getByRole('dialog', { name: 'Search all' });
    await expect(searchDialog.getByRole('searchbox')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(searchButton).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(768);
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
