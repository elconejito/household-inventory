import { expect, test } from '@playwright/test';

test('redirects a guest to sign in and exposes account registration', async ({ page }) => {
    await page.route('**/api/user*', async (route) => {
        await route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: JSON.stringify({
                errors: [{
                    status: '401',
                    code: 'unauthenticated',
                    title: 'Unauthenticated',
                    detail: 'Authentication is required.',
                }],
            }),
        });
    });

    await page.goto('/');

    await expect(page).toHaveTitle('Household Inventory');
    await expect(page).toHaveURL(/\/login\?redirect=/);
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in to your home' })).toBeVisible();

    await page.getByRole('link', { name: 'Create an account' }).click();

    await expect(page).toHaveURL('/register');
    await expect(page.getByRole('heading', { level: 1, name: 'Create your household' })).toBeVisible();
});
