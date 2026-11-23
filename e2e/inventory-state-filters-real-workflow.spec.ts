import { expect, test, type Page, type Request } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');

const apiHeaders = {
    Accept: 'application/json',
    Origin: 'http://127.0.0.1:8000',
    Referer: 'http://127.0.0.1:8000/',
};

type FilterItemResponse = { id: string };

async function registerHousehold(page: Page, suffix: string): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Inventory Filter Tester');
    await page.getByLabel('Household name').fill(`Inventory Filter Home ${suffix}`);
    await page.getByLabel('Email address').fill(`inventory-filter-${suffix}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Inventory-filter-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Inventory-filter-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');
}

async function createResource(page: Page, path: string, data: Record<string, unknown>): Promise<FilterItemResponse> {
    const csrfCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrfCookie).toBeDefined();
    const response = await page.request.post(`/api/${path}`, {
        headers: { ...apiHeaders, 'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value) },
        data: { data },
    });
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data as FilterItemResponse;
}

async function resolveBuySoonAlert(page: Page, alertId: string): Promise<void> {
    const csrfCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrfCookie).toBeDefined();
    const response = await page.request.post(`/api/inventory-alerts/${alertId}/resolve`, {
        headers: { ...apiHeaders, 'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value) },
    });
    expect(response.status(), await response.text()).toBe(200);
}

function matchesItemsRequest(request: Request, expected: Record<string, string>): boolean {
    if (request.method() !== 'GET') return false;
    const url = new URL(request.url());
    return url.pathname === '/api/items' && Object.entries(expected).every(([key, value]) => value === ''
        ? !url.searchParams.has(key)
        : url.searchParams.get(key) === value);
}

async function waitForFilteredItems(page: Page, expected: Record<string, string>): Promise<URL> {
    const request = await page.waitForRequest((candidate) => matchesItemsRequest(candidate, expected));
    return new URL(request.url());
}

async function selectInventoryFilter(page: Page, label: string, value: string): Promise<void> {
    const filters = page.locator('dialog[aria-label="Filters and display"]');
    const isOpen = await filters.isVisible();
    if (!isOpen) {
        await page.getByRole('button', { name: /^Filters/ }).click();
    }
    await page.getByLabel(label, { exact: true }).selectOption(value);
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
}

async function closeInventoryFilters(page: Page): Promise<void> {
    if (await page.locator('dialog[aria-label="Filters and display"]').isVisible()) {
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    }
}

function itemLink(page: Page, name: string) {
    return page.getByRole('region', { name: 'Inventory', exact: true }).getByRole('link', { name, exact: true });
}

test('filters stock and attention across item-wide levels while preserving combined inventory filters', async ({ page }, testInfo) => {
    test.setTimeout(90_000);
    await page.context().setDefaultTimeout(10_000);
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const names = {
        untracked: `Filter untracked ${suffix}`,
        positive: `Filter positive ${suffix}`,
        monitoredEmpty: `Filter monitored empty ${suffix}`,
        mixed: `Filter mixed empty-positive ${suffix}`,
        low: `Filter exact threshold ${suffix}`,
        buySoon: `Filter plentiful buy soon ${suffix}`,
        resolved: `Filter resolved buy soon ${suffix}`,
        combined: `Filter combined visible ${suffix}`,
    };

    await registerHousehold(page, suffix);
    const category = await createResource(page, 'categories', { name: `Filter category ${suffix}` });
    const pantry = await createResource(page, 'locations', { name: `Filter pantry ${suffix}` });
    const closet = await createResource(page, 'locations', { name: `Filter closet ${suffix}` });
    const utility = await createResource(page, 'locations', { name: `Filter utility ${suffix}` });
    const createItem = (name: string, categoryIds: string[] = []) => createResource(page, 'items', {
        name,
        counting_unit: 'pack',
        category_ids: categoryIds,
    });

    await createItem(names.untracked);
    const positive = await createItem(names.positive);
    const monitoredEmpty = await createItem(names.monitoredEmpty);
    const mixed = await createItem(names.mixed);
    const low = await createItem(names.low);
    const buySoon = await createItem(names.buySoon);
    const resolved = await createItem(names.resolved);
    const combined = await createItem(names.combined, [category.id]);

    await createResource(page, 'inventory-levels', { item_id: positive.id, location_id: closet.id, alert_threshold: null });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: positive.id, location_id: closet.id, quantity: 5 });

    await createResource(page, 'inventory-levels', { item_id: monitoredEmpty.id, location_id: pantry.id, alert_threshold: 0 });

    await createResource(page, 'inventory-levels', { item_id: mixed.id, location_id: pantry.id, alert_threshold: 0 });
    await createResource(page, 'inventory-levels', { item_id: mixed.id, location_id: closet.id, alert_threshold: null });
    await createResource(page, 'inventory-levels', { item_id: mixed.id, location_id: utility.id, alert_threshold: null });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: mixed.id, location_id: utility.id, quantity: 3 });

    await createResource(page, 'inventory-levels', { item_id: low.id, location_id: closet.id, alert_threshold: 4 });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: low.id, location_id: closet.id, quantity: 4 });

    await createResource(page, 'inventory-levels', { item_id: buySoon.id, location_id: pantry.id, alert_threshold: null });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: buySoon.id, location_id: pantry.id, quantity: 12 });
    await createResource(page, 'inventory-alerts', { item_id: buySoon.id, alert_type: 'buy_soon' });
    const resolvedAlert = await createResource(page, 'inventory-alerts', { item_id: resolved.id, alert_type: 'buy_soon' });
    await resolveBuySoonAlert(page, resolvedAlert.id);

    await createResource(page, 'inventory-levels', { item_id: combined.id, location_id: pantry.id, alert_threshold: null });
    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: combined.id, location_id: pantry.id, quantity: 2 });

    const pageOnlyItems: FilterItemResponse[] = [];
    for (let index = 1; index <= 11; index++) {
        pageOnlyItems.push(await createItem(`Filter category empty ${suffix} ${String(index).padStart(2, '0')}`, [category.id]));
    }
    expect(pageOnlyItems).toHaveLength(11);

    await page.goto('/items');
    const inventory = page.getByRole('region', { name: 'Inventory', exact: true });
    const stockFilter = page.locator('#item-stock-filter');
    const attentionFilter = page.locator('#item-attention-filter');
    await page.getByRole('button', { name: /^Filters/ }).click();
    await expect(stockFilter).toHaveValue('');
    await expect(attentionFilter).toHaveValue('');
    await expect(stockFilter.locator('option')).toHaveText(['All stock levels', 'Stock on hand', 'No stock on hand']);
    await expect(attentionFilter.locator('option')).toHaveText([
        'All attention states',
        'Needs attention',
        'Empty monitored location',
        'Low monitored location',
        'Buy soon',
        'No attention needed',
    ]);
    await page.getByRole('button', { name: 'Close', exact: true }).click();

    const search = page.locator('#item-search');
    const searchRequestPromise = waitForFilteredItems(page, { 'filter[search]': names.combined });
    await search.fill(names.combined);
    const searchRequest = await searchRequestPromise;
    expect(searchRequest.searchParams.get('page')).toBe('1');
    const categoryRequestPromise = waitForFilteredItems(page, { 'filter[search]': names.combined, 'filter[category_id]': category.id });
    await selectInventoryFilter(page, 'Category', category.id);
    const categoryRequest = await categoryRequestPromise;
    expect(categoryRequest.searchParams.get('page')).toBe('1');
    const combinedLocationRequestPromise = waitForFilteredItems(page, { 'filter[search]': names.combined, 'filter[category_id]': category.id, 'filter[location_id]': pantry.id });
    await selectInventoryFilter(page, 'Location', pantry.id);
    const combinedLocationRequest = await combinedLocationRequestPromise;
    expect(combinedLocationRequest.searchParams.get('page')).toBe('1');
    const stockRequest = page.waitForRequest((request) => matchesItemsRequest(request, {
        'filter[search]': names.combined,
        'filter[category_id]': category.id,
        'filter[location_id]': pantry.id,
        'filter[stock_status]': 'in_stock',
    }));
    await selectInventoryFilter(page, 'Stock on hand', 'in_stock');
    const attentionRequest = page.waitForRequest((request) => matchesItemsRequest(request, {
        'filter[search]': names.combined,
        'filter[category_id]': category.id,
        'filter[location_id]': pantry.id,
        'filter[stock_status]': 'in_stock',
        'filter[attention_status]': 'none',
    }));
    await selectInventoryFilter(page, 'Attention', 'none');
    const combinedRequest = new URL((await attentionRequest).url());
    expect(combinedRequest.searchParams.get('page')).toBe('1');
    expect(await stockRequest).toBeTruthy();
    await expect(inventory).toContainText('1 item');
    await expect(itemLink(page, names.combined)).toBeVisible();
    await closeInventoryFilters(page);
    await page.screenshot({ path: testInfo.outputPath('inventory-state-filters-desktop.png'), fullPage: true });

    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('inventory-state-filters-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });

    await page.goto('/items');
    const categoryPageRequest = waitForFilteredItems(page, { 'filter[category_id]': category.id });
    await selectInventoryFilter(page, 'Category', category.id);
    let itemsRequest = await categoryPageRequest;
    expect(itemsRequest.searchParams.get('page')).toBe('1');
    await expect(inventory).toContainText('12 items');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(inventory).toContainText('Page 2 of 2');
    const emptyFilterRequest = page.waitForRequest((request) => matchesItemsRequest(request, {
        'filter[category_id]': category.id,
        'filter[stock_status]': 'empty',
        page: '1',
    }));
    await selectInventoryFilter(page, 'Stock on hand', 'empty');
    itemsRequest = new URL((await emptyFilterRequest).url());
    expect(itemsRequest.searchParams.get('page')).toBe('1');
    await expect(inventory).toContainText('11 items');
    await expect(inventory).toContainText('Page 1 of 2');

    await page.goto('/items');
    const pageSizeRequest = page.waitForRequest((request) => matchesItemsRequest(request, { page: '1', per_page: '100' }));
    await page.getByLabel('Per page').selectOption('100');
    await pageSizeRequest;
    await expect(inventory).toContainText('19 items');

    const noStockRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[stock_status]': 'empty' }));
    await selectInventoryFilter(page, 'Stock on hand', 'empty');
    expect(new URL((await noStockRequest).url()).searchParams.get('page')).toBe('1');
    await expect(itemLink(page, names.untracked)).toBeVisible();
    await expect(itemLink(page, names.monitoredEmpty)).toBeVisible();
    await expect(itemLink(page, names.mixed)).toHaveCount(0);
    await expect(inventory).toContainText('14 items');

    const inStockRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[stock_status]': 'in_stock' }));
    await selectInventoryFilter(page, 'Stock on hand', 'in_stock');
    await inStockRequest;
    await expect(itemLink(page, names.untracked)).toHaveCount(0);
    await expect(itemLink(page, names.mixed)).toBeVisible();
    await expect(itemLink(page, names.positive)).toBeVisible();

    const locationRequest = page.waitForRequest((request) => matchesItemsRequest(request, {
        'filter[location_id]': closet.id,
        'filter[stock_status]': '',
    }));
    await selectInventoryFilter(page, 'Stock on hand', '');
    await selectInventoryFilter(page, 'Location', closet.id);
    const emptyAttentionRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[location_id]': closet.id, 'filter[attention_status]': 'empty' }));
    await selectInventoryFilter(page, 'Attention', 'empty');
    await locationRequest;
    const emptyAttentionUrl = new URL((await emptyAttentionRequest).url());
    expect(emptyAttentionUrl.searchParams.get('filter[location_id]')).toBe(closet.id);
    await expect(itemLink(page, names.mixed)).toBeVisible();
    await expect(inventory).toContainText('3 packs total on hand');
    await expect(inventory).toContainText(`At Filter closet ${suffix}: 0 packs`);
    await expect(itemLink(page, names.monitoredEmpty)).toHaveCount(0);
    await expect(inventory).toContainText('1 item');

    await selectInventoryFilter(page, 'Location', '');
    const lowAttentionRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[attention_status]': 'low' }));
    await selectInventoryFilter(page, 'Attention', 'low');
    const lowUrl = new URL((await lowAttentionRequest).url());
    expect(lowUrl.searchParams.get('filter[stock_status]')).toBeNull();
    await expect(itemLink(page, names.low)).toBeVisible();
    await expect(inventory).toContainText('1 item');

    const buySoonRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[attention_status]': 'buy_soon' }));
    await selectInventoryFilter(page, 'Attention', 'buy_soon');
    await buySoonRequest;
    await expect(itemLink(page, names.buySoon)).toBeVisible();
    await expect(itemLink(page, names.resolved)).toHaveCount(0);
    await expect(inventory).toContainText('1 item');

    const needsAttentionRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[attention_status]': 'needs_attention' }));
    await selectInventoryFilter(page, 'Attention', 'needs_attention');
    await needsAttentionRequest;
    await expect(itemLink(page, names.monitoredEmpty)).toBeVisible();
    await expect(itemLink(page, names.mixed)).toBeVisible();
    await expect(itemLink(page, names.low)).toBeVisible();
    await expect(itemLink(page, names.buySoon)).toBeVisible();
    await expect(itemLink(page, names.resolved)).toHaveCount(0);

    const noAttentionRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[attention_status]': 'none' }));
    await selectInventoryFilter(page, 'Attention', 'none');
    await noAttentionRequest;
    await expect(itemLink(page, names.untracked)).toBeVisible();
    await expect(itemLink(page, names.positive)).toBeVisible();
    await expect(itemLink(page, names.resolved)).toBeVisible();
    await expect(itemLink(page, names.low)).toHaveCount(0);
    await expect(itemLink(page, names.buySoon)).toHaveCount(0);

    const emptyResultSearch = `no matching inventory item ${suffix}`;
    const emptyResultRequest = page.waitForRequest((request) => matchesItemsRequest(request, {
        'filter[search]': emptyResultSearch,
        'filter[stock_status]': 'in_stock',
        'filter[attention_status]': 'none',
    }));
    await selectInventoryFilter(page, 'Stock on hand', 'in_stock');
    await selectInventoryFilter(page, 'Attention', 'none');
    await search.fill(emptyResultSearch);
    await emptyResultRequest;
    await expect(inventory).toContainText('No items match these filters');
    await expect(page.getByRole('button', { name: 'Clear filters', exact: true })).toBeVisible();
    const clearedRequest = page.waitForRequest((request) => {
        if (request.method() !== 'GET') return false;
        const url = new URL(request.url());
        return url.pathname === '/api/items'
            && !url.searchParams.has('filter[search]')
            && !url.searchParams.has('filter[category_id]')
            && !url.searchParams.has('filter[location_id]')
            && !url.searchParams.has('filter[stock_status]')
            && !url.searchParams.has('filter[attention_status]')
            && url.searchParams.get('page') === '1';
    });
    await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
    await clearedRequest;
    await expect(search).toHaveValue('');
    await page.getByRole('button', { name: /^Filters/ }).click();
    await expect(stockFilter).toHaveValue('');
    await expect(attentionFilter).toHaveValue('');
    await expect(inventory).toContainText('19 items');
    await page.getByRole('button', { name: 'Close', exact: true }).click();

    await page.screenshot({ path: testInfo.outputPath('inventory-state-filters-cleared-desktop.png'), fullPage: true });
});

test('keeps a searched filtered page in the URL across item navigation, browser back, and refresh', async ({ page }) => {
    test.setTimeout(90_000);
    await page.context().setDefaultTimeout(10_000);
    const suffix = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const searchTerm = `History state ${suffix}`;

    await registerHousehold(page, suffix);
    const category = await createResource(page, 'categories', { name: `History category ${suffix}` });
    for (let index = 1; index <= 11; index++) {
        await createResource(page, 'items', {
            name: `${searchTerm} ${String(index).padStart(2, '0')}`,
            counting_unit: 'pack',
            category_ids: [category.id],
        });
    }

    await page.goto('/items');
    const inventory = page.getByRole('region', { name: 'Inventory', exact: true });
    const search = page.locator('#item-search');
    const searchRequest = page.waitForRequest((request) => matchesItemsRequest(request, { 'filter[search]': searchTerm }));
    await search.fill(searchTerm);
    await searchRequest;
    await selectInventoryFilter(page, 'Category', category.id);
    await expect(inventory).toContainText('11 items');

    await page.getByRole('button', { name: 'Page 2', exact: true }).click();
    await expect(inventory).toContainText('Page 2 of 2');
    await expect.poll(() => new URL(page.url()).searchParams.get('search')).toBe(searchTerm);
    await expect.poll(() => new URL(page.url()).searchParams.get('category')).toBe(category.id);
    await expect.poll(() => new URL(page.url()).searchParams.get('page')).toBe('2');
    await expect(itemLink(page, `${searchTerm} 11`)).toBeVisible();

    await itemLink(page, `${searchTerm} 11`).click();
    await expect(page).toHaveURL(/\/items\/\d+/);
    await page.goBack();
    await expect(page).toHaveURL(/\/items\?/);
    await expect(search).toHaveValue(searchTerm);
    await expect(inventory).toContainText('Page 2 of 2');
    await expect(itemLink(page, `${searchTerm} 11`)).toBeVisible();

    await page.reload();
    await expect(search).toHaveValue(searchTerm);
    await expect(inventory).toContainText('Page 2 of 2');
    await expect(itemLink(page, `${searchTerm} 11`)).toBeVisible();
    await page.getByRole('button', { name: /^Filters/ }).click();
    await expect(page.locator('#item-category-filter')).toHaveValue(category.id);
});
