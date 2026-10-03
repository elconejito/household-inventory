import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { getItem, getItems } from '../api/items';
import { createLocation, getLocations, getMovements, recordMovement, updateThreshold, type MovementFilters } from '../api/stock';

export const stockQueryKeys = {
    detail: (id: string) => ['items', 'detail', id] as const,
    locations: ['locations'] as const,
    movements: ['inventory-movements'] as const,
    movementList: (filters: MovementFilters) => ['inventory-movements', filters] as const,
};

export function useStockItemQuery(id: string | ComputedRef<string>) {
    const resolvedId = computed(() => typeof id === 'string' ? id : id.value);

    return useQuery({ queryKey: computed(() => stockQueryKeys.detail(resolvedId.value)), queryFn: () => getItem(resolvedId.value), enabled: computed(() => Boolean(resolvedId.value)) });
}

export function useLocationsQuery() {
    return useQuery({
        queryKey: stockQueryKeys.locations,
        queryFn: async () => {
            const firstPage = await getLocations(1);
            const rest = await Promise.all(Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) => getLocations(index + 2)));
            return [...firstPage.data, ...rest.flatMap((page) => page.data)];
        },
    });
}

export function useAllItemsQuery() {
    return useQuery({
        queryKey: ['items', 'activity-filter-options'] as const,
        queryFn: async () => {
            const firstPage = await getItems({ search: '', page: 1, perPage: 100 });
            const rest = await Promise.all(Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) => getItems({ search: '', page: index + 2, perPage: 100 })));
            return [...firstPage.data, ...rest.flatMap((page) => page.data)];
        },
    });
}

export function useMovementsQuery(filters: ComputedRef<MovementFilters>) {
    return useQuery({
        queryKey: computed(() => stockQueryKeys.movementList(filters.value)),
        queryFn: () => getMovements(filters.value),
    });
}

function useStockMutation<TVariables>(mutationFn: (variables: TVariables) => Promise<unknown>) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn,
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['items'] }),
                queryClient.invalidateQueries({ queryKey: ['inventory-levels'] }),
                queryClient.invalidateQueries({ queryKey: stockQueryKeys.locations }),
                queryClient.invalidateQueries({ queryKey: stockQueryKeys.movements }),
                queryClient.invalidateQueries({ queryKey: ['activity'] }),
            ]);
        },
    });
}

export const useRecordMovementMutation = () => useStockMutation(recordMovement);
export const useUpdateThresholdMutation = () => useStockMutation(({ id, alertThreshold }: { id: string; alertThreshold: number | null }) => updateThreshold(id, alertThreshold));
export const useCreateLocationMutation = () => useStockMutation(createLocation);
