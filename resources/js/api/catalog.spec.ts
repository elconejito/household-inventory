import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { createCatalogLocation, createCategory, getCategories, getCatalogLocations, getCategoryItems, getLocationLevels, updateCatalogLocation, updateCategory } from './catalog';

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
