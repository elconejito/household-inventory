import { describe, expect, it } from 'vitest';
import { itemBackDestination, itemBrowseRouteQuery, readItemBrowseResultLastPage, readItemBrowseRouteState } from './item-browse-state';

describe('item browse route state', () => {
    it('round trips active search, filters, sort, page, and page size', () => {
        const state = {
            search: 'paper',
            categoryId: '4',
            locationId: '9',
            stockStatus: 'in_stock' as const,
            attentionStatus: 'buy_soon' as const,
            sort: '-name' as const,
            page: 3,
            perPage: 25 as const,
        };
        const query = itemBrowseRouteQuery(state, ['12', '13'], 4);

        expect(query).toEqual({
            search: 'paper',
            category: '4',
            location: '9',
            stockStatus: 'in_stock',
            attentionStatus: 'buy_soon',
            sort: '-name',
            page: '3',
            perPage: '25',
            resultIds: '12,13',
            resultLastPage: '4',
        });
        expect(readItemBrowseRouteState(query)).toEqual(state);
        expect(readItemBrowseResultLastPage(query)).toBe(4);
    });

    it('ignores invalid route state and uses browse defaults', () => {
        expect(readItemBrowseRouteState({ page: '0', perPage: '15', stockStatus: 'unknown', sort: 'other' })).toEqual({
            search: '',
            categoryId: '',
            locationId: '',
            stockStatus: undefined,
            attentionStatus: undefined,
            sort: 'name',
            page: 1,
            perPage: 10,
        });
        expect(readItemBrowseResultLastPage({ resultLastPage: '0' })).toBeUndefined();
    });
    it.each([
        { origin: 'https://example.org', originId: '9' },
        { origin: 'location', originId: '//example.org/path' },
        { origin: 'category', originId: ['7', '9'] },
        { origin: 'location', originId: '0' },
        {},
    ])('returns to inventory for a missing or invalid origin', (query) => {
        expect(itemBackDestination(query)).toEqual({ name: 'inventory', query: {} });
    });

});
