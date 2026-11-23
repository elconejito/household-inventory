import { expect, test } from '@playwright/test';

test('opens global search from the keyboard and finds all three resource types', async ({ page }) => {
    await page.route('**/api/user?**', (route) => route.fulfill({ json: { data: {
        id: '1', name: 'Search Owner', email: 'search@example.test',
        membership: { role: 'owner', household: { id: '1', name: 'Search Home' } },
    } } }));

    for (const [resource, name] of [
        ['items', 'Kitchen towels'],
        ['locations', 'Kitchen pantry'],
        ['categories', 'Kitchen supplies'],
    ]) {
        await page.route(`**/api/${resource}?**`, (route) => {
            const query = new URL(route.request().url()).searchParams;
            const matches = query.get('filter[search]') === 'kitchen';
            const pageNumber = Number(query.get('page') ?? 1);
            return route.fulfill({ json: {
                data: matches ? [{ id: pageNumber === 2 ? '8' : '7', name: pageNumber === 2 ? `${name} 2` : name }] : [],
                meta: { current_page: pageNumber, last_page: matches ? 2 : 1, total: matches ? 6 : 0 },
            } });
        });
    }

    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto('/categories');
    await expect(page.getByRole('heading', { name: 'Categories', exact: true, level: 1 })).toBeVisible();

    await page.locator('.mobile-more > summary').click();
    await expect(page.locator('.mobile-more-panel')).toContainText('Account & settings');
    await page.keyboard.press('Escape');

    await page.locator('header details > summary').click();
    await expect(page.getByText('Search Owner')).toBeVisible();
    await expect(page.locator('header').getByRole('link', { name: 'Settings' })).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: 'Search all' }).click();
    await expect(page.getByRole('dialog', { name: 'Search all' })).toBeVisible();
    await page.getByRole('button', { name: 'Close search' }).click();

    await page.keyboard.press('Control+k');
    const dialog = page.getByRole('dialog', { name: 'Search all' });
    const input = dialog.getByRole('searchbox');
    await expect(input).toBeFocused();
    await input.fill('kitchen');

    await expect(dialog.getByRole('link', { name: 'Kitchen towels' })).toHaveAttribute('href', '/items/7');
    await expect(dialog.getByRole('link', { name: 'Kitchen pantry' })).toHaveAttribute('href', '/locations/7');
    await expect(dialog.getByRole('link', { name: 'Kitchen supplies' })).toHaveAttribute('href', '/categories/7');

    await dialog.getByRole('link', { name: 'View all matches in items' }).click();
    await expect(page).toHaveURL('/items?search=kitchen');
    await expect(page.getByRole('searchbox', { name: 'Search items' })).toHaveValue('kitchen');

    await page.keyboard.press('Control+k');
    const locationsDialog = page.getByRole('dialog', { name: 'Search all' });
    await locationsDialog.getByRole('searchbox').fill('kitchen');
    await locationsDialog.getByRole('link', { name: 'View all matches in locations' }).click();
    await expect(page).toHaveURL('/locations?search=kitchen');
    await expect(page.getByRole('searchbox', { name: 'Search all locations' })).toHaveValue('kitchen');

    await page.keyboard.press('Control+k');
    const categoriesDialog = page.getByRole('dialog', { name: 'Search all' });
    await categoriesDialog.getByRole('searchbox').fill('kitchen');

    const categoryResults = categoriesDialog.getByRole('region', { name: 'Categories' });
    await categoryResults.getByRole('button', { name: 'Next' }).click();
    await expect(categoryResults.getByRole('link', { name: 'Kitchen supplies 2' })).toHaveAttribute('href', '/categories/8');
    await categoriesDialog.getByRole('link', { name: 'View all matches in categories' }).click();
    await expect(categoriesDialog).toHaveCount(0);
    await expect(page).toHaveURL('/categories?search=kitchen');
    await expect(page.getByRole('searchbox', { name: 'Search categories' })).toHaveValue('kitchen');

    await page.keyboard.press('Control+k');
    const reopenedDialog = page.getByRole('dialog', { name: 'Search all' });
    await reopenedDialog.getByRole('searchbox').fill('kitchen');
    await expect(reopenedDialog.getByRole('link', { name: 'Kitchen supplies' })).toBeVisible();
    await page.keyboard.press('ArrowDown');
    await expect(reopenedDialog.getByRole('link', { name: 'Kitchen towels' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(reopenedDialog.getByRole('link', { name: 'Kitchen pantry' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/locations/7');
});
