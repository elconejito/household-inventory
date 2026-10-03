<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { getArchivedChildren, permanentlyDeleteArchivedChild, restoreArchivedChild, type ArchivedChildKind } from '../api/archived-children';
import type { Note, NoteContextType } from '../api/notes';
import type { ItemImage } from '../api/item-images';
import { parseApiErrors } from '../lib/api-errors';
import { useSessionStore } from '../stores/session';

const props = defineProps<{
    contextType: NoteContextType;
    contextId: string;
    kind: ArchivedChildKind;
}>();

const session = useSessionStore();
const client = useQueryClient();
const page = ref(1);
const expanded = ref(false);
const error = ref('');
const confirmingId = ref<string | null>(null);
const confirmationText = ref('');
const owner = computed(() => session.user?.membership?.role === 'owner');
const label = computed(() => props.kind === 'notes' ? 'notes' : 'photos');
const queryKey = computed(() => ['archived-child-records', props.kind, props.contextType, props.contextId, page.value] as const);
const query = useQuery({
    queryKey,
    queryFn: () => getArchivedChildren(props.contextType, props.contextId, props.kind, page.value),
    enabled: computed(() => expanded.value && Boolean(props.contextId)),
});

type MutationVariables = {
    id: string;
    kind: ArchivedChildKind;
    contextType: NoteContextType;
    contextId: string;
};

async function invalidateOriginalContext(variables: MutationVariables): Promise<void> {
    const invalidations = [
        client.invalidateQueries({ queryKey: ['archived-child-records', variables.kind, variables.contextType, variables.contextId] }),
        client.invalidateQueries({ queryKey: variables.kind === 'notes'
            ? ['notes', variables.contextType, variables.contextId]
            : ['item-images', variables.contextId] }),
        client.invalidateQueries({ queryKey: [variables.contextType] }),
    ];

    if (variables.kind === 'item-images') {
        invalidations.push(client.invalidateQueries({ queryKey: ['items'] }));
    }

    await Promise.all(invalidations);
}

const restore = useMutation({
    mutationFn: async (variables: MutationVariables) => {
        await restoreArchivedChild(variables.kind, variables.id);
        return variables;
    },
    onSuccess: invalidateOriginalContext,
});
const permanentlyDelete = useMutation({
    mutationFn: async (variables: MutationVariables) => {
        await permanentlyDeleteArchivedChild(variables.kind, variables.id);
        return variables;
    },
    onSuccess: invalidateOriginalContext,
});
const pending = computed(() => restore.isPending.value || permanentlyDelete.isPending.value);
const rows = computed(() => query.data.value?.data ?? []);
const lastPage = computed(() => query.data.value?.meta.last_page ?? 1);

watch(lastPage, (value) => {
    if (query.data.value && value > 0 && page.value > value) {
        page.value = value;
    }
});

watch(() => [props.contextType, props.contextId, props.kind], () => {
    page.value = 1;
    expanded.value = false;
    error.value = '';
    confirmingId.value = null;
    confirmationText.value = '';
});

function onToggle(event: Event): void {
    expanded.value = (event.currentTarget as HTMLDetailsElement).open;
}

function variablesFor(id: string): MutationVariables {
    return {
        id,
        kind: props.kind,
        contextType: props.contextType,
        contextId: props.contextId,
    };
}

function isCurrentContext(variables: MutationVariables): boolean {
    return props.contextType === variables.contextType
        && props.contextId === variables.contextId
        && props.kind === variables.kind;
}

async function restoreRecord(id: string): Promise<void> {
    if (pending.value) return;
    const variables = variablesFor(id);
    error.value = '';
    try {
        await restore.mutateAsync(variables);
    } catch (cause) {
        if (isCurrentContext(variables)) {
            error.value = parseApiErrors(cause).form || `This archived ${props.kind === 'notes' ? 'note' : 'photo'} could not be restored.`;
        }
    }
}

function requestPermanentDelete(id: string): void {
    if (pending.value) return;
    confirmingId.value = id;
    confirmationText.value = '';
    error.value = '';
}

function cancelPermanentDelete(): void {
    confirmingId.value = null;
    confirmationText.value = '';
}

async function confirmPermanentDelete(id: string): Promise<void> {
    if (pending.value || confirmationText.value !== 'DELETE') return;
    const variables = variablesFor(id);
    error.value = '';
    try {
        await permanentlyDelete.mutateAsync(variables);
        if (! isCurrentContext(variables)) return;
        cancelPermanentDelete();
        if (rows.value.length === 1 && page.value > 1) {
            page.value--;
        }
    } catch (cause) {
        if (isCurrentContext(variables)) {
            error.value = parseApiErrors(cause).form || `This archived ${props.kind === 'notes' ? 'note' : 'photo'} could not be permanently deleted.`;
        }
    }
}

