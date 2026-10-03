import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run with PLAYWRIGHT_REAL_API=1 against the dedicated MySQL test database.');

async function createResource(page: Page, path: string, data: Record<string, string | number | null>): Promise<{ id: string }> {
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

async function photoFixture(page: Page, label: string): Promise<Buffer> {
    const image = await page.evaluate((text) => {
        const canvas = document.createElement('canvas');
        canvas.width = 900;
        canvas.height = 700;
        const context = canvas.getContext('2d')!;
        context.fillStyle = '#ecf2ef';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.fillStyle = '#397766';
        context.fillRect(140, 100, 620, 500);
        context.fillStyle = '#ffffff';
        context.font = 'bold 42px sans-serif';
        context.textAlign = 'center';
        context.fillText(text, 450, 310);
        context.font = '28px sans-serif';
        context.fillText('Household supply', 450, 365);
        return canvas.toDataURL('image/png').split(',')[1]!;
    }, label);
    return Buffer.from(image, 'base64');
}

test('identifies an item with private photos and preserves editable multiline notes', async ({ page }, testInfo) => {
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const itemName = `Filter cartridge ${uniqueId}`;
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Context Tester');
    await page.getByLabel('Household name').fill(`Context Home ${uniqueId}`);
    await page.getByLabel('Email address').fill(`context-${uniqueId}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Context-workflow-Password-1');
    await page.getByLabel('Confirm password').fill('Context-workflow-Password-1');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/');

    const item = await createResource(page, 'items', { name: itemName, counting_unit: 'filter' });
    await page.goto(`/inventory/${item.id}`);
    await expect(page.getByRole('heading', { name: itemName, exact: true })).toBeVisible();

    const notes = page.getByRole('region', { name: 'Notes', exact: true });
    await notes.getByLabel('Note', { exact: true }).fill('Use cartridge A\nReplace every six months.\n\nKeep the label for reference.');
    await notes.getByRole('button', { name: 'Add note', exact: true }).click();
    await expect(notes).toContainText('Replace every six months.');
    await expect(notes).toContainText('Context Tester');
    await notes.getByRole('button', { name: 'Edit note', exact: true }).click();
    await notes.getByLabel('Edit note', { exact: true }).fill('Use cartridge A\nReplace every three months.\n\nKeep the label for reference.');
    await notes.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(notes).toContainText('edited');

    const photos = page.getByRole('region', { name: 'Photos', exact: true });
    await photos.locator('summary').click();
    await photos.getByLabel('Add a photo', { exact: true }).setInputFiles({ name: 'front.png', mimeType: 'image/png', buffer: await photoFixture(page, 'CARTRIDGE A') });
    await photos.getByLabel(/Caption/).fill('Front label');
    await photos.getByRole('button', { name: 'Upload photo', exact: true }).click();
    await expect(photos).toContainText('Front label');
    await photos.getByLabel('Add a photo', { exact: true }).setInputFiles({ name: 'instructions.png', mimeType: 'image/png', buffer: await photoFixture(page, 'INSTRUCTIONS') });
    await photos.getByLabel(/Caption/).fill('Rear instructions');
    await photos.getByRole('button', { name: 'Upload photo', exact: true }).click();
    await expect(photos).toContainText('Rear instructions');

    const rearPhoto = photos.getByRole('listitem').filter({ hasText: 'Rear instructions' });
    await rearPhoto.getByRole('button', { name: 'Make primary', exact: true }).click();
    await expect(photos.getByRole('button', { name: 'Enlarge Rear instructions', exact: true }).locator('..')).toContainText('Primary photo');
    await expect.poll(() => photos.getByRole('img', { name: 'Rear instructions', exact: true }).evaluate((image) => (image as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
    await page.reload();
    await expect(notes).toContainText('Replace every three months.');
    await expect(photos.getByRole('button', { name: 'Enlarge Rear instructions', exact: true }).locator('..')).toContainText('Primary photo');
    await photos.getByRole('button', { name: 'Enlarge Rear instructions', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Enlarged photo' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Enlarged photo' })).not.toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('item-context-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: testInfo.outputPath('item-context-mobile.png'), fullPage: true });
    page.once('dialog', (dialog) => dialog.accept());
    await photos.getByRole('button', { name: 'Remove photo', exact: true }).click();
    await expect(photos.getByRole('button', { name: 'Enlarge Front label', exact: true }).locator('..')).toContainText('Primary photo');

    const persisted = await page.request.get(`/api/items/${item.id}/notes?include=created_by`, {
        headers: { Accept: 'application/json', Origin: 'http://127.0.0.1:8000', Referer: 'http://127.0.0.1:8000/' },
    });
    expect(persisted.ok()).toBe(true);
    expect((await persisted.json()).data[0].body).toBe('Use cartridge A\nReplace every three months.\n\nKeep the label for reference.');

    const category = await createResource(page, 'categories', { name: 'Filters' });
    const location = await createResource(page, 'locations', { name: 'Basement', description: 'Replacement cartridges and other supplies.' });
    for (const context of [{ type: 'categories', id: category.id, body: 'Keep compatible filters together.' }, { type: 'locations', id: location.id, body: 'Check the shelf beside the stairs.' }]) {
        await page.goto(`/${context.type}/${context.id}`);
        const contextNotes = page.getByRole('region', { name: 'Notes', exact: true });
        await contextNotes.getByLabel('Note', { exact: true }).fill(context.body);
        await contextNotes.getByRole('button', { name: 'Add note', exact: true }).click();
        await expect(contextNotes).toContainText(context.body);
    }

    await createResource(page, 'inventory-movements', { movement_type: 'restock', item_id: item.id, location_id: location.id, quantity: 1 });
    await page.goto('/activity');
    const activity = page.getByRole('region', { name: 'Inventory activity' });
    await activity.locator('summary').click();
    const movementNotes = activity.getByRole('region', { name: 'Notes', exact: true });
    await movementNotes.getByLabel('Note', { exact: true }).fill('Bought the compatible replacement cartridge.');
    await movementNotes.getByRole('button', { name: 'Add note', exact: true }).click();
    await expect(movementNotes).toContainText('Bought the compatible replacement cartridge.');

    await createResource(page, 'inventory-alerts', { item_id: item.id, alert_type: 'buy_soon' });
    await page.goto('/');
    const manualAlerts = page.getByRole('region', { name: 'Buy soon', exact: true });
    await manualAlerts.locator('summary').click();
    const alertNotes = manualAlerts.getByRole('region', { name: 'Notes', exact: true });
    await alertNotes.getByLabel('Note', { exact: true }).fill('Prepare a spare before winter.');
    await alertNotes.getByRole('button', { name: 'Add note', exact: true }).click();
    await expect(alertNotes).toContainText('Prepare a spare before winter.');
});
