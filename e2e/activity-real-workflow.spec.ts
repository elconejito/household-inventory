import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');
test.use({ timezoneId: 'America/Los_Angeles' });

async function createResource(page: Page, path: string, data: Record<string, string | number | null>): Promise<{ id: string }> {
    const cookie = (await page.context().cookies()).find((candidate) => candidate.name === 'XSRF-TOKEN');
    expect(cookie).toBeDefined();
    const response = await page.request.post(`/api/${path}`, {
        headers: {
            Accept: 'application/json',
            Origin: 'http://127.0.0.1:8000',
            Referer: 'http://127.0.0.1:8000/',
            'X-XSRF-TOKEN': decodeURIComponent(cookie!.value),
        },
        data: { data },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data;
}

test('connects five recent item movements to linkable, paginated and locally dated Activity filters', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    await page.context().setDefaultTimeout(10_000);
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Kitchen towel ${suffix}`;
    const otherName = `Spare filter ${suffix}`;
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Activity Tester');
    await page.getByLabel('Household name').fill(`Activity Home ${suffix}`);
    await page.getByLabel('Email address').fill(`activity-${suffix}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Activity-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Activity-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');

    const item = await createResource(page, 'items', { name: itemName, counting_unit: 'roll' });
    const other = await createResource(page, 'items', { name: otherName, counting_unit: 'filter' });
    const basement = await createResource(page, 'locations', { name: 'Basement' });
    const pantry = await createResource(page, 'locations', { name: 'Pantry' });
    for (let index = 0; index < 12; index++) {
        await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: item.id, location_id: basement.id, quantity: 1 });
    }
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: other.id, location_id: basement.id, quantity: 1 });
    await createResource(page, 'inventory-movements', { movement_type: 'transfer', item_id: item.id, source_location_id: basement.id, destination_location_id: pantry.id, quantity: 3 });

    await page.goto(`/inventory/${item.id}`);
    const recent = page.getByRole('region', { name: 'Recent activity', exact: true });
    await expect(recent.locator('article')).toHaveCount(5);
    await expect(recent).toContainText('Transfer');
    await expect(recent).not.toContainText(otherName);
    const basementStock = page.getByRole('region', { name: 'Stock by location', exact: true }).getByRole('listitem').filter({
        has: page.getByRole('heading', { name: 'Basement', exact: true }),
    });
    await basementStock.getByRole('button', { name: 'Use 1', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Used 1 roll from Basement.' })).toBeVisible();
    await expect(recent.locator('article').first()).toContainText('Consumption');
    await expect(recent.locator('article')).toHaveCount(5);
    await page.screenshot({ path: testInfo.outputPath('item-recent-activity-desktop.png'), fullPage: true });

    await recent.getByRole('link', { name: 'View all activity', exact: true }).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('item_id')).toBe(item.id);
    await expect(page.getByLabel('Item', { exact: true })).toHaveValue(item.id);
    const activity = page.getByRole('region', { name: 'Inventory activity', exact: true });
    await expect(activity.locator('article')).toHaveCount(10);
    await expect(activity).not.toContainText(otherName);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(activity.locator('article')).toHaveCount(4);
    await expect.poll(() => new URL(page.url()).searchParams.get('page')).toBe('2');
    await page.getByLabel('Movements per page', { exact: true }).selectOption('25');
    await expect(activity.locator('article')).toHaveCount(14);
    expect(new URL(page.url()).searchParams.get('page')).not.toBe('2');

    await page.getByLabel('Recorded by', { exact: true }).selectOption({ label: 'Activity Tester' });
    const today = await page.evaluate(() => {
        const current = new Date();
        return `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, '0')}-${String(current.getDate()).padStart(2, '0')}`;
    });
    await page.getByLabel('From date', { exact: true }).fill(today);
    const datedRequest = page.waitForRequest((request) => request.method() === 'GET'
        && request.url().includes('/api/inventory-movements?')
        && new URL(request.url()).searchParams.has('filter[recorded_until]'));
    await page.getByLabel('Until date', { exact: true }).fill(today);
    const dateParams = new URL((await datedRequest).url()).searchParams;
    expect(dateParams.get('filter[recorded_from]')).toMatch(/T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
    expect(dateParams.get('filter[recorded_until]')).toMatch(/T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
    await expect(activity.locator('article')).toHaveCount(14);
    await page.getByLabel('Change type', { exact: true }).selectOption('transfer');
    await expect(activity.locator('article')).toHaveCount(1);
    await expect(activity).toContainText('-3 rolls');
    await expect(activity).toContainText('+3 rolls');
    await page.reload();
    await expect(page.getByLabel('Item', { exact: true })).toHaveValue(item.id);
    await expect(page.getByLabel('From date', { exact: true })).toHaveValue(today);
    await expect(page.getByLabel('Until date', { exact: true })).toHaveValue(today);
    await expect(page.getByLabel('Movements per page', { exact: true })).toHaveValue('25');
    await expect(activity.locator('article')).toHaveCount(1);
    await page.getByLabel('Change type', { exact: true }).selectOption('consumption');
    await expect(activity.locator('article').first()).toContainText('Consumption');
    await page.goBack();
    await expect(page.getByLabel('Change type', { exact: true })).toHaveValue('transfer');
    await expect(activity.locator('article').first()).toContainText('Transfer');
    await page.screenshot({ path: testInfo.outputPath('activity-filters-desktop.png'), fullPage: true });

    await page.setViewportSize({ width: 320, height: 740 });
    await expect(activity.locator('article')).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('activity-filters-mobile.png'), fullPage: true });
});
