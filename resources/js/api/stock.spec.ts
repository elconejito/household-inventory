import { describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { buildLocationPath, getMovements } from './stock';

vi.mock('../lib/http', () => ({ http: { get: vi.fn() } }));

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
});
