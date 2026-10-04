import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');

const apiHeaders = {
    Accept: 'application/json',
    Origin: 'http://127.0.0.1:8000',
    Referer: 'http://127.0.0.1:8000/',
};

async function registerHousehold(page: Page, suffix: string): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Association Tester');
    await page.getByLabel('Household name').fill(`Association Home ${suffix}`);
    await page.getByLabel('Email address').fill(`association-${suffix}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Association-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Association-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');
}

async function createResource(page: Page, path: string, data: Record<string, unknown>): Promise<{ id: string }> {
    const csrfCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrfCookie).toBeDefined();
    const response = await page.request.post(`/api/${path}`, {
        headers: { ...apiHeaders, 'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value) },
        data: { data },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data;
}

async function getResource(page: Page, path: string) {
    const response = await page.request.get(`/api/${path}`, { headers: apiHeaders });
    expect(response.status(), await response.text()).toBe(200);
    return (await response.json()).data;
}

test('sets up zero-stock parent and child locations with explicit monitoring and no movements', async ({ page }, testInfo) => {
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Spare paper towels ${suffix}`;
    await registerHousehold(page, suffix);
    const item = await createResource(page, 'items', { name: itemName, counting_unit: 'roll' });
    const basement = await createResource(page, 'locations', { name: 'Basement' });
    const shelf = await createResource(page, 'locations', { name: 'Shelf', parent_id: basement.id });
    const movementRequests: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/inventory-movements') {
            movementRequests.push(request.url());
        }
    });

    await page.goto(`/inventory/${item.id}`);
    await page.getByRole('button', { name: 'Set up a stock location', exact: true }).click();
    await page.locator('#setup-location').selectOption({ label: 'Basement' });
    await expect(page.locator('#setup-threshold')).toHaveValue('');
    await page.screenshot({ path: testInfo.outputPath('zero-stock-setup-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('zero-stock-setup-mobile.png'), fullPage: true });
    const unmonitoredResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/inventory-levels');
    await page.getByRole('button', { name: 'Set up location', exact: true }).click();
    const unmonitored = await unmonitoredResponse;
    expect(unmonitored.status()).toBe(201);
    expect(unmonitored.request().postDataJSON()).toEqual({ data: { item_id: item.id, location_id: basement.id, alert_threshold: null } });
    const stock = page.getByRole('region', { name: 'Stock by location', exact: true });
    const basementRow = stock.getByRole('listitem').filter({ has: page.getByRole('link', { name: 'Basement', exact: true }) });
    await expect(basementRow).toContainText('0 rolls');
    await expect(basementRow).toContainText('Unmonitored');
    await expect(basementRow.getByRole('button', { name: 'Use 1', exact: true })).toHaveCount(0);

    await page.getByRole('button', { name: 'Set up a stock location', exact: true }).click();
    await expect(page.locator('#setup-location').getByRole('option', { name: 'Basement', exact: true })).toHaveCount(0);
    await page.locator('#setup-location').selectOption({ label: 'Basement / Shelf' });
    await page.locator('#setup-threshold').fill('0');
    const monitoredResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/inventory-levels');
    await page.getByRole('button', { name: 'Set up location', exact: true }).click();
    const monitored = await monitoredResponse;
    expect(monitored.status()).toBe(201);
    expect(monitored.request().postDataJSON()).toEqual({ data: { item_id: item.id, location_id: shelf.id, alert_threshold: 0 } });
    const shelfRow = stock.getByRole('listitem').filter({ has: page.getByRole('link', { name: 'Basement / Shelf', exact: true }) });
    await expect(shelfRow).toContainText('Monitored');
    await expect(shelfRow).toContainText('Empty');
    await expect(stock.getByRole('button', { name: 'Use 1', exact: true })).toHaveCount(0);
    expect(movementRequests).toHaveLength(0);

    const savedItem = await getResource(page, `items/${item.id}?include=inventory_levels.location`);
    expect(savedItem.total_quantity).toBe(0);
    expect(savedItem.inventory_levels).toHaveLength(2);
    expect(savedItem.inventory_levels).toEqual(expect.arrayContaining([
        expect.objectContaining({ quantity: 0, alert_threshold: null, location: expect.objectContaining({ id: basement.id }) }),
        expect.objectContaining({ quantity: 0, alert_threshold: 0, location: expect.objectContaining({ id: shelf.id }) }),
    ]));
    expect(await getResource(page, `inventory-movements?filter[item_id]=${item.id}`)).toHaveLength(0);
    const alerts = await getResource(page, `inventory-levels?filter[item_id]=${item.id}&filter[alert_status]=empty&include=location`);
    expect(alerts).toHaveLength(1);
    expect(alerts[0].location.id).toBe(shelf.id);
    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Dashboard', exact: true }).click();
    const emptyGroup = page.getByRole('region', { name: 'Out of stock', exact: true });
    await expect(emptyGroup.getByRole('listitem')).toHaveCount(1);
    await expect(emptyGroup).toContainText('Basement / Shelf');
    await expect(emptyGroup).toContainText(itemName);
});

test('adds and removes one category assignment without deleting the item or changing other categories', async ({ page }, testInfo) => {
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Garden hose 10 ft Blue ${suffix}`;
    await registerHousehold(page, suffix);
    const supplies = await createResource(page, 'categories', { name: 'Garden supplies' });
    const seasonal = await createResource(page, 'categories', { name: 'Seasonal' });
    const item = await createResource(page, 'items', { name: itemName, counting_unit: 'hose', category_ids: [seasonal.id] });

    await page.goto(`/categories/${supplies.id}`);
    const assignments = page.getByRole('region', { name: 'Add items to this category', exact: true });
    await assignments.getByLabel('Search inventory items', { exact: true }).fill('not-a-matching-item');
    await expect(assignments).toContainText('No items match that search.');
    await assignments.getByLabel('Search inventory items', { exact: true }).fill('Garden hose');
    await assignments.getByLabel('Item to add', { exact: true }).selectOption({ label: itemName });
    await page.screenshot({ path: testInfo.outputPath('category-assignment-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('category-assignment-mobile.png'), fullPage: true });
    const assignmentPath = `/api/categories/${supplies.id}/items/${item.id}`;
    const addResponse = page.waitForResponse((response) => response.request().method() === 'PATCH' && new URL(response.url()).pathname === assignmentPath);
    await assignments.getByRole('button', { name: 'Add to category', exact: true }).click();
    const added = await addResponse;
    expect(added.status()).toBe(200);
    expect(added.request().postDataJSON()).toEqual({ data: { assigned: true } });
    const categoryItems = page.getByRole('region', { name: 'Items in this category', exact: true });
    const itemRow = categoryItems.getByRole('listitem').filter({ has: page.getByRole('link', { name: itemName, exact: true }) });
    await expect(itemRow).toBeVisible();
    const savedAfterAdd = await getResource(page, `items/${item.id}?include=categories`);
    expect(savedAfterAdd.categories.map((category: { id: string }) => category.id).sort()).toEqual([supplies.id, seasonal.id].sort());

    const removeResponse = page.waitForResponse((response) => response.request().method() === 'PATCH' && new URL(response.url()).pathname === assignmentPath);
    await itemRow.getByRole('button', { name: 'Remove from category', exact: true }).click();
    const removed = await removeResponse;
    expect(removed.status()).toBe(200);
    expect(removed.request().postDataJSON()).toEqual({ data: { assigned: false } });
    await expect(categoryItems.getByRole('link', { name: itemName, exact: true })).toHaveCount(0);
    const savedAfterRemove = await getResource(page, `items/${item.id}?include=categories`);
    expect(savedAfterRemove).toMatchObject({ id: item.id, name: itemName, total_quantity: 0 });
    expect(savedAfterRemove.categories).toHaveLength(1);
    expect(savedAfterRemove.categories[0].id).toBe(seasonal.id);
    expect(await getResource(page, `inventory-movements?filter[item_id]=${item.id}`)).toHaveLength(0);
    await page.screenshot({ path: testInfo.outputPath('category-assignment-removed-mobile.png'), fullPage: true });
});
