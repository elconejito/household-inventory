<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useItemImagesQuery, useItemImageMutations } from '../queries/item-images';
import { parseApiErrors } from '../lib/api-errors';
import ArchivedChildRecordsPanel from './ArchivedChildRecordsPanel.vue';

const props = defineProps<{ itemId: string }>();
const itemId = computed(() => props.itemId);
const imagesQuery = useItemImagesQuery(itemId);
const mutations = useItemImageMutations(itemId);
const file = ref<File | null>(null);
const caption = ref('');
const editCaption = ref('');
const editingId = ref<string | null>(null);
const error = ref('');
const preview = ref<string | null>(null);
const previewDialog = ref<HTMLDialogElement | null>(null);
const pending = computed(() => mutations.upload.isPending.value || mutations.update.isPending.value || mutations.remove.isPending.value);
const images = computed(() => imagesQuery.data.value ?? []);
const primary = computed(() => images.value.find((image) => image.is_primary) ?? images.value[0] ?? null);
const secondary = computed(() => images.value.filter((image) => image.id !== primary.value?.id));

watch(() => props.itemId, () => { file.value = null; caption.value = ''; error.value = ''; editingId.value = null; preview.value = null; });
watch(preview, (value) => {
    if (value && previewDialog.value && !previewDialog.value.open) previewDialog.value.showModal();
    if (!value && previewDialog.value?.open) previewDialog.value.close();
});

function timestamp(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}

function errorMessage(cause: unknown, fallback: string): string {
    const errors = parseApiErrors(cause);
    return errors.form || Object.values(errors.fields).join(' ') || fallback;
}

function chooseFile(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    error.value = '';
}

async function upload(): Promise<void> {
    if (!file.value || pending.value) return;
    error.value = '';
    try {
        await mutations.upload.mutateAsync({ file: file.value, caption: caption.value });
        file.value = null;
        caption.value = '';
        const input = document.getElementById(`image-upload-${props.itemId}`) as HTMLInputElement | null;
        if (input) input.value = '';
    } catch (cause) {
        error.value = errorMessage(cause, 'The photo could not be uploaded. Try JPEG, PNG, or WebP up to 20 MB.');
    }
}

function startEdit(imageId: string, value: string | null): void {
    if (pending.value) return;
    editingId.value = imageId;
    editCaption.value = value ?? '';
    error.value = '';
}

async function saveCaption(imageId: string, original: string | null): Promise<void> {
    const value = editCaption.value.trim();
    editingId.value = null;
    if (pending.value || value === (original ?? '')) return;
    try {
        await mutations.update.mutateAsync({ imageId, changes: { caption: value } });
    } catch (cause) {
        editingId.value = imageId;
        error.value = errorMessage(cause, 'The caption could not be updated.');
    }
}

async function makePrimary(imageId: string): Promise<void> {
    if (pending.value) return;
    error.value = '';
    try {
        await mutations.update.mutateAsync({ imageId, changes: { is_primary: true } });
    } catch (cause) {
        error.value = errorMessage(cause, 'The primary photo could not be changed.');
    }
}

async function removeImage(imageId: string): Promise<void> {
    if (pending.value || !window.confirm('Delete this photo?')) return;
    error.value = '';
    try {
        await mutations.remove.mutateAsync(imageId);
    } catch (cause) {
        error.value = errorMessage(cause, 'The photo could not be deleted.');
    }
}

function openPreview(): void { preview.value = primary.value?.display_url ?? null; }
function closePreview(): void { preview.value = null; }
</script>

