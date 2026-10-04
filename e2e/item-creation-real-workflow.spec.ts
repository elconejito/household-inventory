import { expect, test, type Page, type Request } from '@playwright/test';

const runRealApiWorkflow = process.env.PLAYWRIGHT_REAL_API === '1';

test.skip(!runRealApiWorkflow, 'Set PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database to run real item creation workflows.');

async function registerHousehold(page: Page, suffix: string): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Item Creation Tester');
    await page.getByLabel('Household name').fill(`Creation Home ${suffix}`);
    await page.getByLabel('Email address').fill(`creation-${suffix}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Creation-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Creation-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');
}

async function createLocation(page: Page, name: string, parentId?: string): Promise<{ id: string }> {
    const csrfCookie = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(csrfCookie).toBeDefined();
    const response = await page.request.post('/api/locations', {
        headers: {
            Accept: 'application/json',
            Origin: 'http://127.0.0.1:8000',
            Referer: 'http://127.0.0.1:8000/',
            'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value),
        },
        data: { data: { name, ...(parentId ? { parent_id: Number(parentId) } : {}) } },
    });
    expect(response.status(), await response.text()).toBe(201);

    return (await response.json()).data;
}

async function openCreateItemPanel(page: Page, itemName: string): Promise<void> {
    await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Inventory' }).click();
    await page.getByRole('button', { name: 'Add item', exact: true }).click();
    await page.getByRole('textbox', { name: /^Name/ }).fill(itemName);
    await page.getByRole('textbox', { name: /^Counting unit/ }).fill('pack');
}

async function itemImageList(page: Page, itemId: string): Promise<Array<Record<string, string | boolean>>> {
    const response = await page.request.get(`/api/items/${itemId}/images?per_page=10`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(response.ok(), await response.text()).toBe(true);

    return (await response.json()).data;
}

async function movementHistory(page: Page, itemId: string): Promise<Array<Record<string, unknown>>> {
    const response = await page.request.get(`/api/inventory-movements?filter[item_id]=${itemId}&include=entries.location`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(response.ok(), await response.text()).toBe(true);

    return (await response.json()).data;
}

async function primaryPhotoFixture(page: Page, label: string): Promise<Buffer> {
    const imageData = await page.evaluate((text) => {
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 600;
        const context = canvas.getContext('2d')!;
        context.fillStyle = '#e8efe9';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#397766';
        context.fillRect(120, 90, 560, 420);
        context.fillStyle = '#ffffff';
        context.font = 'bold 40px sans-serif';
        context.textAlign = 'center';
        context.fillText(text, 400, 300);

        return canvas.toDataURL('image/png').split(',')[1]!;
    }, label);

    return Buffer.from(imageData, 'base64');
}

function watchCreateRequests(page: Page): { items: Request[]; photos: Request[] } {
    const requests = { items: [] as Request[], photos: [] as Request[] };
    page.on('request', (request) => {
        if (request.method() !== 'POST') return;
        if (/\/api\/items$/.test(new URL(request.url()).pathname)) requests.items.push(request);
        if (/\/api\/items\/\d+\/images$/.test(new URL(request.url()).pathname)) requests.photos.push(request);
    });

    return requests;
}

test('saves an item with an optional primary photo and opens its detail page', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Coffee filters ${uniqueId}`;
    await registerHousehold(page, uniqueId);
    const requests = watchCreateRequests(page);
    await openCreateItemPanel(page, itemName);
    await page.screenshot({ path: testInfo.outputPath('new-item-create-form-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-create-form-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.getByLabel('Primary photo (optional)', { exact: true }).setInputFiles({
        name: 'coffee-filters.png',
        mimeType: 'image/png',
        buffer: await primaryPhotoFixture(page, 'COFFEE FILTERS'),
    });

    await page.getByRole('button', { name: 'Save item', exact: true }).click();
    await expect(page).toHaveURL(/\/inventory\/\d+$/);
    await expect(page.getByRole('heading', { level: 1, name: itemName, exact: true })).toBeVisible();
    expect(requests.items).toHaveLength(1);
    expect(requests.photos).toHaveLength(1);
    const itemPayload = requests.items[0]!.postDataJSON() as { data: Record<string, unknown> };
    expect(itemPayload.data).not.toHaveProperty('quantity');
    expect(itemPayload.data).not.toHaveProperty('image');
    expect(itemPayload.data).not.toHaveProperty('photo');
    const itemId = new URL(page.url()).pathname.split('/').at(-1)!;
    const photos = await itemImageList(page, itemId);
    expect(photos).toHaveLength(1);
    expect(photos[0]).toMatchObject({ is_primary: true, thumbnail_mime_type: 'image/webp', display_mime_type: 'image/webp' });
    const thumbnailPath = new URL(String(photos[0]!.thumbnail_url)).pathname;
    const displayPath = new URL(String(photos[0]!.display_url)).pathname;
    expect(thumbnailPath).toMatch(new RegExp(`/api/item-images/${photos[0]!.id}/thumbnail$`));
    expect(displayPath).toMatch(new RegExp(`/api/item-images/${photos[0]!.id}/display$`));
    expect(thumbnailPath).not.toContain('/storage/');
    expect(displayPath).not.toContain('/storage/');

    const imageHeaders = {
        Accept: 'image/avif,image/webp,*/*',
        Origin: 'http://127.0.0.1:8000',
        Referer: 'http://127.0.0.1:8000/',
    };
    const thumbnail = await page.request.get(String(photos[0]!.thumbnail_url), { headers: imageHeaders });
    const thumbnailBody = await thumbnail.body();
    expect(thumbnail.status(), JSON.stringify({ status: thumbnail.status(), headers: thumbnail.headers(), bodyBytes: thumbnailBody.length })).toBe(200);
    expect(thumbnail.headers()['content-type']).toContain('image/');
    const display = await page.request.get(String(photos[0]!.display_url), { headers: imageHeaders });
    const displayBody = await display.body();
    expect(display.status(), JSON.stringify({ status: display.status(), headers: display.headers(), bodyBytes: displayBody.length })).toBe(200);
    expect(display.headers()['content-type']).toContain('image/');
    await expect(page.getByRole('region', { name: 'Photos', exact: true })).toContainText('Primary photo');
    await expect.poll(() => page.getByRole('img', { name: 'Primary item photo', exact: true }).evaluate((image) => (image as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
    await page.screenshot({ path: testInfo.outputPath('new-item-primary-photo-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    await expect(page.getByRole('heading', { level: 1, name: itemName, exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-primary-photo-mobile.png'), fullPage: true });
});

test('retries a failed optional photo upload without creating a second item', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Water filters ${uniqueId}`;
    await registerHousehold(page, uniqueId);
    const requests = watchCreateRequests(page);
    let rejectedFirstPhotoUpload = false;
    await page.route('**/api/items/*/images', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        if (rejectedFirstPhotoUpload) {
            await route.continue();
            return;
        }
        rejectedFirstPhotoUpload = true;
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ errors: [{ code: 'validation_failed', detail: 'The photo could not be uploaded. Try again.' }] }),
        });
    });

    await openCreateItemPanel(page, itemName);
    await page.screenshot({ path: testInfo.outputPath('new-item-retry-create-form-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-retry-create-form-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.getByLabel('Primary photo (optional)', { exact: true }).setInputFiles({
        name: 'water-filter.png',
        mimeType: 'image/png',
        buffer: await primaryPhotoFixture(page, 'WATER FILTER'),
    });
    const creationResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/items');
    await page.getByRole('button', { name: 'Save item', exact: true }).click();
    const createdItem = (await creationResponse).json().then((body) => body.data as { id: string });
    await expect(page.getByRole('button', { name: 'Retry photo upload', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue without photo', exact: true })).toBeVisible();
    expect(new URL(page.url()).pathname).toBe('/inventory');
    await expect(page.getByRole('heading', { level: 2, name: itemName, exact: true })).toBeVisible();
    expect(requests.items).toHaveLength(1);
    const itemId = (await createdItem).id;
    const itemPayload = requests.items[0]!.postDataJSON() as { data: Record<string, unknown> };
    expect(itemPayload.data).not.toHaveProperty('quantity');
    expect(itemPayload.data).not.toHaveProperty('image');

    await page.screenshot({ path: testInfo.outputPath('new-item-photo-recovery-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-photo-recovery-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });

    const retryResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `/api/items/${itemId}/images`);
    await page.getByRole('button', { name: 'Retry photo upload', exact: true }).click();
    expect((await retryResponse).status()).toBe(201);
    await expect(page).toHaveURL(`/inventory/${itemId}`);
    await expect(page.getByRole('button', { name: 'Retry photo upload', exact: true })).toHaveCount(0);
    expect(requests.items).toHaveLength(1);
    expect(requests.photos).toHaveLength(2);
    const savedItem = await page.request.get(`/api/items/${itemId}`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(savedItem.ok()).toBe(true);
    expect((await savedItem.json()).data.id).toBe(itemId);
    const photos = await itemImageList(page, itemId);
    expect(photos).toHaveLength(1);
    expect(photos[0]).toMatchObject({ is_primary: true, thumbnail_mime_type: 'image/webp', display_mime_type: 'image/webp' });
    await expect(page.getByRole('region', { name: 'Photos', exact: true })).toContainText('Primary photo');
    await page.screenshot({ path: testInfo.outputPath('new-item-photo-retried-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-photo-retried-mobile.png'), fullPage: true });
});

test('continues without a failed optional photo and preserves the add-stock intent', async ({ page }, testInfo) => {
    test.setTimeout(60_000);
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Dish soap ${uniqueId}`;
    await registerHousehold(page, uniqueId);
    const kitchen = await createLocation(page, 'Kitchen');
    const location = await createLocation(page, 'Shelf', kitchen.id);
    const requests = watchCreateRequests(page);
    await page.route('**/api/items/*/images', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        await route.fulfill({
            status: 422,
            contentType: 'application/json',
            body: JSON.stringify({ errors: [{ code: 'validation_failed', detail: 'The photo could not be uploaded. Try again.' }] }),
        });
    });

    await openCreateItemPanel(page, itemName);
    await page.getByLabel('Primary photo (optional)', { exact: true }).setInputFiles({
        name: 'dish-soap.png',
        mimeType: 'image/png',
        buffer: await primaryPhotoFixture(page, 'DISH SOAP'),
    });
    const creationResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/items');
    await page.getByRole('button', { name: 'Save and add stock', exact: true }).click();
    const createdItem = (await creationResponse).json().then((body) => body.data as { id: string });
    await expect(page.getByRole('button', { name: 'Continue without photo', exact: true })).toBeVisible();
    expect(requests.items).toHaveLength(1);
    const itemPayload = requests.items[0]!.postDataJSON() as { data: Record<string, unknown> };
    expect(itemPayload.data).not.toHaveProperty('quantity');
    expect(itemPayload.data).not.toHaveProperty('image');
    const savedItemId = (await createdItem).id;
    await page.getByRole('button', { name: 'Continue without photo', exact: true }).click();
    await expect(page).toHaveURL(/\/inventory\/\d+\?restock=1$/);
    await expect(page.locator('#new-level-location')).toBeVisible();
    const createdItemId = new URL(page.url()).pathname.split('/').at(-1)!;
    expect(createdItemId).toBe(savedItemId);
    await page.locator('#new-level-location').selectOption({ label: 'Kitchen / Shelf' });
    await page.locator('#new-level-quantity').fill('4');
    await expect(page.getByText('Restock 4 packs at Kitchen / Shelf.', { exact: true })).toBeVisible();
    await page.getByLabel('I’ve checked this direction and quantity.').check();
    const movementResponse = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith('/api/inventory-movements'));
    await page.getByRole('button', { name: 'Save change', exact: true }).click();
    const restockResponse = await movementResponse;
    expect(restockResponse.status()).toBe(201);
    expect(restockResponse.request().postDataJSON()).toEqual({ data: { movement_type: 'restock', item_id: createdItemId, location_id: location.id, quantity: 4 } });
    await expect(page.getByRole('status').filter({ hasText: 'Stock updated.' })).toBeVisible();
    expect(requests.items).toHaveLength(1);
    expect(requests.photos).toHaveLength(1);
    expect(await itemImageList(page, createdItemId)).toHaveLength(0);
    const itemResponse = await page.request.get(`/api/items/${createdItemId}`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(itemResponse.ok()).toBe(true);
    expect((await itemResponse.json()).data.id).toBe(createdItemId);
    const movements = await movementHistory(page, createdItemId);
    expect(movements).toHaveLength(1);
    expect(movements[0]).toMatchObject({ movement_type: 'restock', entries: [{ quantity_delta: 4, balance_after: 4, location: { id: location.id, name: 'Shelf' } }] });
    await expect(page.getByRole('heading', { level: 1, name: itemName, exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Stock by location', exact: true })).toContainText('4 packs');
    await page.screenshot({ path: testInfo.outputPath('new-item-without-photo-stock-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await page.screenshot({ path: testInfo.outputPath('new-item-without-photo-stock-mobile.png'), fullPage: true });
});
