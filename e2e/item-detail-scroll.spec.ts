import { expect, test, type Page } from '@playwright/test';

const authenticatedUser = {
    data: {
        type: 'users',
        id: '1',
        name: 'Scroll Tester',
        email: 'scroll@example.test',
        membership: {
            type: 'memberships',
            id: '1',
            role: 'owner',
            household: { type: 'households', id: '1', name: 'Scroll Home' },
        },
    },
};

function item(id: number) {
    return {
        type: 'items',
        id: String(id),
        name: `Item ${id}`,
        counting_unit: 'box',
        counting_unit_plural: 'boxes',
        description: null,
        categories: [],
        images: [],
        total_quantity: 0,
        inventory_levels: [],
        active_alerts: [],
    };
}

async function mockInventory(page: Page): Promise<void> {
    await page.route('**/api/user*', (route) => route.fulfill({ json: authenticatedUser }));
    await page.route('**/api/items/**', (route) => {
        const itemDetail = new URL(route.request().url()).pathname.match(/\/api\/items\/(\d+)$/);
        return route.fulfill({ json: { data: item(Number(itemDetail?.[1] ?? 1)) } });
    });
    await page.route('**/api/items*', (route) => {
        const url = new URL(route.request().url());
        const perPage = Number(url.searchParams.get('per_page') ?? 10);
        const currentPage = Number(url.searchParams.get('page') ?? 1);
        const allItems = Array.from({ length: 40 }, (_, index) => item(index + 1));
        const start = (currentPage - 1) * perPage;

        return route.fulfill({ json: {
            data: allItems.slice(start, start + perPage),
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: currentPage, last_page: Math.max(1, Math.ceil(allItems.length / perPage)), per_page: perPage, total: allItems.length, from: start + 1, to: Math.min(allItems.length, start + perPage) },
        } });
    });
    await page.route('**/api/categories*', (route) => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
    await page.route('**/api/locations*', (route) => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
    await page.route('**/api/inventory-alerts*', (route) => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
    await page.route('**/api/inventory-movements*', (route) => route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } }));
}

test('opens item details at the top and restores the originating list position on return', async ({ page }) => {
    await mockInventory(page);
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/items');
    await expect(page.getByRole('region', { name: 'Inventory', exact: true })).toBeVisible();

    await page.getByLabel('Per page').selectOption('50');
    const targetItem = page.getByRole('list', { name: 'Inventory items' }).getByRole('link', { name: 'Item 40', exact: true });
    await expect(targetItem).toBeVisible();
    await targetItem.scrollIntoViewIfNeeded();
    const listScrollPosition = await page.evaluate(() => window.scrollY);
    expect(listScrollPosition).toBeGreaterThan(0);

    await targetItem.click();
    await expect(page).toHaveURL(/\/items\/40/);
    await expect(page.getByRole('heading', { level: 1, name: 'Item 40', exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);

    await page.goBack();
    await expect(page.getByRole('list', { name: 'Inventory items' }).getByRole('link', { name: 'Item 40', exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
    expect(await page.evaluate(() => window.scrollY)).toBeCloseTo(listScrollPosition, -1);

    await page.goForward();
    await expect(page.getByRole('heading', { level: 1, name: 'Item 40', exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);

    await page.getByRole('link', { name: 'Back to results', exact: true }).click();
    await expect(page.getByRole('list', { name: 'Inventory items' }).getByRole('link', { name: 'Item 40', exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
    expect(await page.evaluate(() => window.scrollY)).toBeCloseTo(listScrollPosition, -1);
});

test('returns directly linked items to the inventory list top', async ({ page }) => {
    await mockInventory(page);
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/items/40');

    await expect(page.getByRole('heading', { level: 1, name: 'Item 40', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Back to results', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Inventory', exact: true })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
});
