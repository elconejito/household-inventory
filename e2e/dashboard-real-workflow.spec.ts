import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');

async function createResource(page: Page, path: string, data: Record<string, string | number | null>): Promise<{ id: string }> {
    const csrfCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrfCookie).toBeDefined();
    const response = await page.request.post(`/api/${path}`, {
        headers: {
            Accept: 'application/json',
            Origin: 'http://127.0.0.1:8000',
            Referer: 'http://127.0.0.1:8000/',
            'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value),
        },
        data: { data },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data;
}

test('finds stock and manages independent automatic and buy-soon attention states', async ({ page }, testInfo) => {
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Dashboard tissue ${uniqueId}`;
    const unrelatedName = `Unrelated batteries ${uniqueId}`;

    await page.goto('/register');
    await page.getByLabel('Your name').fill('Dashboard Tester');
    await page.getByLabel('Household name').fill(`Dashboard Home ${uniqueId}`);
    await page.getByLabel('Email address').fill(`dashboard-${uniqueId}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Dashboard-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Dashboard-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/');

    const item = await createResource(page, 'items', { name: itemName, counting_unit: 'pack' });
    await createResource(page, 'items', { name: unrelatedName, counting_unit: 'battery' });
    const basement = await createResource(page, 'locations', { name: 'Basement' });
    const pantry = await createResource(page, 'locations', { name: 'Pantry', parent_id: basement.id });
    const closet = await createResource(page, 'locations', { name: 'Closet' });
    await createResource(page, 'inventory-levels', { item_id: item.id, location_id: basement.id, alert_threshold: 2 });
    await createResource(page, 'inventory-levels', { item_id: item.id, location_id: pantry.id, alert_threshold: 0 });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: item.id, location_id: basement.id, quantity: 2 });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: item.id, location_id: closet.id, quantity: 4 });

    await page.reload();
    const emptyGroup = page.getByRole('region', { name: 'Out of stock', exact: true });
    const lowGroup = page.getByRole('region', { name: 'Low stock', exact: true });
    const manualGroup = page.getByRole('region', { name: 'Buy soon', exact: true });
    await expect(emptyGroup).toContainText('Basement / Pantry');
    await expect(lowGroup).toContainText('Basement');
    await expect(page.getByText(unrelatedName, { exact: true })).toHaveCount(0);

    await page.getByRole('searchbox', { name: 'Find an item', exact: true }).fill('Dashboard tissue');
    const result = page.getByRole('listitem').filter({ has: page.getByRole('link', { name: itemName, exact: true }) }).filter({ has: page.getByRole('button', { name: 'Consume 1', exact: true }) });
    await expect(result).toContainText('6 packs');
    await result.getByRole('button', { name: 'Consume 1', exact: true }).click();
    const locationPicker = page.getByLabel('Location', { exact: true });
    await expect(locationPicker.locator('option').filter({ hasText: 'Basement / Pantry' })).toHaveCount(0);
    await locationPicker.selectOption(basement.id);
    await page.getByLabel('Confirm this stock change at the selected location.').check();
    await page.getByRole('button', { name: 'Save stock change', exact: true }).click();
    await expect(page.locator('section[aria-labelledby="quick-action-title"]')).toBeHidden();
    await expect(result).toContainText('5 packs');

    await result.getByRole('button', { name: 'Buy soon', exact: true }).click();
    await expect(manualGroup).toContainText(itemName);
    await expect(result.getByRole('button', { name: 'Buy soon', exact: true })).toHaveCount(0);

    await result.getByRole('button', { name: 'Restock', exact: true }).click();
    await page.getByLabel('Location', { exact: true }).selectOption(pantry.id);
    await page.getByRole('spinbutton', { name: /Quantity/ }).fill('4');
    await page.getByLabel('Confirm this stock change at the selected location.').check();
    await page.getByRole('button', { name: 'Save stock change', exact: true }).click();
    await expect(page.locator('section[aria-labelledby="quick-action-title"]')).toBeHidden();
    await expect(emptyGroup).not.toContainText(itemName);
    await expect(manualGroup).toContainText(itemName);
    await expect(result).toContainText('9 packs');

    await page.screenshot({ path: testInfo.outputPath('dashboard-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: testInfo.outputPath('dashboard-mobile.png'), fullPage: true });
    await manualGroup.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(manualGroup).not.toContainText(itemName);
    await expect(result.getByRole('button', { name: 'Buy soon', exact: true })).toBeVisible();
});
