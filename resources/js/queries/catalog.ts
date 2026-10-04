import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { createCatalogLocation, createCategory, getCategories, getCatalogLocations, getCategoryItems, getLocationLevels, updateCatalogLocation, updateCategory, type CatalogListParams, type LocationListParams } from '../api/catalog';

export const catalogQueryKeys = {
    all: ['catalog'] as const,
    categoryLists: ['categories', 'catalog-list'] as const,
    categoryList: (params: CatalogListParams) => ['categories', 'catalog-list', params] as const,
    locationLists: ['locations', 'catalog-list'] as const,
    locationList: (params: LocationListParams) => ['locations', 'catalog-list', params] as const,
    categoryItems: (id: string, params: CatalogListParams) => ['items', 'category', id, params] as const,
    locationLevels: (ids: string[], params: CatalogListParams) => ['inventory-levels', 'locations', ids, params] as const,
};

export function useCategoriesCatalogQuery(params: ComputedRef<CatalogListParams>, enabled?: ComputedRef<boolean>) {
    return useQuery({
        queryKey: computed(() => catalogQueryKeys.categoryList(params.value)),
        queryFn: () => getCategories(params.value),
        enabled: enabled ?? true,
    });
}

export function useLocationsCatalogQuery(params: ComputedRef<LocationListParams>, enabled?: ComputedRef<boolean>) {
    return useQuery({
        queryKey: computed(() => catalogQueryKeys.locationList(params.value)),
        queryFn: () => getCatalogLocations(params.value),
        enabled: enabled ?? true,
    });
}

export function useCategoryItemsQuery(categoryId: ComputedRef<string>, params: ComputedRef<CatalogListParams>) {
    return useQuery({
        queryKey: computed(() => catalogQueryKeys.categoryItems(categoryId.value, params.value)),
        queryFn: () => getCategoryItems(categoryId.value, params.value),
        enabled: computed(() => Boolean(categoryId.value)),
    });
}

export function useLocationLevelsQuery(locationIds: ComputedRef<string[]>, params: ComputedRef<Pick<CatalogListParams, 'page' | 'perPage'>>) {
    const normalizedIds = computed(() => [...locationIds.value].sort());

    return useQuery({
        queryKey: computed(() => catalogQueryKeys.locationLevels(normalizedIds.value, { search: '', ...params.value })),
        enabled: computed(() => normalizedIds.value.length > 0),
        queryFn: () => getLocationLevels(normalizedIds.value, params.value.page, params.value.perPage),
    });
}

function useCatalogMutation<TData, TVariables>(mutationFn: (variables: TVariables) => Promise<TData>) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn,
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['catalog'] }),
                queryClient.invalidateQueries({ queryKey: ['items'] }),
                queryClient.invalidateQueries({ queryKey: ['categories'] }),
                queryClient.invalidateQueries({ queryKey: ['locations'] }),
                queryClient.invalidateQueries({ queryKey: ['inventory-levels'] }),
                queryClient.invalidateQueries({ queryKey: ['inventory-alerts'] }),
                queryClient.invalidateQueries({ queryKey: ['inventory-movements'] }),
                queryClient.invalidateQueries({ queryKey: ['activity'] }),
            ]);
        },
    });
}

export function useCreateCategoryMutation() {
    return useCatalogMutation(createCategory);
}

export function useUpdateCategoryMutation() {
    return useCatalogMutation(({ id, changes }: { id: string; changes: { name?: string } }) => updateCategory(id, changes));
}

export function useCreateCatalogLocationMutation() {
    return useCatalogMutation(createCatalogLocation);
}

export function useUpdateCatalogLocationMutation() {
    return useCatalogMutation(({ id, changes }: { id: string; changes: { name?: string; description?: string | null; parent_id?: string | null } }) => updateCatalogLocation(id, changes));
}
