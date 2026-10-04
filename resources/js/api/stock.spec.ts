import { describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { buildLocationPath, createBuySoon, getActiveAlerts, getMovementRecorders, getMovements, getTriggeredLevels, resolveAlert } from './stock';

vi.mock('../lib/http', () => ({ http: { get: vi.fn(), post: vi.fn() } }));

describe('stock activity API', () => {
    it('builds full hierarchy paths from the location collection', () => {
        const locations = [
            { id: '1', name: 'House', description: null, parent: null },
            { id: '2', name: 'Kitchen', description: null, parent: { id: '1', name: 'House', description: null } },
            { id: '3', name: 'Pantry', description: null, parent: { id: '2', name: 'Kitchen', description: null } },
        ];

        expect(buildLocationPath(locations[2], locations)).toBe('House / Kitchen / Pantry');
        expect(buildLocationPath({ id: '3', name: 'Pantry', description: null }, locations)).toBe('House / Kitchen / Pantry');
    });

    it('requests an allowed page size and uses the documented location filter names', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

        await getMovements({ page: 1, perPage: 10, itemId: '7', locationId: '4', locationMode: 'from', movementType: 'transfer' });

        expect(http.get).toHaveBeenCalledWith('/inventory-movements', {
            params: {
                include: 'item,entries.location,recorded_by',
                per_page: 10,
                page: 1,
                'filter[item_id]': '7',
                'filter[from_location_id]': '4',
                'filter[movement_type]': 'transfer',
            },
        });
    });

    it('sends recorder and UTC date bounds with movement filters', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

        await getMovements({ page: 1, perPage: 25, itemId: '', locationId: '', locationMode: 'either', movementType: '', recordedBy: '8', recordedFrom: '2026-10-01T04:00:00.000Z', recordedUntil: '2026-10-02T03:59:59.999Z' });

        expect(http.get).toHaveBeenCalledWith('/inventory-movements', { params: {
            include: 'item,entries.location,recorded_by', per_page: 25, page: 1,
            'filter[recorded_by]': '8', 'filter[recorded_from]': '2026-10-01T04:00:00.000Z', 'filter[recorded_until]': '2026-10-02T03:59:59.999Z',
        } });
    });

    it('loads recorder options using the privacy-safe paginated endpoint', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [{ type: 'users', id: '8', name: 'Sam' }], meta: { current_page: 1, last_page: 2, total: 101 } } });

        await getMovementRecorders(2);

        expect(http.get).toHaveBeenCalledWith('/inventory-movement-recorders', { params: { per_page: 100, page: 2 } });
    });
});

describe('dashboard alert API', () => {
    it('requests separate paginated automatic and manual alert collections', async () => {
        vi.mocked(http.get).mockResolvedValue({ data: { data: [], meta: { current_page: 2, last_page: 3, total: 25 } } });

        await getTriggeredLevels({ page: 2, perPage: 10 }, 'low');
        await getTriggeredLevels({ page: 1, perPage: 10 }, 'empty');
        await getActiveAlerts({ page: 1, perPage: 10 });
        await getActiveAlerts({ page: 1, perPage: 10 }, '42');

        expect(http.get).toHaveBeenNthCalledWith(1, '/inventory-levels', { params: { 'filter[alert_status]': 'low', include: 'item,location', per_page: 10, page: 2 } });
        expect(http.get).toHaveBeenNthCalledWith(2, '/inventory-levels', { params: { 'filter[alert_status]': 'empty', include: 'item,location', per_page: 10, page: 1 } });
        expect(http.get).toHaveBeenNthCalledWith(3, '/inventory-alerts', { params: { 'filter[status]': 'active', include: 'item', per_page: 10, page: 1 } });
        expect(http.get).toHaveBeenNthCalledWith(4, '/inventory-alerts', { params: { 'filter[status]': 'active', 'filter[item_id]': '42', include: 'item', per_page: 10, page: 1 } });
    });

    it('creates and resolves a Buy soon alert with the documented request bodies', async () => {
        vi.mocked(http.post).mockResolvedValue({ data: { data: { id: '8', alert_type: 'buy_soon' } } } as never);

        await createBuySoon('42');
        await resolveAlert('8');

        expect(http.post).toHaveBeenNthCalledWith(1, '/inventory-alerts', { data: { item_id: '42', alert_type: 'buy_soon' } });
        expect(http.post).toHaveBeenNthCalledWith(2, '/inventory-alerts/8/resolve');
    });
});
