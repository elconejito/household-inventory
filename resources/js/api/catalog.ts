import { http } from '../lib/http';
import type { InventoryItem, ItemCategory, ItemLocation, ItemListResponse } from './items';
import type { InventoryLevel, PageCollection } from './stock';

export type CatalogCategory = ItemCategory;
export type CatalogLocation = ItemLocation;

export type CatalogListParams = {
    search: string;
    page: number;
    perPage: number;
};

export type LocationListParams = CatalogListParams & {
    parentId?: string;
};

export type CatalogInventoryLevel = InventoryLevel & {
    item: InventoryItem;
};

type Resource<T> = { data: T };

export async function getCategories(params: CatalogListParams): Promise<PageCollection<CatalogCategory>> {
    const response = await http.get<PageCollection<CatalogCategory>>('/categories', {
        params: {
            ...(params.search ? { 'filter[search]': params.search } : {}),
            sort: 'name',
            page: params.page,
            per_page: params.perPage,
        },
    });

    return response.data;
}

export async function createCategory(name: string): Promise<CatalogCategory> {
    const response = await http.post<Resource<CatalogCategory>>('/categories', { data: { name } });

    return response.data.data;
}

export async function updateCategory(id: string, changes: { name?: string }): Promise<CatalogCategory> {
    const response = await http.patch<Resource<CatalogCategory>>(`/categories/${id}`, { data: changes });

    return response.data.data;
}

export async function getCatalogLocations(params: LocationListParams): Promise<PageCollection<CatalogLocation>> {
    const response = await http.get<PageCollection<CatalogLocation>>('/locations', {
        params: {
            include: 'parent',
            ...(params.search ? { 'filter[search]': params.search } : {}),
            ...(!params.search && params.parentId ? { 'filter[parent_id]': params.parentId } : {}),
            sort: 'name',
            page: params.page,
            per_page: params.perPage,
        },
    });

    return response.data;
}

export async function createCatalogLocation(data: { name: string; description: string | null; parent_id: string | null }): Promise<CatalogLocation> {
    const response = await http.post<Resource<CatalogLocation>>('/locations', { data });

    return response.data.data;
}

export async function updateCatalogLocation(id: string, data: Partial<{ name: string; description: string | null; parent_id: string | null }>): Promise<CatalogLocation> {
    const response = await http.patch<Resource<CatalogLocation>>(`/locations/${id}`, { data });

    return response.data.data;
}

export async function getCategoryItems(categoryId: string, params: CatalogListParams): Promise<ItemListResponse> {
    const response = await http.get<ItemListResponse>('/items', {
        params: {
            'filter[category_id]': categoryId,
            ...(params.search ? { 'filter[search]': params.search } : {}),
            include: 'categories,images',
            page: params.page,
            per_page: params.perPage,
        },
    });

    return response.data;
}

export async function getLocationLevels(locationIds: string[], page: number, perPage = 10): Promise<PageCollection<CatalogInventoryLevel>> {
    const response = await http.get<PageCollection<CatalogInventoryLevel>>('/inventory-levels', {
        params: {
            'filter[location_id]': locationIds.join(','),
            include: 'item,location',
            page,
            per_page: perPage,
        },
    });

    return response.data;
}