<template>
    <section class="rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="item-images-title">
        <h2 id="item-images-title" class="font-semibold text-ink">Photos</h2>
        <p v-if="error" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">{{ error }}</p>
        <div v-if="imagesQuery.isPending.value" class="py-6 text-sm text-ink-muted" role="status">Loading photos…</div>
        <div v-else-if="imagesQuery.isError.value" class="py-4 text-sm text-rose-700" role="alert">Photos could not be loaded. <button type="button" class="underline" @click="imagesQuery.refetch()">Try again</button></div>
        <template v-else>
            <div v-if="primary" class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] sm:items-start">
                <button type="button" class="group block overflow-hidden rounded-lg bg-surface-soft text-left" :aria-label="`Enlarge ${primary.caption || 'primary photo'}`" @click="openPreview"><img :src="primary.display_url" :alt="primary.caption || 'Primary item photo'" class="max-h-[28rem] w-full object-contain" loading="lazy"></button>
                <div class="grid gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-sage-dark">Primary photo</p>
                    <p v-if="primary.caption" class="whitespace-pre-wrap text-sm text-ink">{{ primary.caption }}</p>
                    <p class="text-xs text-ink-muted">Uploaded {{ timestamp(primary.uploaded_at) }}<span v-if="primary.uploaded_by?.name"> · {{ primary.uploaded_by.name }}</span></p>
                    <div class="flex flex-wrap gap-2"><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm" @click="startEdit(primary.id, primary.caption)">Edit caption</button><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-rose-200 px-3 text-sm text-rose-700" @click="removeImage(primary.id)">Remove photo</button></div>
                    <form v-if="editingId === primary.id" class="grid gap-2" @submit.prevent="saveCaption(primary.id, primary.caption)"><label :for="`image-caption-${primary.id}`" class="text-sm font-medium">Caption</label><input :id="`image-caption-${primary.id}`" v-model="editCaption" maxlength="500" class="min-h-10 rounded-md border border-line px-3 text-sm"><div class="flex gap-2"><button :disabled="pending" class="min-h-9 rounded-md bg-sage px-3 text-sm text-white">Save</button><button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm" @click="editingId = null">Cancel</button></div></form>
                </div>
            </div>
            <p v-else class="mt-4 text-sm text-ink-muted">No photos yet. Add a photo to make this item easier to recognize.</p>
            <details class="mt-5 rounded-md border border-line px-4 py-3">
                <summary class="w-fit cursor-pointer text-sm font-medium text-sage-dark">Add or manage photos</summary>
                <ul v-if="secondary.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <li v-for="image in secondary" :key="image.id" class="grid content-start gap-2 rounded-md border border-line p-2">
                    <button type="button" class="overflow-hidden rounded bg-surface-soft" :aria-label="`Enlarge ${image.caption || 'photo'}`" @click="preview = image.display_url"><img :src="image.thumbnail_url" :alt="image.caption || 'Item photo'" class="aspect-[4/3] w-full object-cover" loading="lazy"></button>
                    <p v-if="image.caption" class="line-clamp-2 text-xs text-ink">{{ image.caption }}</p>
                    <div v-if="editingId === image.id" class="grid gap-2"><label :for="`image-caption-${image.id}`" class="sr-only">Caption</label><input :id="`image-caption-${image.id}`" v-model="editCaption" maxlength="500" class="min-h-9 w-full rounded border border-line px-2 text-xs"><button type="button" :disabled="pending" class="text-left text-xs text-sage-dark underline" @click="saveCaption(image.id, image.caption)">Save caption</button></div>
                    <div v-else class="flex flex-wrap gap-x-3 gap-y-1 text-xs"><button type="button" :disabled="pending" class="text-sage-dark underline" @click="startEdit(image.id, image.caption)">Caption</button><button type="button" :disabled="pending" class="text-sage-dark underline" @click="makePrimary(image.id)">Make primary</button><button type="button" :disabled="pending" class="text-rose-700 underline" @click="removeImage(image.id)">Remove photo</button></div>
                </li>
                </ul>
            <form class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="upload">
                <div class="grid gap-2"><label :for="`image-upload-${itemId}`" class="text-sm font-medium text-ink">Add a photo</label><input :id="`image-upload-${itemId}`" type="file" accept="image/jpeg,image/png,image/webp" :disabled="pending" class="min-h-10 w-full text-sm file:mr-3 file:min-h-10 file:rounded-md file:border-0 file:bg-sage-soft file:px-3 file:text-sm file:font-medium file:text-sage-dark" @change="chooseFile"></div>
                <div class="grid gap-2"><label :for="`image-upload-caption-${itemId}`" class="text-sm font-medium text-ink">Caption <span class="font-normal text-ink-muted">(optional)</span></label><input :id="`image-upload-caption-${itemId}`" v-model="caption" maxlength="500" :disabled="pending" class="min-h-10 rounded-md border border-line px-3 text-sm"></div>
                <button type="submit" :disabled="pending || !file" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ mutations.upload.isPending.value ? 'Uploading…' : 'Upload photo' }}</button>
            </form>
            <p class="mt-2 text-xs text-ink-muted">JPEG, PNG, or WebP · up to 20 MB. HEIC is not supported.</p>
            </details>
        </template>
        <ArchivedChildRecordsPanel context-type="items" :context-id="itemId" kind="item-images" />
        <dialog ref="previewDialog" aria-labelledby="photo-preview-title" class="max-h-[96vh] max-w-[96vw] overflow-visible rounded-lg bg-transparent p-4 backdrop:bg-black/80" @close="closePreview" @cancel="closePreview"><h3 id="photo-preview-title" class="sr-only">Enlarged photo</h3><button type="button" autofocus class="absolute right-4 top-4 min-h-11 rounded-md bg-white px-4 text-sm font-semibold text-ink" @click="closePreview">Close</button><img v-if="preview" :src="preview" alt="Enlarged item photo" class="max-h-[90vh] max-w-full object-contain"></dialog>
    </section>
</template>
