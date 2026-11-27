import { expect, test, type Page } from '@playwright/test';

const runRealApiWorkflow = process.env.PLAYWRIGHT_REAL_API === '1';

test.skip(!runRealApiWorkflow, 'Set PLAYWRIGHT_REAL_API=1 after preparing household_inventory_test to run the real API workflow.');

async function createLocation(page: Page, name: string, parentName?: string): Promise<void> {
    await page.getByRole('button', { name: 'Add a location' }).click();
    await page.getByLabel('Name', { exact: true }).fill(name);

    if (parentName) {
        await page.getByLabel(/Inside another location/).selectOption({ label: parentName });
    }

    await page.getByRole('button', { name: 'Create location' }).click();
    await expect(page.getByRole('heading', { name: 'Create a location', exact: true })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Add a location' })).toBeVisible();
}

function levelRow(page: Page, locationPath: string) {
    return page.getByRole('listitem').filter({
        has: page.getByRole('heading', { name: locationPath, exact: true }),
    });
}

async function openStockAction(page: Page, locationPath: string, action: string): Promise<void> {
    const row = levelRow(page, locationPath);
    await row.locator('summary[aria-label^="More stock actions"]').click();
    await row.getByRole('button', { name: action, exact: true }).click();
}

async function confirmAction(page: Page): Promise<void> {
    await page.getByLabel('I’ve checked this direction and quantity.').check();
    await page.getByRole('button', { name: 'Save change' }).click();
    await expect(page.locator('section[aria-labelledby="action-title"]')).toBeHidden();
}

async function createResource(page: Page, path: string, data: Record<string, string | number>): Promise<{ id: string }> {
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

test('registers a household and manages real inventory stock and activity', async ({ page }, testInfo) => {
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Playwright detergent ${uniqueId}`;
    const email = `stock-${uniqueId}@example.test`;

    await page.goto('/register');
    await page.getByLabel('Your name').fill('Stock Workflow Tester');
    await page.getByLabel('Household name').fill(`Stock Workflow ${uniqueId}`);
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Stock-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Stock-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/');

    const basement = await createResource(page, 'locations', { name: 'Basement' });
    await createResource(page, 'locations', { name: 'Shelf', parent_id: Number(basement.id) });

    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Items', exact: true }).click();
    await page.getByRole('button', { name: 'Add item' }).click();
    await page.getByRole('textbox', { name: /^Name/ }).fill(itemName);
    await page.getByRole('textbox', { name: /^Counting unit/ }).fill('bottle');
    await page.getByRole('button', { name: 'Create item', exact: true }).click();
    await page.getByRole('button', { name: 'Choose a location', exact: true }).click();
    await expect(page.locator('#setup-location')).toBeVisible();
    await expect(page).toHaveURL(/\/items\/\d+$/);
    const itemId = new URL(page.url()).pathname.split('/').at(-1)!;
    await page.locator('#setup-location').selectOption({ label: 'Basement' });
    await page.locator('#setup-quantity').fill('10');
    const openingStockRequest = page.waitForRequest((request) => request.method() === 'POST' && request.url().endsWith('/api/inventory-levels'));
    await page.getByRole('dialog', { name: 'Add stock location', exact: true }).getByRole('button', { name: 'Add stock location', exact: true }).click();
    expect((await openingStockRequest).postDataJSON()).toEqual({ data: { item_id: itemId, location_id: basement.id, opening_quantity: 10, alert_threshold: null } });
    await expect(page.getByRole('heading', { level: 1, name: itemName })).toBeVisible();

    await expect(page.getByRole('status').filter({ hasText: 'Stock location added with 10 bottles.' })).toBeVisible();

    await openStockAction(page, 'Basement', 'Move out');
    await page.getByLabel('Move to').selectOption({ label: 'Basement / Shelf' });
    await page.locator('#stock-quantity').fill('3');
    await expect(page.getByText('Move 3 bottles from Basement to Basement / Shelf.')).toBeVisible();
    await confirmAction(page);

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Use 1' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Used 1 bottle from Basement / Shelf.' })).toBeVisible();

    await openStockAction(page, 'Basement / Shelf', 'Threshold');
    await page.getByLabel(/Alert when quantity falls to/).fill('2');
    await page.getByRole('button', { name: 'Save change' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Low stock threshold updated.' })).toBeVisible();

    await openStockAction(page, 'Basement / Shelf', 'Correct');
    await page.getByLabel('Observed quantity').fill('0');
    await confirmAction(page);

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Restock' }).click();
    await page.locator('#stock-quantity').fill('1');
    await confirmAction(page);
    await openStockAction(page, 'Basement / Shelf', 'Dispose');
    await page.locator('#stock-quantity').fill('1');
    await confirmAction(page);

    await expect(levelRow(page, 'Basement')).toContainText('7 bottles');
    await expect(levelRow(page, 'Basement / Shelf')).toContainText('0 bottles');
    await expect(page.locator('header').filter({ has: page.getByRole('heading', { level: 1, name: itemName }) })).toContainText('7 bottles on hand');
    await page.screenshot({ path: testInfo.outputPath('item-stock.png'), fullPage: true });

    const itemUrl = page.url();
    await page.locator('summary[aria-label="More item actions"]').click();
    await page.getByRole('button', { name: 'Mark Buy soon', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Resolve Buy soon', exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'Notes', exact: true }).click();
    const itemNotesTab = page.locator('#item-tab-notes');
    const alertNotesDisclosure = itemNotesTab.locator('details').filter({ has: page.getByText('Reminder notes', { exact: true }) });
    await alertNotesDisclosure.locator('summary').click();
    const alertNotes = itemNotesTab.getByRole('region', { name: 'Notes', exact: true }).nth(1);
    const reminderNote = 'Buy the unscented refill on the next shop.';
    await alertNotes.getByLabel('Note', { exact: true }).fill(reminderNote);
    await alertNotes.getByRole('button', { name: 'Add note', exact: true }).click();
    await expect(alertNotes).toContainText(reminderNote);
    await page.reload();
    await page.getByRole('tab', { name: 'Notes', exact: true }).click();
    await page.locator('#item-tab-notes').locator('details').filter({ has: page.getByText('Reminder notes', { exact: true }) }).locator('summary').click();
    await expect(page.locator('#item-tab-notes').getByRole('region', { name: 'Notes', exact: true }).nth(1)).toContainText(reminderNote);

    await page.goto('/items');
    const inventoryRow = page.locator('article').filter({ has: page.getByRole('link', { name: itemName, exact: true }) });
    await expect(inventoryRow).toContainText('Buy soon');
    await expect(inventoryRow).toContainText('Out of stock');
    await page.screenshot({ path: testInfo.outputPath('inventory-stock-indicators.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('inventory-stock-indicators-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto(itemUrl);
    await page.locator('summary[aria-label="More item actions"]').click();
    await page.getByRole('button', { name: 'Resolve Buy soon', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Mark Buy soon', exact: true })).toBeVisible();
    await page.goto('/items');
    await expect(inventoryRow).not.toContainText('Buy soon');
    await expect(inventoryRow).toContainText('Out of stock');

    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Activity' }).click();
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await page.getByLabel('Item', { exact: true }).selectOption({ label: itemName });
    await page.getByLabel('Change type', { exact: true }).selectOption('transfer');

    await page.getByLabel('Location role', { exact: true }).selectOption('from');
    await page.getByLabel('Location', { exact: true }).selectOption({ label: 'Basement' });
    let transferRecord = page.locator('article').filter({ has: page.getByRole('heading', { name: `${itemName} · Transfer`, exact: true }) });
    await expect(transferRecord).toContainText('-3 bottles');
    await expect(transferRecord).toContainText('7 after');

    await page.getByLabel('Location role', { exact: true }).selectOption('to');
    await page.getByLabel('Location', { exact: true }).selectOption({ label: 'Basement / Shelf' });
    transferRecord = page.locator('article').filter({ has: page.getByRole('heading', { name: `${itemName} · Transfer`, exact: true }) });
    await expect(transferRecord).toContainText('+3 bottles');
    await expect(transferRecord).toContainText('3 after');

    await page.getByLabel('Location role', { exact: true }).selectOption('either');
    await expect(transferRecord).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('activity-stock.png'), fullPage: true });
});
