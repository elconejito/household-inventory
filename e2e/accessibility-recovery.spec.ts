import { expect, test, type Page, type Route } from '@playwright/test';

const authenticatedUser = {
    data: {
        type: 'users',
        id: '1',
        name: 'Keyboard Tester',
        email: 'keyboard@example.test',
        membership: {
            type: 'memberships',
            id: '1',
            role: 'owner',
            household: { type: 'households', id: '1', name: 'Keyboard Home' },
        },
    },
};

async function mockAuthenticatedSession(page: Page): Promise<void> {
    await page.route('**/api/user*', async (route) => {
        await route.fulfill({ json: authenticatedUser });
    });
}

async function mockCatalogLocations(page: Page, onHierarchyRequest: (route: Route, requestCount: number) => Promise<void>): Promise<void> {
    let hierarchyRequests = 0;
    await page.route('**/api/locations*', async (route) => {
        const url = new URL(route.request().url());
        if (url.searchParams.get('per_page') === '100') {
            hierarchyRequests++;
            await onHierarchyRequest(route, hierarchyRequests);
            return;
        }

        await route.fulfill({ json: {
            data: [{ type: 'locations', id: '2', name: 'Pantry', description: null, parent: { type: 'locations', id: '1', name: 'Kitchen', description: null, parent: null } }],
            meta: { current_page: 1, last_page: 1, total: 1 },
        } });
    });
}

test('supports keyboard skip navigation and moves focus to the heading after a route change', async ({ page }) => {
    await mockAuthenticatedSession(page);
    await mockCatalogLocations(page, async (route) => {
        await route.fulfill({ json: {
            data: [{ type: 'locations', id: '1', name: 'Kitchen', description: null, parent: null }],
            meta: { current_page: 1, last_page: 1, total: 1 },
        } });
    });
    await page.route('**/api/items*', async (route) => {
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null } } });
    });
    await page.route('**/api/inventory-movements?**', async (route) => {
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null } } });
    });
    await page.route('**/api/inventory-movement-recorders?**', async (route) => {
        await route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null } } });
    });

    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/inventory/locations?parent=1');
    await expect(page.getByRole('heading', { level: 1, name: 'Locations', exact: true })).toBeVisible();

    await page.keyboard.press('Tab');
    const skipLink = page.getByRole('link', { name: 'Skip to main content', exact: true });
    await expect(skipLink).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();

    const activityLink = page.getByRole('navigation', { name: 'Main navigation' }).first().getByRole('link', { name: 'Activity', exact: true });
    await activityLink.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/activity');
    await expect(page.getByRole('heading', { level: 1, name: 'Activity', exact: true })).toBeFocused();
});

test('recovers the catalog location hierarchy query without losing visible child locations', async ({ page }, testInfo) => {
    await mockAuthenticatedSession(page);
    let hierarchyRequests = 0;
    await mockCatalogLocations(page, async (route, requestCount) => {
        hierarchyRequests = requestCount;
        if (requestCount <= 4) {
            await route.fulfill({ status: 503, json: { errors: [{ status: '503', code: 'service_unavailable', title: 'Service Unavailable', detail: 'Temporary location hierarchy failure.' }] } });
            return;
        }

        await route.fulfill({ json: {
            data: [
                { type: 'locations', id: '1', name: 'Kitchen', description: null, parent: null },
                { type: 'locations', id: '2', name: 'Pantry', description: null, parent: { type: 'locations', id: '1', name: 'Kitchen', description: null, parent: null } },
            ],
            meta: { current_page: 1, last_page: 1, total: 2 },
        } });
    });

    await page.goto('/inventory/locations?parent=1');
    const pantryLink = page.getByRole('link', { name: 'Pantry', exact: true });
    await expect(pantryLink).toBeVisible();
    const hierarchyAlert = page.getByRole('alert').filter({ hasText: 'Location hierarchy could not be loaded. Location names may not show their full paths.' });
    await expect(hierarchyAlert).toBeVisible({ timeout: 15_000 });

    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('location-hierarchy-retry-mobile.png'), fullPage: true });

    const retry = page.getByRole('button', { name: 'Retry location hierarchy', exact: true });
    const recoveredResponse = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname === '/api/locations' && url.searchParams.get('per_page') === '100' && response.status() === 200;
    });
    await retry.focus();
    await page.keyboard.press('Enter');
    await recoveredResponse;
    await expect(hierarchyAlert).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 2, name: 'Kitchen', exact: true })).toBeVisible();
    await expect(pantryLink).toBeVisible();
    expect(hierarchyRequests).toBe(5);
});
