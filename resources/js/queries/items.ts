import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { createItem, getItems, type ItemListParams, type NewItem } from '../api/items';

export const itemQueryKeys = {
    all: ['items'] as const,
    lists: () => [...itemQueryKeys.all, 'list'] as const,
    list: (params: ItemListParams) => [...itemQueryKeys.lists(), params] as const,
};

export function useItemsQuery(params: ComputedRef<ItemListParams>) {
    return useQuery({
        queryKey: computed(() => itemQueryKeys.list(params.value)),
        queryFn: () => getItems(params.value),
    });
}

export function useCreateItemMutation() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (item: NewItem) => createItem(item),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: itemQueryKeys.all });
        },
    });
}
