import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');

async function registerHousehold(page: Page, suffix: string): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Catalog Tester');
    await page.getByLabel('Household name').fill(`Catalog Home ${suffix}`);
    await page.getByLabel('Email address').fill(`catalog-${suffix}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Catalog-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Catalog-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');
}

async function addCategory(page: Page, name: string): Promise<void> {
    await page.goto('/categories');
    await page.getByRole('button', { name: 'Add category', exact: true }).first().click();
    await page.getByLabel(/^Category name/).fill(name);
    await page.getByRole('button', { name: 'Save category', exact: true }).click();
    await expect(page.getByRole('link', { name: new RegExp(`^${name}`) })).toBeVisible();
}

async function addLocation(page: Page, name: string, parent?: string): Promise<void> {
    await page.goto('/locations');
    await page.getByRole('button', { name: 'Add location', exact: true }).first().click();
    await page.getByLabel(/^Location name/).fill(name);
    if (parent) await page.getByLabel(/^Parent location/).selectOption({ label: parent });
    await page.getByRole('button', { name: 'Save location', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: `${name} was created.` })).toBeVisible();
}

async function setUpAndRestock(page: Page, locationId: string, itemId: string, quantity: number): Promise<void> {
    await page.goto(`/items/${itemId}`);
    await page.getByRole('button', { name: 'Add stock location', exact: true }).click();
    const setup = page.getByRole('dialog', { name: 'Add stock location', exact: true });
    await setup.getByLabel('Location', { exact: true }).selectOption(locationId);
    await setup.getByLabel(/Quantity here/).fill('0');
    await setup.getByRole('button', { name: 'Add stock location', exact: true }).click();
    await expect(setup).not.toBeVisible();
    const row = page.getByRole('region', { name: 'Stock by location', exact: true }).getByRole('listitem').filter({ has: page.locator(`a[href="/locations/${locationId}"]`) });
    await row.getByRole('button', { name: 'Restock', exact: true }).click();
    const action = page.getByRole('dialog', { name: 'Change stock', exact: true });
    await action.getByLabel(/Quantity/).fill(String(quantity));
    await action.getByLabel('I’ve checked this direction and quantity.').check();
    await action.getByRole('button', { name: 'Save change', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Stock updated.' })).toBeVisible();
}

test('creates and edits a catalog, assigns equal categories, and distinguishes parent stock from descendants', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Garden hose 10 ft Blue ${suffix}`;
    await registerHousehold(page, suffix);
    await addCategory(page, 'Garden supplies');
    await addCategory(page, 'Seasonal');
    await addLocation(page, 'Basement');
    await addLocation(page, 'Garage');
    await addLocation(page, 'Shelf', 'Basement');
    await addLocation(page, 'Bin', 'Basement / Shelf');

    const locationsResponse = await page.request.get('/api/locations?include=parent&per_page=100', {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(locationsResponse.ok()).toBe(true);
    const locations = (await locationsResponse.json()).data as Array<{ id: string; name: string }>;
    const basementId = locations.find((location) => location.name === 'Basement')!.id;
    const garageId = locations.find((location) => location.name === 'Garage')!.id;
    const shelfId = locations.find((location) => location.name === 'Shelf')!.id;
    const binId = locations.find((location) => location.name === 'Bin')!.id;

    await page.goto('/locations');
    await page.getByRole('list', { name: 'Locations', exact: true }).getByRole('link', { name: /^Basement/ }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Basement', exact: true })).toBeVisible();
    await page.getByRole('list', { name: 'Sublocations', exact: true }).getByRole('link', { name: 'Shelf', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Shelf', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Breadcrumb', exact: true }).getByRole('link', { name: 'Basement', exact: true })).toBeVisible();
    const extraBinName = `Cleaning Bin ${suffix}`;
    await page.getByRole('link', { name: 'Add sublocation', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Add location', exact: true })).toBeVisible();
    await page.getByLabel(/^Location name/).fill(extraBinName);
    await expect(page.getByLabel(/^Parent location/)).toHaveValue(shelfId);
    await page.getByRole('button', { name: 'Save location', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: `${extraBinName} was created.` })).toBeVisible();

    await page.getByRole('navigation', { name: 'Main navigation' }).first().getByRole('link', { name: 'Items', exact: true }).click();
    await page.getByRole('button', { name: 'Add item', exact: true }).click();
    await page.getByRole('textbox', { name: /^Name/ }).fill(itemName);
    await page.getByRole('textbox', { name: /^Counting unit/ }).fill('hose');
    await page.getByText('Categories, description and photo', { exact: true }).click();
    await page.getByRole('checkbox', { name: 'Garden supplies', exact: true }).check();
    await page.getByRole('checkbox', { name: 'Seasonal', exact: true }).check();
    await page.getByRole('button', { name: 'Create item', exact: true }).click();
    await expect(page.getByRole('heading', { level: 2, name: itemName, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Open item', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: itemName, exact: true })).toBeVisible();
    const itemUrl = page.url().split('?')[0]!;
    expect(itemUrl).toMatch(/^.*\/items\/\d+$/);
    const itemId = itemUrl.split('/').at(-1)!;

    await page.getByLabel('More item actions', { exact: true }).click();
    await page.getByRole('button', { name: 'Edit item', exact: true }).click();
    await expect(page.getByText(/at all locations; it does not convert/i)).toBeVisible();
    await page.getByLabel('Description', { exact: false }).fill('Blue connector\nKeep with the outdoor tap.');
    const descriptionPatch = page.waitForRequest((request) => request.method() === 'PATCH' && request.url().includes('/api/items/'));
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await descriptionPatch).postDataJSON()).toEqual({ data: { description: 'Blue connector\nKeep with the outdoor tap.' } });
    await page.getByText('Item details', { exact: true }).click();
    await expect(page.getByText('Blue connector\nKeep with the outdoor tap.', { exact: true })).toBeVisible();

    await setUpAndRestock(page, basementId, itemId, 6);
    await setUpAndRestock(page, binId, itemId, 4);
    await page.goto(`/locations/${basementId}`);
    const stock = page.getByRole('region', { name: 'Items stored directly here', exact: true });
    await expect(page.getByRole('region', { name: 'Sublocations', exact: true })).toContainText('Shelf');
    await expect(stock).toContainText('6 hoses');
    await expect(stock).not.toContainText('4 hoses');
    await expect(stock.getByRole('link', { name: 'Bin', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Inventory scope', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Add items here', exact: true })).toHaveAttribute('href', `/locations/${basementId}/intake`);
    await expect(stock.getByRole('link', { name: 'Check shelf quantities', exact: true })).toHaveAttribute('href', `/locations/${basementId}/count`);
    await expect(page.getByRole('link', { name: 'View location inventory', exact: true })).toHaveAttribute('href', `/items?location=${basementId}`);
    await page.getByRole('link', { name: 'Add items here', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Add items here', exact: true })).toBeVisible();
    await page.goto(`/locations/${basementId}`);
    await stock.getByRole('link', { name: 'Check shelf quantities', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Count this shelf', exact: true })).toBeVisible();
    await page.goto(`/locations/${basementId}`);
    await page.getByRole('link', { name: 'View location inventory', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/items\\?location=${basementId}$`));
    await expect(page.getByRole('heading', { level: 2, name: 'Items at Basement', exact: true })).toBeVisible();
    await page.goto(`/locations/${basementId}`);
    await page.screenshot({ path: testInfo.outputPath('location-direct-stock-desktop.png'), fullPage: true });

    await page.goto(`/locations/${binId}`);
    const binStock = stock.getByRole('listitem').filter({ hasText: itemName });
    const consumptionRequest = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname === '/api/inventory-movements');
    await binStock.getByRole('button', { name: 'Use 1', exact: true }).click();
    expect((await consumptionRequest).postDataJSON()).toEqual({ data: { movement_type: 'consumption', item_id: itemId, location_id: binId, quantity: 1 } });
    await expect(binStock).toContainText('3 hoses');

    await binStock.getByRole('button', { name: `More stock actions for ${itemName}`, exact: true }).click();
    const actionMenu = page.getByRole('dialog').filter({ hasText: itemName });
    await actionMenu.getByRole('button', { name: /^Move out/ }).click();
    const stockAction = page.getByRole('dialog', { name: /Move/ }).first();
    await stockAction.getByLabel('Move to', { exact: true }).selectOption({ label: 'Basement' });
    await stockAction.getByLabel(/Quantity/).fill('2');
    await expect(stockAction).toContainText('Basement / Shelf / Bin');
    await expect(stockAction).toContainText('3 → 1 hose');
    await expect(stockAction).toContainText('6 → 8 hoses');
    const pushRequest = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname === '/api/inventory-movements');
    await stockAction.getByRole('button', { name: /Move 2/ }).click();
    expect((await pushRequest).postDataJSON()).toEqual({ data: { movement_type: 'transfer', item_id: itemId, source_location_id: binId, destination_location_id: basementId, quantity: 2 } });
    await expect(binStock).toContainText('1 hose');
    await page.getByRole('dialog', { name: 'Transfer saved', exact: true }).getByRole('button', { name: 'Done', exact: true }).click();

    await binStock.getByRole('button', { name: `More stock actions for ${itemName}`, exact: true }).click();
    await page.getByRole('dialog').filter({ hasText: itemName }).getByRole('button', { name: /^Move in/ }).click();
    const moveIn = page.getByRole('dialog', { name: /Move/ }).first();
    await moveIn.getByLabel('Move from', { exact: true }).selectOption(basementId);
    await expect(moveIn.getByLabel(/Quantity/)).toHaveAttribute('max', '8');
    await expect(moveIn).toContainText('8 → 7 hoses');
    await expect(moveIn).toContainText('1 → 2 hoses');
    const pullRequest = page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname === '/api/inventory-movements');
    await moveIn.getByRole('button', { name: /Move 1/ }).click();
    expect((await pullRequest).postDataJSON()).toEqual({ data: { movement_type: 'transfer', item_id: itemId, source_location_id: basementId, destination_location_id: binId, quantity: 1 } });
    await expect(binStock).toContainText('2 hoses');
    await page.getByRole('dialog', { name: 'Transfer saved', exact: true }).getByRole('button', { name: 'Done', exact: true }).click();

    await binStock.getByRole('button', { name: 'Restock', exact: true }).click();
    const restockAction = page.getByRole('dialog', { name: /Restock/ });
    await restockAction.getByLabel('Quantity to add', { exact: true }).fill('2');
    await restockAction.getByRole('button', { name: /Add 2/ }).click();
    await expect(binStock).toContainText('4 hoses');
    await page.getByRole('dialog', { name: 'Restock saved', exact: true }).getByRole('button', { name: 'Done', exact: true }).click();
    await page.screenshot({ path: testInfo.outputPath('location-row-actions.png'), fullPage: true });

    await page.goto('/items');
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await page.getByLabel('Category', { exact: true }).selectOption({ label: 'Garden supplies' });
    await page.getByLabel('Location', { exact: true }).selectOption({ label: 'Basement' });
    await page.getByRole('dialog', { name: 'Filters and display' }).getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.getByRole('link', { name: itemName, exact: true })).toBeVisible();
    await expect(page.getByText('11 hoses total on hand', { exact: true })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('inventory-filtered-desktop.png'), fullPage: true });

    await page.goto('/categories');
    await page.getByRole('link', { name: /^Garden supplies/ }).click();
    const categoryItems = page.getByRole('region', { name: 'Items in this category', exact: true });
    await expect(categoryItems).toContainText('11 hoses total on hand');
    await categoryItems.getByRole('button', { name: `View locations for ${itemName}`, exact: true }).click();
    await expect(categoryItems).toContainText('Basement / Shelf / Bin — 4 hoses');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.screenshot({ path: testInfo.outputPath('category-stock-desktop.png'), fullPage: true });

    await page.goto(itemUrl);
    await page.getByLabel('More item actions', { exact: true }).click();
    await page.getByRole('button', { name: 'Edit item', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Garden supplies', exact: true }).uncheck();
    await page.getByRole('checkbox', { name: 'Seasonal', exact: true }).uncheck();
    const categoryPatch = page.waitForRequest((request) => request.method() === 'PATCH' && request.url().includes('/api/items/'));
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await categoryPatch).postDataJSON()).toEqual({ data: { category_ids: [] } });

    await page.goto('/categories');
    await page.getByRole('link', { name: /^Garden supplies/ }).click();
    await expect(page.getByRole('region', { name: 'Items in this category', exact: true })).toContainText('No items in this category yet');
    await page.getByRole('button', { name: 'Edit category', exact: true }).click();
    await page.getByLabel('Category name', { exact: true }).fill('Outdoor supplies');
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Outdoor supplies', exact: true })).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Archive category', exact: true }).click();
    await expect(page).toHaveURL(/\/items$/);
    await page.goto('/settings/archives');
    await page.getByRole('button', { name: 'Categories', exact: true }).click();
    const archivedCategories = page.getByRole('region', { name: 'Archived categories', exact: true });
    await expect(archivedCategories).toContainText('Outdoor supplies');
    await archivedCategories.getByRole('button', { name: 'Restore', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Outdoor supplies restored.');

    await page.goto(`/locations/${basementId}`);
    await page.getByRole('button', { name: 'Edit location', exact: true }).click();
    await page.getByLabel('Location name', { exact: true }).fill('Storage room');
    await page.getByLabel('Description', { exact: false }).fill('Bulk supplies\nKeep access to the stairs clear.');
    const locationPatch = page.waitForRequest((request) => request.method() === 'PATCH' && request.url().includes('/api/locations/'));
    await page.getByRole('button', { name: 'Save changes', exact: true }).click();
    expect((await locationPatch).postDataJSON()).toEqual({ data: { name: 'Storage room', description: 'Bulk supplies\nKeep access to the stairs clear.' } });
    await expect(page.getByRole('heading', { level: 1, name: 'Storage room', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Sublocations', exact: true }).getByRole('link', { name: 'Shelf', exact: true })).toBeVisible();

    await page.goto(`/locations/${binId}`);
    await page.getByRole('button', { name: 'Move location', exact: true }).click();
    await page.getByLabel('Parent location', { exact: true }).selectOption(garageId);
    const moveLocationPatch = page.waitForRequest((request) => request.method() === 'PATCH' && request.url().endsWith(`/api/locations/${binId}`));
    await page.getByRole('button', { name: 'Move location', exact: true }).last().click();
    expect((await moveLocationPatch).postDataJSON()).toEqual({ data: { parent_id: garageId } });
    await expect(page.getByRole('navigation', { name: 'Breadcrumb', exact: true })).toContainText('Garage');

    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto(`/locations/${basementId}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Storage room', exact: true })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Sublocations', exact: true }).getByRole('link', { name: 'Shelf', exact: true })).toBeVisible();
    await expect(page.getByLabel('Item to restock', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('location-stock-mobile.png'), fullPage: true });
    await page.goto(`/locations/${binId}`);
    const mobileBinStock = stock.getByRole('listitem').filter({ hasText: itemName });
    await mobileBinStock.getByRole('button', { name: `More stock actions for ${itemName}`, exact: true }).click();
    await page.getByRole('dialog').filter({ hasText: itemName }).getByRole('button', { name: /^Move out/ }).click();
    const mobileMoveAction = page.getByRole('dialog', { name: /Move/ }).first();
    await mobileMoveAction.getByLabel('Move to', { exact: true }).selectOption(basementId);
    await expect(mobileMoveAction).toContainText('4 → 3 hoses');
    await expect(mobileMoveAction).toContainText('7 → 8 hoses');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('location-transfer-mobile.png'), fullPage: true });
});
