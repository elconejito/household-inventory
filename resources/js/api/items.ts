import { http } from '../lib/http';

export type ItemCategory = {
    id: string;
    name: string;
};

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
};

export type ItemListParams = {
    search: string;
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

export async function getItems(params: ItemListParams): Promise<ItemListResponse> {
    const response = await http.get<ItemListResponse>('/items', {
        params: {
            ...(params.search ? { 'filter[search]': params.search } : {}),
            page: params.page,
            per_page: params.perPage,
            include: 'categories',
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
