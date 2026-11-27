import type { LocationQuery, LocationQueryRaw } from 'vue-router';

export type ItemBrowseRouteState = {
    search: string;
    categoryId: string;
    locationId: string;
    stockStatus?: 'in_stock' | 'empty';
    attentionStatus?: 'needs_attention' | 'empty' | 'low' | 'buy_soon' | 'none';
    sort: 'name' | '-name';
    page: number;
    perPage: 10 | 25 | 50 | 100;
};

function queryValue(value: LocationQuery[string] | LocationQueryRaw[string]): string | undefined {
    return typeof value === 'string' ? value : undefined;
}

export function readItemBrowseRouteState(query: LocationQuery | LocationQueryRaw): ItemBrowseRouteState {
    const search = queryValue(query.search) ?? '';
    const categoryId = queryValue(query.category);
    const locationId = queryValue(query.location);
    const stockStatus = queryValue(query.stockStatus);
    const attentionStatus = queryValue(query.attentionStatus);
    const sort = queryValue(query.sort);
    const page = Number(queryValue(query.page));
    const perPage = Number(queryValue(query.perPage));

    return {
        search,
        categoryId: categoryId ?? '',
        locationId: locationId ?? '',
        stockStatus: stockStatus === 'in_stock' || stockStatus === 'empty' ? stockStatus : undefined,
        attentionStatus: attentionStatus === 'needs_attention' || attentionStatus === 'empty' || attentionStatus === 'low' || attentionStatus === 'buy_soon' || attentionStatus === 'none' ? attentionStatus : undefined,
        sort: sort === '-name' ? '-name' : 'name',
        page: Number.isInteger(page) && page > 0 ? page : 1,
        perPage: perPage === 25 || perPage === 50 || perPage === 100 ? perPage : 10,
    };
}

export function readItemBrowseResultLastPage(query: LocationQuery | LocationQueryRaw): number | undefined {
    const lastPage = Number(queryValue(query.resultLastPage));

    return Number.isInteger(lastPage) && lastPage > 0 ? lastPage : undefined;
}

export function itemBrowseRouteQuery(state: ItemBrowseRouteState, resultIds: string[], resultLastPage?: number): LocationQueryRaw {
    return {
        ...(state.search ? { search: state.search } : {}),
        ...(state.categoryId ? { category: state.categoryId } : {}),
        ...(state.locationId ? { location: state.locationId } : {}),
        ...(state.stockStatus ? { stockStatus: state.stockStatus } : {}),
        ...(state.attentionStatus ? { attentionStatus: state.attentionStatus } : {}),
        ...(state.sort !== 'name' ? { sort: state.sort } : {}),
        ...(state.page !== 1 ? { page: String(state.page) } : {}),
        ...(state.perPage !== 10 ? { perPage: String(state.perPage) } : {}),
        ...(resultIds.length ? { resultIds: resultIds.join(',') } : {}),
        ...(resultLastPage && resultLastPage > 0 ? { resultLastPage: String(resultLastPage) } : {}),
    };
}

export type ItemContextKind = 'category' | 'location';

export function readContextListState(query: LocationQueryRaw) {
    const { page, perPage, search } = readItemBrowseRouteState(query);
    const children = readItemBrowseRouteState({ page: query.childrenPage, perPage: query.childrenPerPage });
    return { page, perPage, search, childrenPage: children.page, childrenPerPage: children.perPage };
}

export function contextListQuery(kind: ItemContextKind, state: { page: number; perPage: number; search: string; childrenPage: number; childrenPerPage: number }): Record<string, string> {
    return {
        ...(state.page > 1 ? { page: String(state.page) } : {}),
        ...(state.perPage !== 10 ? { perPage: String(state.perPage) } : {}),
        ...(kind === 'category' && state.search ? { search: state.search } : {}),
        ...(kind === 'location' && state.childrenPage > 1 ? { childrenPage: String(state.childrenPage) } : {}),
        ...(kind === 'location' && state.childrenPerPage !== 10 ? { childrenPerPage: String(state.childrenPerPage) } : {}),
    };
}

export function itemBackDestination(query: LocationQueryRaw) {
    const kind = query.origin;
    const id = query.originId;
    if ((kind === 'location' || kind === 'category') && typeof id === 'string' && /^[1-9]\d*$/.test(id)) {
        const listQuery = contextListQuery(kind, readContextListState(query));
        return { name: kind + '-detail', params: { [kind]: id }, query: listQuery };
    }
    const ids = typeof query.resultIds === 'string' ? query.resultIds.split(',').filter(Boolean) : [];
    return { name: 'inventory', query: itemBrowseRouteQuery(readItemBrowseRouteState(query), ids, readItemBrowseResultLastPage(query)) };
}
