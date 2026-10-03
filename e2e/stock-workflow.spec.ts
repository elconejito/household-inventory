import { expect, test } from '@playwright/test';

test('records a one tap stock use from an item detail page', async ({ page }) => {
    const item = {
        type: 'items', id: '7', name: 'Batteries', counting_unit: 'pack', counting_unit_plural: 'packs', description: null,
        total_quantity: 2,
        inventory_levels: [{
            type: 'inventory-levels', id: '9', quantity: 2, alert_threshold: null, stock_status: 'in_stock', alert_status: 'unmonitored',
            location: { type: 'locations', id: '2', name: 'Pantry', description: null, parent: null },
        }],
    };

    await page.route('**/api/user*', (route) => route.fulfill({ json: { data: { id: '1', name: 'Harvey', email: 'harvey@example.test', membership: { role: 'owner', household: { id: '1', name: 'Home' } } } } }));
    await page.route('**/api/items/7*', (route) => route.fulfill({ json: { data: item } }));
    await page.route('**/api/inventory-alerts?**', (route) => route.fulfill({ json: { data: [], links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } } }));
    await page.route('**/api/locations*', (route) => route.fulfill({ json: { data: [{ type: 'locations', id: '2', name: 'Pantry', description: null, parent: null }], meta: { current_page: 1, last_page: 1, total: 1 } } }));
    let recordedPayload: unknown;
    await page.route('**/api/inventory-movements', async (route) => {
        recordedPayload = route.request().postDataJSON();
        await route.fulfill({ status: 201, json: { data: { id: '22' } } });
    });

    await page.goto('/inventory/7');
    await expect(page.getByRole('heading', { level: 1, name: 'Batteries' })).toBeVisible();
    await page.getByRole('button', { name: 'Use 1' }).click();
    await expect(page.getByRole('status')).toContainText('Used 1 pack from Pantry.');
    expect(recordedPayload).toEqual({ data: { movement_type: 'consumption', item_id: '7', location_id: '2', quantity: 1 } });
});
