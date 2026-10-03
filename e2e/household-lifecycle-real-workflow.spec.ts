import { expect, test, type Page } from '@playwright/test';

test.skip(process.env.PLAYWRIGHT_REAL_API !== '1', 'Run against the dedicated MySQL test database with PLAYWRIGHT_REAL_API=1.');

async function api(page: Page, method: string, path: string, data?: Record<string, unknown>) {
    const csrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    return page.request.fetch(`/api/${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            Origin: 'http://127.0.0.1:8000',
            Referer: 'http://127.0.0.1:8000/',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) } : {}),
        },
        ...(data ? { data: { data } } : {}),
    });
}

test('invites a member, revokes and restores their access, and permanently deletes archived inventory', async ({ page, browser }, testInfo) => {
    const unique = `${Date.now()}-${Math.floor(Math.random() * 100_000)}`;
    const memberEmail = `member-${unique}@example.test`;
    const itemName = `Paper towels ${unique}`;
    await page.goto('/register');
    await page.getByLabel('Your name').fill('Household Owner');
    await page.getByLabel('Household name').fill(`Shared Home ${unique}`);
    await page.getByLabel('Email address').fill(`owner-${unique}@example.test`);
    await page.getByLabel('Password', { exact: true }).fill('Shared-home-Password-1');
    await page.getByLabel('Confirm password').fill('Shared-home-Password-1');
    await page.getByRole('button', { name: 'Create account', exact: true }).click();
    await expect(page).toHaveURL('/');

    const itemResponse = await api(page, 'POST', 'items', { name: itemName, counting_unit: 'roll' });
    expect(itemResponse.status(), await itemResponse.text()).toBe(201);
    const item = (await itemResponse.json()).data;
    const locationResponse = await api(page, 'POST', 'locations', { name: 'Basement' });
    expect(locationResponse.status(), await locationResponse.text()).toBe(201);
    const location = (await locationResponse.json()).data;
    const stocked = await api(page, 'POST', 'inventory-movements', { item_id: item.id, location_id: location.id, movement_type: 'restock', quantity: 1 });
    expect(stocked.status(), await stocked.text()).toBe(201);

    await page.goto('/settings');
    await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
    const renamedHousehold = `Renamed Home ${unique}`;
    await page.getByLabel('Household name', { exact: true }).fill(renamedHousehold);
    await page.getByRole('button', { name: 'Save name', exact: true }).click();
    await expect(page.getByRole('heading', { name: renamedHousehold, exact: true })).toBeVisible();
    await expect(page.locator('header')).toContainText(renamedHousehold);
    await page.getByLabel('Role for Household Owner').selectOption('member');
    await expect(page.getByRole('alert')).toContainText('at least one active owner');
    await expect(page.getByLabel('Role for Household Owner')).toHaveValue('owner');
    await page.getByLabel('Invitation email').fill(memberEmail);
    await page.getByRole('button', { name: 'Create invitation', exact: true }).click();
    const invitationLink = page.locator('#one-time-invitation-link');
    await expect(invitationLink).toBeVisible();
    const oldLink = await invitationLink.inputValue();
    await page.getByRole('button', { name: 'Resend', exact: true }).click();
    await expect.poll(() => invitationLink.inputValue()).not.toBe(oldLink);
    const link = await invitationLink.inputValue();
    const invitations = await api(page, 'GET', 'household-invitations');
    expect(JSON.stringify(await invitations.json())).not.toContain(new URL(link).hash.slice(7));

    const memberContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8000' });
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(link);
        await expect(memberPage).not.toHaveURL(/#token=/);
        await memberPage.getByLabel('Your name').fill('Household Member');
        await memberPage.getByLabel('Email address').fill(memberEmail);
        await memberPage.getByLabel('Password', { exact: true }).fill('Shared-home-Password-2');
        await memberPage.getByLabel('Confirm password').fill('Shared-home-Password-2');
        await memberPage.getByRole('button', { name: 'Create account and join', exact: true }).click();
        await expect(memberPage).toHaveURL('/');
        await memberPage.goto(`/inventory/${item.id}`);
        await memberPage.getByRole('button', { name: 'Use 1', exact: true }).click();
        await expect(memberPage.getByRole('status').filter({ hasText: 'Used 1 roll' })).toBeVisible();
        await memberPage.goto('/settings');
        await expect(memberPage.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible();
        await expect(memberPage.getByRole('button', { name: 'Create invitation', exact: true })).toHaveCount(0);
        expect((await api(memberPage, 'PATCH', 'household', { name: 'Not allowed' })).status()).toBe(403);

        await page.reload();
        const memberRow = page.getByRole('listitem').filter({ hasText: memberEmail });
        await expect(memberRow).toBeVisible();
        page.once('dialog', (dialog) => dialog.accept());
        await memberRow.getByRole('button', { name: 'Remove', exact: true }).click();
        await expect(memberRow).toHaveCount(0);
        expect((await api(memberPage, 'GET', 'items')).status()).toBe(403);
        await memberPage.reload();
        await expect(memberPage).toHaveURL('/invitations/accept');
        await expect(memberPage.getByRole('link', { name: 'Inventory', exact: true })).toHaveCount(0);

        await page.getByRole('combobox', { name: /Show/ }).selectOption('only');
        await expect(memberRow).toBeVisible();
        await memberRow.getByRole('button', { name: 'Restore', exact: true }).click();
        await expect(memberRow).toHaveCount(0);
        expect((await api(memberPage, 'GET', 'items')).status()).toBe(200);

        await page.goto(`/inventory/${item.id}`);
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Archive item', exact: true }).click();
        await expect(page).toHaveURL('/inventory');
        await page.goto('/settings/archives');
        const itemRow = page.getByRole('listitem').filter({ hasText: itemName });
        await expect(itemRow).toBeVisible();
        await itemRow.getByRole('button', { name: `Permanently delete ${itemName}`, exact: true }).click();
        await itemRow.getByLabel('Type DELETE to confirm').fill('DELETE');
        await page.screenshot({ path: testInfo.outputPath('archive-confirmation-desktop.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.screenshot({ path: testInfo.outputPath('archive-confirmation-mobile.png'), fullPage: true });
        await itemRow.getByRole('button', { name: 'Confirm permanent deletion', exact: true }).click();
        await expect(itemRow).toHaveCount(0);
        const remaining = await api(page, 'GET', 'items?filter[trashed]=with');
        expect((await remaining.json()).data).toEqual([]);
        const history = await api(page, 'GET', `inventory-movements?filter[item_id]=${item.id}`);
        expect((await history.json()).data).toEqual([]);
    } finally {
        await memberContext.close();
    }
});
