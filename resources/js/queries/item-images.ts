import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { deleteItemImage, getItemImages, updateItemImage, uploadItemImage } from '../api/item-images';

export const itemImageQueryKey = (id: string) => ['item-images', id] as const;

export function useItemImagesQuery(itemId: ComputedRef<string>) {
    return useQuery({ queryKey: computed(() => itemImageQueryKey(itemId.value)), queryFn: () => getItemImages(itemId.value), enabled: computed(() => Boolean(itemId.value)) });
}

export function useItemImageMutations(itemId: ComputedRef<string>) {
    const client = useQueryClient();
    const refresh = async (contextId: string) => Promise.all([
        client.invalidateQueries({ queryKey: itemImageQueryKey(contextId) }),
        client.invalidateQueries({ queryKey: ['archived-child-records', 'item-images', 'items', contextId] }),
        client.invalidateQueries({ queryKey: ['items'] }),
    ]);
    return {
        upload: useMutation({ mutationFn: async ({ file, caption }: { file: File; caption: string }) => {
            const contextId = itemId.value;
            await uploadItemImage(contextId, file, caption);
            return contextId;
        }, onSuccess: refresh }),
        update: useMutation({ mutationFn: async ({ imageId, changes }: { imageId: string; changes: { caption?: string; is_primary?: true } }) => {
            const contextId = itemId.value;
            await updateItemImage(imageId, changes);
            return contextId;
        }, onSuccess: refresh }),
        remove: useMutation({ mutationFn: async (imageId: string) => {
            const contextId = itemId.value;
            await deleteItemImage(imageId);
            return contextId;
        }, onSuccess: refresh }),
    };
}
