import { expect, test } from '@playwright/test';

test('loads the dashboard and navigates through the application shell', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveTitle('Household Inventory');
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();

    const inventoryLink = page.getByRole('link', { name: 'Inventory', exact: true });

    await expect(inventoryLink).toBeVisible();
    await inventoryLink.click();

    await expect(page).toHaveURL('/inventory');
    await expect(page.getByRole('heading', { level: 1, name: 'Inventory' })).toBeVisible();
});
