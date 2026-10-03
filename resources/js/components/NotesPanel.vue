<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { type NoteContextType } from '../api/notes';
import { useNotesQuery, useNoteMutations } from '../queries/notes';
import { parseApiErrors } from '../lib/api-errors';

const props = defineProps<{ type: NoteContextType; contextId: string }>();
const page = ref(1);
const body = ref('');
const editingId = ref<string | null>(null);
const editBody = ref('');
const error = ref('');
const contextId = computed(() => props.contextId);
const currentPage = computed(() => page.value);
const notesQuery = useNotesQuery(props.type, contextId, currentPage);
const mutations = useNoteMutations(props.type, contextId);
const pending = computed(() => mutations.create.isPending.value || mutations.update.isPending.value || mutations.remove.isPending.value);
const notes = computed(() => notesQuery.data.value?.data ?? []);

watch(() => notesQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && page.value > lastPage) page.value = lastPage;
});

watch(() => props.contextId, () => { page.value = 1; body.value = ''; editingId.value = null; error.value = ''; });

function timestamp(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function errorMessage(cause: unknown, fallback: string): string {
    const errors = parseApiErrors(cause);
    return errors.form || Object.values(errors.fields).join(' ') || fallback;
}

function startEdit(noteId: string, value: string): void {
    if (pending.value) return;
    error.value = '';
    editingId.value = noteId;
    editBody.value = value;
}

async function addNote(): Promise<void> {
    const value = body.value;
    if (!value.trim() || pending.value) return;
    error.value = '';
    try {
        await mutations.create.mutateAsync(value);
        body.value = '';
        page.value = 1;
    } catch (cause) {
        error.value = errorMessage(cause, 'The note could not be saved. Try again.');
    }
}

async function saveEdit(noteId: string, original: string): Promise<void> {
    const value = editBody.value;
    if (!value.trim() || pending.value) return;
    editingId.value = null;
    if (value === original) return;
    error.value = '';
    try {
        await mutations.update.mutateAsync({ noteId, body: value });
    } catch (cause) {
        editingId.value = noteId;
        error.value = errorMessage(cause, 'The note could not be updated. Try again.');
    }
}

async function removeNote(noteId: string): Promise<void> {
    if (pending.value || !window.confirm('Delete this note?')) return;
    error.value = '';
    try {
        await mutations.remove.mutateAsync(noteId);
        if (notes.value.length === 1 && page.value > 1) page.value--;
    } catch (cause) {
        error.value = errorMessage(cause, 'The note could not be deleted. Try again.');
    }
}
</script>

<template>
    <section class="rounded-panel border border-line bg-white p-5 shadow-card" aria-label="Notes">
        <h2 class="font-semibold text-ink">Notes</h2>
        <p v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{{ error }}</p>
        <form class="mt-4 grid gap-2" @submit.prevent="addNote">
            <label :for="`new-note-${type}-${contextId}`" class="text-sm font-medium text-ink">Note</label>
            <textarea :id="`new-note-${type}-${contextId}`" v-model="body" rows="3" maxlength="10000" required :disabled="pending" class="w-full rounded-md border border-line px-3 py-2 text-sm text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20 disabled:opacity-60" placeholder="Write a note…"></textarea>
            <button type="submit" :disabled="pending || !body.trim()" class="min-h-10 justify-self-start rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ mutations.create.isPending.value ? 'Saving…' : 'Add note' }}</button>
        </form>
        <div v-if="notesQuery.isPending.value" class="py-6 text-sm text-ink-muted" role="status">Loading notes…</div>
        <div v-else-if="notesQuery.isError.value" class="py-4 text-sm text-rose-700" role="alert">Notes could not be loaded. <button type="button" class="underline" @click="notesQuery.refetch()">Try again</button></div>
        <p v-else-if="notes.length === 0" class="py-5 text-sm text-ink-muted">No notes yet.</p>
        <ol v-else class="mt-4 divide-y divide-line">
            <li v-for="note in notes" :key="note.id" class="py-4 first:pt-0 last:pb-0">
                <form v-if="editingId === note.id" class="grid gap-2" @submit.prevent="saveEdit(note.id, note.body)">
                    <label :for="`edit-note-${note.id}`" class="sr-only">Edit note</label>
                    <textarea :id="`edit-note-${note.id}`" v-model="editBody" rows="3" maxlength="10000" required :disabled="pending" class="w-full rounded-md border border-line px-3 py-2 text-sm text-ink" />
                    <div class="flex gap-2"><button type="submit" :disabled="pending || !editBody.trim()" class="min-h-9 rounded-md bg-sage px-3 text-sm font-medium text-white disabled:opacity-50">Save changes</button><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm" @click="editingId = null">Cancel</button></div>
                </form>
                <template v-else>
                    <article :aria-label="`Note by ${note.created_by?.name ?? 'Former member'}`"><p class="whitespace-pre-wrap break-words text-sm leading-6 text-ink">{{ note.body }}</p>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-ink-muted"><span>{{ note.created_by?.name ?? 'Former member' }}</span> · <time :datetime="note.created_at">{{ timestamp(note.created_at) }}</time><span v-if="note.is_edited"> · edited</span></p>
                        <div class="flex gap-2"><button type="button" :disabled="pending" class="min-h-8 px-2 text-xs font-medium text-sage-dark underline disabled:opacity-50" @click="startEdit(note.id, note.body)">Edit note</button><button type="button" :disabled="pending" class="min-h-8 px-2 text-xs font-medium text-rose-700 underline disabled:opacity-50" @click="removeNote(note.id)">Delete note</button></div>
                    </div>
                    </article>
                </template>
            </li>
        </ol>
        <div v-if="notesQuery.data.value && notesQuery.data.value.meta.last_page > 1" class="mt-4 flex items-center justify-between border-t border-line pt-3 text-sm"><span class="text-ink-muted">Page {{ page }} of {{ notesQuery.data.value.meta.last_page }}</span><div class="flex gap-2"><button type="button" :disabled="page <= 1 || notesQuery.isFetching.value" class="min-h-9 rounded-md border border-line px-3 disabled:opacity-50" @click="page--">Previous</button><button type="button" :disabled="page >= notesQuery.data.value.meta.last_page || notesQuery.isFetching.value" class="min-h-9 rounded-md border border-line px-3 disabled:opacity-50" @click="page++">Next</button></div></div>
    </section>
</template>
