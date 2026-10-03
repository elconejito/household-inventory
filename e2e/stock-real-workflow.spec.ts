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

async function confirmAction(page: Page): Promise<void> {
    await page.getByLabel('I’ve checked this direction and quantity.').check();
    await page.getByRole('button', { name: 'Save change' }).click();
    await expect(page.locator('section[aria-labelledby="action-title"]')).toBeHidden();
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

    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Inventory' }).click();
    await page.getByRole('button', { name: 'Add item' }).click();
    await page.getByRole('textbox', { name: /^Name/ }).fill(itemName);
    await page.getByRole('textbox', { name: /^Counting unit/ }).fill('bottle');
    await page.getByRole('button', { name: 'Save item' }).click();
    await page.getByRole('link', { name: itemName }).click();
    await expect(page.getByRole('heading', { level: 1, name: itemName })).toBeVisible();

    await createLocation(page, 'Basement');
    await createLocation(page, 'Shelf', 'Basement');

    await page.getByRole('button', { name: 'Restock at another location' }).click();
    await page.getByLabel('Location', { exact: true }).selectOption({ label: 'Basement' });
    await page.getByLabel(/Quantity/).fill('10');
    await confirmAction(page);
    await expect(page.getByRole('status')).toContainText('Stock updated.');

    await levelRow(page, 'Basement').getByRole('button', { name: 'Move out' }).click();
    await page.getByLabel('Move to').selectOption({ label: 'Basement / Shelf' });
    await page.locator('#stock-quantity').fill('3');
    await expect(page.getByText('Move 3 bottles from Basement to Basement / Shelf.')).toBeVisible();
    await confirmAction(page);

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Use 1' }).click();
    await expect(page.getByRole('status')).toContainText('Used 1 bottle from Basement / Shelf.');

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Threshold' }).click();
    await page.getByLabel(/Alert when quantity falls to/).fill('2');
    await page.getByRole('button', { name: 'Save change' }).click();
    await expect(page.getByRole('status')).toContainText('Low stock threshold updated.');

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Correct' }).click();
    await page.getByLabel('Observed quantity').fill('0');
    await confirmAction(page);

    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Restock' }).click();
    await page.locator('#stock-quantity').fill('1');
    await confirmAction(page);
    await levelRow(page, 'Basement / Shelf').getByRole('button', { name: 'Dispose' }).click();
    await page.locator('#stock-quantity').fill('1');
    await confirmAction(page);

    await expect(levelRow(page, 'Basement')).toContainText('7 bottles');
    await expect(levelRow(page, 'Basement / Shelf')).toContainText('0 bottles');
    await expect(page.getByText('Total on hand').locator('..')).toContainText('7 bottles');
    await page.screenshot({ path: testInfo.outputPath('item-stock.png'), fullPage: true });

    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Activity' }).click();
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
