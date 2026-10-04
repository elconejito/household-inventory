import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { createCatalogLocation, createCategory, getCategories, getCatalogLocations, getCategoryAssignmentItems, getCategoryItems, getLocationLevels, setCategoryItemAssignment, updateCatalogLocation, updateCategory } from './catalog';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));

describe('catalog API', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } } as never);
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '3', name: 'Pantry' } } } as never);
        vi.mocked(http.patch).mockResolvedValue({ data: { data: { id: '3', name: 'Pantry' } } } as never);
    });

    it('requests searchable category pages with the selected size', async () => {
        await getCategories({ search: 'paper', page: 2, perPage: 25 });

        expect(http.get).toHaveBeenCalledWith('/categories', {
            params: { 'filter[search]': 'paper', sort: 'name', page: 2, per_page: 25 },
        });
    });

    it('browses root or direct child locations and searches the full hierarchy', async () => {
        await getCatalogLocations({ search: '', parentId: 'root', page: 1, perPage: 10 });
        expect(http.get).toHaveBeenLastCalledWith('/locations', {
            params: { include: 'parent', 'filter[parent_id]': 'root', sort: 'name', page: 1, per_page: 10 },
        });

        await getCatalogLocations({ search: 'pantry', parentId: '12', page: 1, perPage: 10 });
        expect(http.get).toHaveBeenLastCalledWith('/locations', {
            params: { include: 'parent', 'filter[search]': 'pantry', sort: 'name', page: 1, per_page: 10 },
        });
    });

    it('loads a paginated category item list and a selected location set with paths', async () => {
        await getCategoryItems('8', { search: 'soap', page: 2, perPage: 50 });
        expect(http.get).toHaveBeenLastCalledWith('/items', {
            params: { 'filter[category_id]': '8', 'filter[search]': 'soap', include: 'categories,images,inventory_levels.location,active_alerts', page: 2, per_page: 50 },
        });

        await getLocationLevels(['20', '21', '22'], 1, 25);
        expect(http.get).toHaveBeenLastCalledWith('/inventory-levels', {
            params: { 'filter[location_id]': '20,21,22', include: 'item.active_alerts,location', page: 1, per_page: 25 },
        });
    });

    it('loads every page of active item assignment options with category membership', async () => {
        vi.mocked(http.get)
            .mockResolvedValueOnce({ data: { data: [{ id: '1', name: 'Batteries', categories: [{ id: '8', name: 'Supplies' }] }], meta: { last_page: 2 } } } as never)
            .mockResolvedValueOnce({ data: { data: [{ id: '2', name: 'Soap', categories: [] }], meta: { last_page: 2 } } } as never);

        const items = await getCategoryAssignmentItems();

        expect(items.map((item) => item.id)).toEqual(['1', '2']);
        expect(http.get).toHaveBeenNthCalledWith(1, '/items', { params: { include: 'categories', page: 1, per_page: 100, sort: 'name' } });
        expect(http.get).toHaveBeenNthCalledWith(2, '/items', { params: { include: 'categories', page: 2, per_page: 100, sort: 'name' } });
    });

    it('changes only the requested category-item pivot assignment', async () => {
        vi.mocked(http.patch).mockResolvedValue({ data: { data: null } } as never);

        await setCategoryItemAssignment('8', '31', true);
        await setCategoryItemAssignment('8', '31', false);

        expect(http.patch).toHaveBeenNthCalledWith(1, '/categories/8/items/31', { data: { assigned: true } });
        expect(http.patch).toHaveBeenNthCalledWith(2, '/categories/8/items/31', { data: { assigned: false } });
    });

    it('sends category and location create and update payloads', async () => {
        await createCategory('Paper goods');
        expect(http.post).toHaveBeenCalledWith('/categories', { data: { name: 'Paper goods' } });
        await updateCategory('9', { name: 'Paper products' });
        expect(http.patch).toHaveBeenCalledWith('/categories/9', { data: { name: 'Paper products' } });

        const location = { name: 'Shelf', description: 'Top shelf', parent_id: '3' };
        await createCatalogLocation(location);
        expect(http.post).toHaveBeenCalledWith('/locations', { data: location });
        await updateCatalogLocation('3', location);
        expect(http.patch).toHaveBeenCalledWith('/locations/3', { data: location });
    });
});
