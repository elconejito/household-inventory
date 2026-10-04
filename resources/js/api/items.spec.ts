import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { getItem, getItemCategoryOptions, getItems, updateItem } from './items';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    },
}));

describe('item API client', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('requests the searched item page with categories and primary images included', async () => {
        vi.mocked(http.get).mockResolvedValue({
            data: { data: [], links: {}, meta: {} },
        });

        await getItems({ search: 'paper goods', page: 2, perPage: 10 });

        expect(http.get).toHaveBeenCalledWith('/items', {
            params: {
                'filter[search]': 'paper goods',
                page: 2,
                per_page: 10,
                sort: 'name',
                include: 'categories,images',
            },
        });
    });

    it('sends exact category and location filters and selected sort direction', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], links: {}, meta: {} } } as never);

        await getItems({ search: '', categoryId: '4', locationId: '9', sort: '-name', page: 1, perPage: 25 });

        expect(http.get).toHaveBeenCalledWith('/items', {
            params: {
                'filter[category_id]': '4',
                'filter[location_id]': '9',
                page: 1,
                per_page: 25,
                sort: '-name',
                include: 'categories,images',
            },
        });
    });

    it('requests stock levels and active reminders for the item detail page', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: { id: '7', active_alerts: [] } } } as never);

        await getItem('7');

        expect(http.get).toHaveBeenCalledWith('/items/7', { params: { include: 'categories,inventory_levels.location,active_alerts' } });
    });

    it('loads every page of active category options', async () => {
        vi.mocked(http.get)
            .mockResolvedValueOnce({ data: { data: [{ id: '1', name: 'Cleaning' }], meta: { last_page: 2 } } } as never)
            .mockResolvedValueOnce({ data: { data: [{ id: '2', name: 'Food' }], meta: { last_page: 2 } } } as never);

        await expect(getItemCategoryOptions()).resolves.toEqual([
            { id: '1', name: 'Cleaning' },
            { id: '2', name: 'Food' },
        ]);
        expect(http.get).toHaveBeenNthCalledWith(1, '/categories', { params: { per_page: 100, page: 1, sort: 'name' } });
        expect(http.get).toHaveBeenNthCalledWith(2, '/categories', { params: { per_page: 100, page: 2, sort: 'name' } });
    });

    it('patches only the fields the editor selected for saving', async () => {
        vi.mocked(http.patch).mockResolvedValue({ data: { data: { id: '7', name: 'Soap' } } } as never);

        await updateItem('7', { name: 'Soap', category_ids: [] });

        expect(http.patch).toHaveBeenCalledWith('/items/7', { data: { name: 'Soap', category_ids: [] } });
    });
});
