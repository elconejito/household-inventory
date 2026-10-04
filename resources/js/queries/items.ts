import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { createItem, getItemCategoryOptions, getItems, updateItem, type ItemListParams, type ItemUpdate, type NewItem } from '../api/items';

export const itemQueryKeys = {
    all: ['items'] as const,
    lists: () => [...itemQueryKeys.all, 'list'] as const,
    list: (params: ItemListParams) => [...itemQueryKeys.lists(), params] as const,
};

export function useItemsQuery(params: ComputedRef<ItemListParams>) {
    return useQuery({
        queryKey: computed(() => itemQueryKeys.list(params.value)),
        queryFn: () => getItems(params.value, 'categories,images,inventory_levels.location,active_alerts'),
    });
}

export function useItemCategoryOptionsQuery() {
    return useQuery({
        queryKey: ['categories', 'item-options'] as const,
        queryFn: getItemCategoryOptions,
    });
}

async function invalidateItemReferences(queryClient: ReturnType<typeof useQueryClient>): Promise<void> {
    await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['items'] }),
        queryClient.invalidateQueries({ queryKey: ['categories'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory-levels'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory-alerts'] }),
        queryClient.invalidateQueries({ queryKey: ['inventory-movements'] }),
    ]);
}

export function useCreateItemMutation() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (item: NewItem) => createItem(item),
        onSuccess: async () => invalidateItemReferences(queryClient),
    });
}

export function useUpdateItemMutation() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, item }: { id: string; item: ItemUpdate }) => updateItem(id, item),
        onSuccess: async () => invalidateItemReferences(queryClient),
    });
}
