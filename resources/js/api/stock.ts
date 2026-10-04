import { http } from '../lib/http';
import type { InventoryItem } from './items';

export type Location = {
    id: string;
    name: string;
    description: string | null;
    parent?: Location | null;
};

export function buildLocationPath(location: Location, locations: Location[]): string {
    const locationsById = new Map(locations.map((candidate) => [candidate.id, candidate]));

    function resolvePath(current: Location, visited: Set<string>): string {
        if (visited.has(current.id)) {
            return current.name;
        }

        visited.add(current.id);
        const parentId = current.parent?.id;
        const parent = parentId ? locationsById.get(parentId) ?? current.parent ?? null : null;

        return parent ? `${resolvePath(parent, visited)} / ${current.name}` : current.name;
    }

    return resolvePath(locationsById.get(location.id) ?? location, new Set());
}

export type InventoryLevel = {
    id: string;
    quantity: number;
    alert_threshold: number | null;
    stock_status: string;
    alert_status: 'unmonitored' | 'above_threshold' | 'below_threshold' | string;
    location: Location;
};

export type StockItem = InventoryItem & {
    total_quantity: number;
    inventory_levels: InventoryLevel[];
};

export type PageCollection<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
};

type Resource<T> = { data: T };

export type MovementEntry = {
    id: string;
    location: Location;
    quantity_delta: number;
    balance_after: number;
};

export type InventoryMovement = {
    id: string;
    movement_type: string;
    recorded_at: string;
    item: InventoryItem;
    entries: MovementEntry[];
    recorded_by: { id: string; name: string; email?: string } | null;
};

export type MovementFilters = {
    page: number;
    perPage: number;
    itemId: string;
    locationId: string;
    locationMode: 'either' | 'from' | 'to';
    movementType: string;
    recordedBy?: string;
    recordedFrom?: string;
    recordedUntil?: string;
};

export type MovementRecorder = { type: 'users'; id: string; name: string };

export type InventoryAlert = {
    id: string;
    alert_type: 'buy_soon' | string;
    created_at: string;
    resolved_at: string | null;
    item: InventoryItem;
};

export type AlertListParams = { page: number; perPage: number };
export type InventoryAlertStatus = 'empty' | 'low' | 'triggered';

export async function getTriggeredLevels(params: AlertListParams, status: InventoryAlertStatus): Promise<PageCollection<InventoryLevel & { item: InventoryItem }>> {
    const response = await http.get<PageCollection<InventoryLevel & { item: InventoryItem }>>('/inventory-levels', {
        params: { 'filter[alert_status]': status, include: 'item,location', per_page: params.perPage, page: params.page },
    });

    return response.data;
}

export async function getActiveAlerts(params: AlertListParams, itemId?: string): Promise<PageCollection<InventoryAlert>> {
    const response = await http.get<PageCollection<InventoryAlert>>('/inventory-alerts', {
        params: {
            'filter[status]': 'active',
            ...(itemId ? { 'filter[item_id]': itemId } : {}),
            include: 'item', per_page: params.perPage, page: params.page,
        },
    });

    return response.data;
}

export async function createBuySoon(itemId: string): Promise<InventoryAlert> {
    const response = await http.post<Resource<InventoryAlert>>('/inventory-alerts', { data: { item_id: itemId, alert_type: 'buy_soon' } });

    return response.data.data;
}

export async function resolveAlert(alertId: string): Promise<InventoryAlert> {
    const response = await http.post<Resource<InventoryAlert>>(`/inventory-alerts/${alertId}/resolve`);

    return response.data.data;
}

export async function getLocations(page: number): Promise<PageCollection<Location>> {
    const response = await http.get<PageCollection<Location>>('/locations', {
        params: { include: 'parent', per_page: 100, page },
    });

    return response.data;
}

export async function createLocation(data: { name: string; parent_id: string | null; description: string | null }): Promise<Location> {
    const response = await http.post<Resource<Location>>('/locations', { data });

    return response.data.data;
}

export async function updateThreshold(id: string, alertThreshold: number | null): Promise<void> {
    await http.patch(`/inventory-levels/${id}`, { data: { alert_threshold: alertThreshold } });
}

export async function recordMovement(data: Record<string, string | number>): Promise<void> {
    await http.post('/inventory-movements', { data });
}

export async function getMovements(filters: MovementFilters): Promise<PageCollection<InventoryMovement>> {
    const response = await http.get<PageCollection<InventoryMovement>>('/inventory-movements', {
        params: {
            include: 'item,entries.location,recorded_by',
            per_page: filters.perPage,
            page: filters.page,
            ...(filters.itemId ? { 'filter[item_id]': filters.itemId } : {}),
            ...(filters.locationId ? { [`filter[${filters.locationMode === 'either' ? 'location_id' : `${filters.locationMode}_location_id`}]`]: filters.locationId } : {}),
            ...(filters.movementType ? { 'filter[movement_type]': filters.movementType } : {}),
            ...(filters.recordedBy ? { 'filter[recorded_by]': filters.recordedBy } : {}),
            ...(filters.recordedFrom ? { 'filter[recorded_from]': filters.recordedFrom } : {}),
            ...(filters.recordedUntil ? { 'filter[recorded_until]': filters.recordedUntil } : {}),
        },
    });

    return response.data;
}

export async function getMovementRecorders(page: number): Promise<PageCollection<MovementRecorder>> {
    const response = await http.get<PageCollection<MovementRecorder>>('/inventory-movement-recorders', {
        params: { per_page: 100, page },
    });

    return response.data;
}
