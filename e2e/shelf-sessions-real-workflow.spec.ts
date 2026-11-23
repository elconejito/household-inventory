import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated test database.');

async function createFixture(page: Page, path: string, data: Record<string, unknown>): Promise<string> {
    const csrf = (await page.context().cookies()).find((entry) => entry.name === 'XSRF-TOKEN');
    const origin = new URL(page.url()).origin;
    const response = await page.request.post('/api/' + path, {
        headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/', 'X-XSRF-TOKEN': decodeURIComponent(csrf!.value) },
        data: { data },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data.id;
}

test('records opening stock, counts the selected shelf, and preserves balances at other locations', async ({ page }) => {
    const suffix = crypto.randomUUID();
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Shelf Tester');
    await page.getByLabel('Household name').fill('Shelf session ' + suffix);
    await page.getByLabel('Email address').fill('shelf-' + suffix + '@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Shelf-session-Password-1');
    await page.getByLabel('Confirm password').fill('Shelf-session-Password-1');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/');

    const shelfId = await createFixture(page, 'locations', { name: 'Storage Shelf' });
    const cabinetId = await createFixture(page, 'locations', { name: 'Kitchen Cabinet' });
    const paperId = await createFixture(page, 'items', { name: 'Paper Towels', counting_unit: 'roll' });
    await createFixture(page, 'inventory-levels', { item_id: paperId, location_id: cabinetId, opening_quantity: 17 });
    await createFixture(page, 'inventory-levels', { item_id: paperId, location_id: shelfId, opening_quantity: 12, alert_threshold: 1 });

    await page.goto('/locations/' + shelfId);
    await page.getByRole('link', { name: 'Add items here', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Item name' }).fill('Paper');
    await page.getByRole('button', { name: /Paper Towels.*Counted in rolls/ }).click();
    await page.getByLabel('Counted on this shelf', { exact: true }).fill('14');
    await expect(page.getByText('Kitchen Cabinet keeps its 17 rolls.')).toBeVisible();
    await page.getByRole('button', { name: 'Save & add another' }).click();
    await expect(page.getByText('Saved Paper Towels · 14 rolls on this shelf.', { exact: false })).toBeVisible();

    await page.getByRole('searchbox', { name: 'Item name' }).fill('Freezer Labels');
    await page.getByRole('button', { name: 'Create “Freezer Labels”' }).click();
    await page.getByLabel('Counting unit', { exact: true }).fill('pack');
    await page.getByLabel('Opening quantity on this shelf', { exact: true }).fill('0');
    await page.getByRole('button', { name: 'Save & finish', exact: true }).click();
    const entrySummary = page.getByRole('dialog', { name: 'Entry session finished' });
    await expect(entrySummary).toContainText('2 items recorded at this shelf.');
    await expect(entrySummary).toContainText('Created and tracked at zero');
    await entrySummary.getByRole('button', { name: 'Done' }).click();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Check shelf quantities', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Freezer Labels', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Skip item' }).click();
    await expect(page.getByRole('heading', { name: 'Paper Towels', exact: true })).toBeVisible();
    await page.getByLabel('Counted on this shelf', { exact: true }).fill('0');
    await page.getByRole('button', { name: 'Save & finish', exact: true }).click();
    const countSummary = page.getByRole('dialog', { name: 'Shelf count finished' });
    await expect(countSummary).toContainText('1 item counted at this shelf. 1 skipped. 0 remaining.');
    await countSummary.getByRole('button', { name: 'Done' }).click();
    await expect(page).toHaveURL('/locations/' + shelfId);

    const origin = new URL(page.url()).origin;
    const response = await page.request.get('/api/items/' + paperId + '?include=inventory_levels.location', {
        headers: { Accept: 'application/json', Origin: origin, Referer: origin + '/' },
    });
    expect(response.ok()).toBe(true);
    expect((await response.json()).data).toMatchObject({
        total_quantity: 17,
        inventory_levels: expect.arrayContaining([
            expect.objectContaining({ location: expect.objectContaining({ id: shelfId }), quantity: 0, alert_threshold: 1 }),
            expect.objectContaining({ location: expect.objectContaining({ id: cabinetId }), quantity: 17 }),
        ]),
    });

    await page.getByRole('button', { name: 'More stock actions for Paper Towels' }).click();
    await page.getByRole('dialog', { name: 'Paper Towels', exact: true }).getByRole('button', { name: /Count on this shelf/ }).click();
    const countItem = page.getByRole('dialog', { name: 'Count Paper Towels', exact: true });
    await countItem.getByLabel('Counted on this shelf').fill('3');
    await countItem.getByRole('button', { name: 'Save count', exact: true }).click();
    const countReceipt = page.getByRole('dialog', { name: 'Count saved', exact: true });
    await expect(countReceipt).toContainText('0 → 3 rolls');
    await countReceipt.getByRole('button', { name: 'Done' }).click();

    const paperRow = page.getByRole('listitem').filter({ has: page.getByRole('link', { name: 'Paper Towels', exact: true }) });
    await paperRow.getByRole('button', { name: 'Restock', exact: true }).click();
    const restock = page.getByRole('dialog', { name: 'Restock Paper Towels', exact: true });
    await restock.getByLabel('Quantity to add').fill('2');
    await expect(restock).toContainText('20 → 22 rolls');
    await restock.getByRole('button', { name: 'Add 2 rolls' }).click();
    const restockReceipt = page.getByRole('dialog', { name: 'Restock saved', exact: true });
    await expect(restockReceipt).toContainText('3 → 5 rolls');
    await expect(restockReceipt).toContainText('20 → 22 rolls');
    await restockReceipt.getByRole('button', { name: 'Done' }).click();

    await paperRow.getByRole('button', { name: 'More stock actions for Paper Towels' }).click();
    await page.getByRole('dialog', { name: 'Paper Towels', exact: true }).getByRole('button', { name: /Move out/ }).click();
    const move = page.getByRole('dialog', { name: 'Move Paper Towels', exact: true });
    await move.getByLabel('Move to').selectOption(cabinetId);
    await expect(move).toContainText('17 → 18 rolls');
    await move.getByRole('button', { name: 'Move 1 roll' }).click();
    const transferReceipt = page.getByRole('dialog', { name: 'Transfer saved', exact: true });
    await expect(transferReceipt).toContainText('5 → 4 rolls');
    await expect(transferReceipt).toContainText('17 → 18 rolls');
    await transferReceipt.getByRole('button', { name: 'Done' }).click();
    await expect(paperRow).toContainText('4 rolls');
    await paperRow.getByRole('link', { name: 'Paper Towels', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Paper Towels', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Back to location', exact: true }).click();
    await expect(page).toHaveURL('/locations/' + shelfId);

});
