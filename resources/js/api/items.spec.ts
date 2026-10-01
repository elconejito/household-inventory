import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http } from '../lib/http';
import { getItems } from './items';

vi.mock('../lib/http', () => ({
    http: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

describe('item API client', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('requests the searched item page with categories included', async () => {
        vi.mocked(http.get).mockResolvedValue({
            data: { data: [], links: {}, meta: {} },
        });

        await getItems({ search: 'paper goods', page: 2, perPage: 10 });

        expect(http.get).toHaveBeenCalledWith('/items', {
            params: {
                'filter[search]': 'paper goods',
                page: 2,
                per_page: 10,
                include: 'categories',
            },
        });
    });
});
