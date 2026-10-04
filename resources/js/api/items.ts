import { http } from '../lib/http';

export type ItemCategory = {
    id: string;
    name: string;
};

export type ItemThumbnail = { id: string; is_primary: boolean; thumbnail_url: string; caption: string | null };

export type ItemLocation = {
    id: string;
    name: string;
    description: string | null;
    parent?: ItemLocation | null;
};

export type InventoryItem = {
    id: string;
    name: string;
    counting_unit: string;
    counting_unit_plural?: string;
    description: string | null;
    total_quantity?: number;
    categories?: ItemCategory[];
    images?: ItemThumbnail[];
    inventory_levels?: Array<{
        id: string;
        quantity: number;
        alert_threshold: number | null;
        stock_status: string;
        alert_status: string;
        location: ItemLocation;
    }>;
};

export type ItemListParams = {
    search: string;
    categoryId?: string;
    locationId?: string;
    sort?: 'name' | '-name';
    page: number;
    perPage: number;
};

export type ItemListResponse = {
    data: InventoryItem[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        per_page: number;
        to: number | null;
        total: number;
    };
};

export type NewItem = {
    name: string;
    counting_unit: string;
    description: string | null;
    category_ids?: string[];
};

export type ItemUpdate = Partial<NewItem>;
export type ItemEditSnapshot = {
    id: string;
    name: string;
    counting_unit: string;
    description: string | null;
    category_ids: string[];
};

type CategoryPage = {
    data: ItemCategory[];
    meta: { last_page: number };
};

type ItemResponse = {
    data: InventoryItem & {
        total_quantity?: number;
        inventory_levels?: Array<{
            id: string;
            quantity: number;
            alert_threshold: number | null;
            stock_status: string;
            alert_status: string;
            location: ItemLocation;
        }>;
    };
};

export async function getItems(params: ItemListParams, include = 'categories,images'): Promise<ItemListResponse> {
    const response = await http.get<ItemListResponse>('/items', {
        params: {
            ...(params.search ? { 'filter[search]': params.search } : {}),
            ...(params.categoryId ? { 'filter[category_id]': params.categoryId } : {}),
            ...(params.locationId ? { 'filter[location_id]': params.locationId } : {}),
            page: params.page,
            per_page: params.perPage,
            sort: params.sort ?? 'name',
            include,
        },
    });

    return response.data;
}

export async function getItem(id: string): Promise<ItemResponse['data']> {
    const response = await http.get<ItemResponse>(`/items/${id}`, {
        params: { include: 'categories,inventory_levels.location' },
    });

    return response.data.data;
}

export async function createItem(item: NewItem): Promise<InventoryItem> {
    const response = await http.post<ItemResponse>('/items', { data: item });

    return response.data.data;
}

export async function updateItem(id: string, item: ItemUpdate): Promise<InventoryItem> {
    const response = await http.patch<ItemResponse>(`/items/${id}`, { data: item });

    return response.data.data;
}

export async function getItemCategoryOptions(): Promise<ItemCategory[]> {
    const firstPage = await http.get<CategoryPage>('/categories', { params: { per_page: 100, page: 1, sort: 'name' } });
    const remainingPages = await Promise.all(Array.from(
        { length: Math.max(0, firstPage.data.meta.last_page - 1) },
        (_, index) => http.get<CategoryPage>('/categories', { params: { per_page: 100, page: index + 2, sort: 'name' } }),
    ));

    return [...firstPage.data.data, ...remainingPages.flatMap((page) => page.data.data)];
}