function timestamp(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function noteRows(items: Array<Note | ItemImage>): Note[] {
    return items.filter((item): item is Note => item.type === 'notes');
}

function imageRows(items: Array<Note | ItemImage>): ItemImage[] {
    return items.filter((item): item is ItemImage => item.type === 'item-images');
}
</script>

<template>
    <details class="mt-4 rounded-md border border-line px-4 py-3" @toggle="onToggle">
        <summary class="w-fit cursor-pointer text-sm font-medium text-sage-dark">Archived {{ label }}</summary>
        <p v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{{ error }}</p>
        <div v-if="expanded && query.isPending.value" class="py-4 text-sm text-ink-muted" role="status">Loading archived {{ label }}…</div>
        <div v-else-if="expanded && query.isError.value" class="py-4 text-sm text-rose-700" role="alert">Archived {{ label }} could not be loaded. <button type="button" class="underline" @click="query.refetch()">Try again</button></div>
        <p v-else-if="expanded && rows.length === 0" class="py-4 text-sm text-ink-muted">No archived {{ label }}.</p>

        <ol v-else-if="expanded" class="mt-2 divide-y divide-line">
            <li v-for="note in noteRows(rows)" :key="note.id" class="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div class="min-w-0"><p class="whitespace-pre-wrap break-words text-sm leading-6 text-ink">{{ note.body }}</p><p class="mt-1 text-xs text-ink-muted">{{ note.created_by?.name ?? 'Former member' }} · {{ timestamp(note.created_at) }} <span v-if="note.is_edited">· edited</span></p></div>
                <div class="flex flex-wrap gap-2"><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium text-sage-dark disabled:opacity-50" @click="restoreRecord(note.id)">Restore note</button><button v-if="owner" type="button" :disabled="pending" class="min-h-9 rounded-md px-2 text-sm font-medium text-rose-700 underline disabled:opacity-50" @click="requestPermanentDelete(note.id)">Permanently delete</button></div>
                <form v-if="owner && confirmingId === note.id" class="grid gap-3 rounded-md border border-rose-200 bg-rose-50 p-3 sm:col-span-2" @submit.prevent="confirmPermanentDelete(note.id)"><p class="text-sm leading-5 text-rose-900"><strong>This cannot be undone.</strong> Permanently deleting this archived note removes its text. Type DELETE to confirm.</p><div class="flex flex-wrap gap-2"><input v-model="confirmationText" autocomplete="off" aria-label="Type DELETE to confirm" class="min-h-9 min-w-0 rounded-md border border-rose-300 bg-white px-3 text-sm text-ink"><button type="submit" :disabled="confirmationText !== 'DELETE' || pending" class="min-h-9 rounded-md bg-rose-700 px-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{{ permanentlyDelete.isPending.value ? 'Deleting…' : 'Confirm permanent deletion' }}</button><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line bg-white px-3 text-sm" @click="cancelPermanentDelete">Cancel</button></div></form>
            </li>
            <li v-for="image in imageRows(rows)" :key="image.id" class="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                <div class="min-w-0"><p class="whitespace-pre-wrap break-words text-sm font-medium text-ink">{{ image.caption || 'Archived photo' }}</p><p class="mt-1 text-xs text-ink-muted">{{ image.uploaded_by?.name ?? 'Former member' }} · {{ timestamp(image.uploaded_at) }}</p></div>
                <div class="flex flex-wrap gap-2"><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium text-sage-dark disabled:opacity-50" @click="restoreRecord(image.id)">Restore photo</button><button v-if="owner" type="button" :disabled="pending" class="min-h-9 rounded-md px-2 text-sm font-medium text-rose-700 underline disabled:opacity-50" @click="requestPermanentDelete(image.id)">Permanently delete</button></div>
                <form v-if="owner && confirmingId === image.id" class="grid gap-3 rounded-md border border-rose-200 bg-rose-50 p-3 sm:col-span-2" @submit.prevent="confirmPermanentDelete(image.id)"><p class="text-sm leading-5 text-rose-900"><strong>This cannot be undone.</strong> Permanently deleting this archived photo removes its stored image files. Type DELETE to confirm.</p><div class="flex flex-wrap gap-2"><input v-model="confirmationText" autocomplete="off" aria-label="Type DELETE to confirm" class="min-h-9 min-w-0 rounded-md border border-rose-300 bg-white px-3 text-sm text-ink"><button type="submit" :disabled="confirmationText !== 'DELETE' || pending" class="min-h-9 rounded-md bg-rose-700 px-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{{ permanentlyDelete.isPending.value ? 'Deleting…' : 'Confirm permanent deletion' }}</button><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line bg-white px-3 text-sm" @click="cancelPermanentDelete">Cancel</button></div></form>
            </li>
        </ol>

        <div v-if="expanded && query.data.value && lastPage > 1" class="mt-2 flex items-center justify-between border-t border-line pt-3 text-sm"><span class="text-ink-muted">Page {{ page }} of {{ lastPage }}</span><div class="flex gap-2"><button type="button" :disabled="page <= 1 || query.isFetching.value || pending" class="min-h-9 rounded-md border border-line px-3 disabled:opacity-50" @click="page--">Previous</button><button type="button" :disabled="page >= lastPage || query.isFetching.value || pending" class="min-h-9 rounded-md border border-line px-3 disabled:opacity-50" @click="page++">Next</button></div></div>
    </details>
</template>
