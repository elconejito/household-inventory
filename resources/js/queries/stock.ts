import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { getItem, getItems } from '../api/items';
import { createBuySoon, createLocation, getActiveAlerts, getLocations, getMovementRecorders, getMovements, getTriggeredLevels, recordMovement, resolveAlert, updateThreshold, type AlertListParams, type InventoryAlertStatus, type MovementFilters } from '../api/stock';

export const stockQueryKeys = {
    detail: (id: string) => ['items', 'detail', id] as const,
    locations: ['locations'] as const,
    movements: ['inventory-movements'] as const,
    movementRecorders: ['inventory-movements', 'recorders'] as const,
    movementList: (filters: MovementFilters) => ['inventory-movements', filters] as const,
    triggeredLevels: (params: AlertListParams, status: InventoryAlertStatus) => ['inventory-levels', status, params] as const,
    activeAlerts: (params: AlertListParams, itemId?: string) => ['inventory-alerts', 'active', params, itemId] as const,
};

export function useTriggeredLevelsQuery(params: ComputedRef<AlertListParams>, status: InventoryAlertStatus) {
    return useQuery({ queryKey: computed(() => stockQueryKeys.triggeredLevels(params.value, status)), queryFn: () => getTriggeredLevels(params.value, status) });
}

export function useActiveAlertsQuery(params: ComputedRef<AlertListParams>, itemId?: ComputedRef<string>) {
    return useQuery({
        queryKey: computed(() => stockQueryKeys.activeAlerts(params.value, itemId?.value)),
        queryFn: () => getActiveAlerts(params.value, itemId?.value),
    });
}

export function useBuySoonMutation() {
    return useStockMutation(createBuySoon);
}

export function useResolveAlertMutation() {
    return useStockMutation(resolveAlert);
}

export function useStockItemQuery(id: string | ComputedRef<string>) {
    const resolvedId = computed(() => typeof id === 'string' ? id : id.value);

    return useQuery({ queryKey: computed(() => stockQueryKeys.detail(resolvedId.value)), queryFn: () => getItem(resolvedId.value), enabled: computed(() => Boolean(resolvedId.value)) });
}

export function useLocationsQuery(enabled: boolean | ComputedRef<boolean> = true) {
    return useQuery({
        queryKey: stockQueryKeys.locations,
        enabled,
        queryFn: async () => {
            const firstPage = await getLocations(1);
            const rest = await Promise.all(Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) => getLocations(index + 2)));
            return [...firstPage.data, ...rest.flatMap((page) => page.data)];
        },
    });
}

export function useAllItemsQuery(enabled: boolean | ComputedRef<boolean> = true) {
    return useQuery({
        queryKey: ['items', 'activity-filter-options'] as const,
        enabled,
        queryFn: async () => {
            const firstPage = await getItems({ search: '', page: 1, perPage: 100 }, '');
            const rest = await Promise.all(Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) => getItems({ search: '', page: index + 2, perPage: 100 }, '')));
            return [...firstPage.data, ...rest.flatMap((page) => page.data)];
        },
    });
}

export function useMovementsQuery(filters: ComputedRef<MovementFilters>, enabled: boolean | ComputedRef<boolean> = true) {
    return useQuery({
        queryKey: computed(() => stockQueryKeys.movementList(filters.value)),
        queryFn: () => getMovements(filters.value),
        enabled,
    });
}

export function useMovementRecordersQuery() {
    return useQuery({
        queryKey: stockQueryKeys.movementRecorders,
        queryFn: async () => {
            const firstPage = await getMovementRecorders(1);
            const rest = await Promise.all(Array.from({ length: Math.max(0, firstPage.meta.last_page - 1) }, (_, index) => getMovementRecorders(index + 2)));
            return [...firstPage.data, ...rest.flatMap((page) => page.data)];
        },
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
                queryClient.invalidateQueries({ queryKey: ['inventory-alerts'] }),
            ]);
        },
    });
}

export const useRecordMovementMutation = () => useStockMutation(recordMovement);
export const useUpdateThresholdMutation = () => useStockMutation(({ id, alertThreshold }: { id: string; alertThreshold: number | null }) => updateThreshold(id, alertThreshold));
export const useCreateLocationMutation = () => useStockMutation(createLocation);
