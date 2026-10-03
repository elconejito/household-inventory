import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type ComputedRef } from 'vue';
import { createNote, deleteNote, getNotes, updateNote, type NoteContextType } from '../api/notes';

export const noteQueryKey = (type: NoteContextType, id: string, page: number) => ['notes', type, id, page] as const;

export function useNotesQuery(type: NoteContextType, id: ComputedRef<string>, page: ComputedRef<number>, enabled?: ComputedRef<boolean>) {
    return useQuery({
        queryKey: computed(() => noteQueryKey(type, id.value, page.value)),
        queryFn: () => getNotes(type, id.value, page.value),
        enabled: computed(() => Boolean(id.value) && (enabled?.value ?? true)),
    });
}

export function useNoteMutations(type: NoteContextType, id: ComputedRef<string>) {
    const client = useQueryClient();
    const refresh = async (contextId: string) => Promise.all([
        client.invalidateQueries({ queryKey: ['notes', type, contextId] }),
        client.invalidateQueries({ queryKey: ['archived-child-records', 'notes', type, contextId] }),
        client.invalidateQueries({ queryKey: [type] }),
    ]);
    const create = useMutation({ mutationFn: async (body: string) => {
        const contextId = id.value;
        await createNote(type, contextId, body);
        return contextId;
    }, onSuccess: refresh });
    const update = useMutation({ mutationFn: async ({ noteId, body }: { noteId: string; body: string }) => {
        const contextId = id.value;
        await updateNote(noteId, body);
        return contextId;
    }, onSuccess: refresh });
    const remove = useMutation({ mutationFn: async (noteId: string) => {
        const contextId = id.value;
        await deleteNote(noteId);
        return contextId;
    }, onSuccess: refresh });
    return { create, update, remove };
}
