<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useQueryClient } from '@tanstack/vue-query';
import ItemEditor from './ItemEditor.vue';
import { getItemImages } from '../api/item-images';
import type { ItemEditSnapshot, NewItem } from '../api/items';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useItemImageMutations } from '../queries/item-images';
import { useCreateItemMutation } from '../queries/items';

type CreateIntent = 'detail' | 'stock';
const maximumPhotoSize = 20 * 1024 * 1024;
const allowedPhotoTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);

const emit = defineEmits<{ busy: [value: boolean]; cancel: [] }>();
const route = useRoute();
const router = useRouter();
const queryClient = useQueryClient();
const createMutation = useCreateItemMutation();
const createdItemId = ref('');
const createdItemName = ref('');
const intent = ref<CreateIntent>('detail');
const photo = ref<File | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);
const photoSelectionError = ref('');
const photoUploadError = ref('');
const photoUploadFailed = ref(false);
const photoUploaded = ref(false);
const navigationError = ref('');
const processing = ref(false);
const creationErrors = ref<FormErrors>({ fields: {}, form: '' });
const photoMutations = useItemImageMutations(computed(() => createdItemId.value));
const photoBusy = computed(() => photoMutations.upload.isPending.value);
let operationGeneration = 0;
let isMounted = true;

function setProcessing(value: boolean): void {
    processing.value = value;
    emit('busy', value || Boolean(createdItemId.value && route.name === 'inventory'));
}

function photoError(file: File): string {
    if (!allowedPhotoTypes.has(file.type)) return 'Choose a JPEG, PNG, or WebP image.';
    if (file.size > maximumPhotoSize) return 'Choose an image no larger than 20 MB.';
    return '';
}

function selectPhoto(event: Event): void {
    const input = event.currentTarget as HTMLInputElement;
    const nextPhoto = input.files?.[0] ?? null;
    if (!nextPhoto) return;
    photo.value = nextPhoto;
    photoSelectionError.value = photoError(nextPhoto);
    photoUploadError.value = '';
    if (createdItemId.value) photoUploadFailed.value = true;
}

function removePhoto(): void {
    if (processing.value) return;
    photo.value = null;
    photoSelectionError.value = '';
    photoUploadError.value = '';
    if (photoInput.value) photoInput.value.value = '';
}

function uploadErrorMessage(cause: unknown): string {
    const errors = parseApiErrors(cause);
    return errors.form || Object.values(errors.fields).join(' ') || 'The photo could not be uploaded. Try again.';
}

function isCurrent(generation: number): boolean {
    return isMounted && generation === operationGeneration;
}

function itemDestination(): { name: string; params: { item: string }; query?: Record<string, string> } {
    return {
        name: 'inventory-item',
        params: { item: createdItemId.value },
        ...(intent.value === 'stock' ? { query: { restock: '1' } } : {}),
    };
}

async function openCreatedItem(generation: number): Promise<void> {
    if (!createdItemId.value || !isCurrent(generation)) return;
    setProcessing(true);
    navigationError.value = '';
    try {
        const failure = await router.push(itemDestination());
        if (!isCurrent(generation)) {
            setProcessing(false);
            return;
        }
        if (failure) {
            navigationError.value = 'The item was saved, but its detail page could not be opened.';
            setProcessing(false);
            return;
        }
        setProcessing(false);
    } catch {
        if (!isCurrent(generation)) {
            setProcessing(false);
            return;
        }
        navigationError.value = 'The item was saved, but its detail page could not be opened.';
        setProcessing(false);
    }
}

async function uploadPhotoForCreatedItem(generation: number, file: File, checkForPriorUpload: boolean): Promise<void> {
    const itemId = createdItemId.value;
    if (!itemId || !isCurrent(generation)) return;
    setProcessing(true);
    photoUploadError.value = '';

    try {
        if (checkForPriorUpload) {
            const existingImages = await getItemImages(itemId);
            if (!isCurrent(generation)) {
                setProcessing(false);
                return;
            }
            if (existingImages.length > 0) {
                await Promise.all([
                    queryClient.invalidateQueries({ queryKey: ['item-images', itemId] }),
                    queryClient.invalidateQueries({ queryKey: ['items'] }),
                ]);
                if (!isCurrent(generation)) {
                    setProcessing(false);
                    return;
                }
                photoUploaded.value = true;
                photoUploadFailed.value = false;
                await openCreatedItem(generation);
                return;
            }
        }

        await photoMutations.upload.mutateAsync({ file, caption: '' });
        if (!isMounted) return;
        photoUploaded.value = true;
        photoUploadFailed.value = false;
        if (!isCurrent(generation)) {
            navigationError.value = 'The photo was uploaded, but the page changed before the item could be opened.';
            setProcessing(false);
            return;
        }
        await openCreatedItem(generation);
    } catch (cause) {
        if (!isMounted) return;
        photoUploadFailed.value = true;
        photoUploadError.value = uploadErrorMessage(cause);
        setProcessing(false);
    }
}

async function submitCreate(itemData: NewItem, _snapshot: ItemEditSnapshot | null, requestedIntent: CreateIntent = 'detail'): Promise<void> {
    if (processing.value || createdItemId.value) return;
    if (photo.value && photoError(photo.value)) {
        photoSelectionError.value = photoError(photo.value);
        return;
    }

    const generation = ++operationGeneration;
    intent.value = requestedIntent;
    createdItemName.value = itemData.name;
    creationErrors.value = { fields: {}, form: '' };
    navigationError.value = '';
    setProcessing(true);

    const photoForOperation = photo.value;
    let item;
    try {
        item = await createMutation.mutateAsync({ ...itemData });
    } catch (cause) {
        if (!isMounted) return;
        creationErrors.value = parseApiErrors(cause);
        setProcessing(false);
        return;
    }

    createdItemId.value = item.id;
    if (!isCurrent(generation)) {
        if (photoForOperation) {
            photoUploadFailed.value = true;
            photoUploadError.value = 'The item was saved before the page changed. Retry the photo upload or continue without it.';
        } else {
            navigationError.value = 'The item was saved before the page changed. Open the saved item to continue.';
        }
        setProcessing(false);
        return;
    }
    if (photoForOperation) {
        await uploadPhotoForCreatedItem(generation, photoForOperation, false);
        return;
    }

    await openCreatedItem(generation);
}

async function retryPhotoUpload(): Promise<void> {
    if (processing.value || !createdItemId.value || !photo.value) return;
    const validationMessage = photoError(photo.value);
    if (validationMessage) {
        photoSelectionError.value = validationMessage;
        return;
    }
    const generation = ++operationGeneration;
    await uploadPhotoForCreatedItem(generation, photo.value, true);
}

async function continueWithoutPhoto(): Promise<void> {
    if (processing.value || !createdItemId.value) return;
    const generation = ++operationGeneration;
    photoUploadFailed.value = false;
    photoUploaded.value = false;
    await openCreatedItem(generation);
}

async function retryOpenItem(): Promise<void> {
    if (processing.value || !createdItemId.value) return;
    const generation = ++operationGeneration;
    await openCreatedItem(generation);
}

function cancel(): void {
    if (processing.value || createdItemId.value) return;
    emit('cancel');
}

watch(() => route.fullPath, () => {
    if (!processing.value) return;
    operationGeneration++;
}, { flush: 'sync' });

watch(() => route.name, () => {
    emit('busy', processing.value || Boolean(createdItemId.value && route.name === 'inventory'));
}, { flush: 'sync' });

onUnmounted(() => {
    isMounted = false;
    operationGeneration++;
    emit('busy', false);
});
</script>

<template>
    <div>
        <ItemEditor v-if="!createdItemId" id="create-item-panel" class="mt-6" :item="null" :saving="processing" :errors="creationErrors" @submit="submitCreate" @cancel="cancel">
            <template #create-fields>
                <div class="grid gap-2 sm:col-span-2">
                    <label for="create-item-photo" class="text-sm font-medium text-ink">Primary photo (optional)</label>
                    <input id="create-item-photo" ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" :disabled="processing" class="min-h-10 w-full min-w-0 rounded-md border border-line bg-white px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-sage-soft file:px-3 file:py-1 file:font-medium file:text-sage-dark disabled:opacity-50" @change="selectPhoto">
                    <p v-if="photoSelectionError" id="create-item-photo-error" class="text-sm text-rose-700" role="alert">{{ photoSelectionError }}</p>
                    <div v-if="photo" class="flex flex-wrap items-center gap-3 text-sm text-ink-muted"><span class="min-w-0 max-w-full break-all">{{ photo.name }}</span><button type="button" :disabled="processing" class="font-medium text-sage-dark underline disabled:opacity-50" @click="removePhoto">Remove photo</button></div>
                    <p v-else class="text-xs text-ink-muted">JPEG, PNG, or WebP up to 20 MB.</p>
                </div>
            </template>
        </ItemEditor>

        <section v-else class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-7" :aria-busy="processing" aria-labelledby="saved-created-item-title">
            <p class="eyebrow">Item saved</p>
            <h2 id="saved-created-item-title" class="mt-1 text-xl font-semibold text-ink">{{ createdItemName }}</h2>
            <p v-if="photoUploadFailed" class="mt-3 text-sm text-rose-700" role="alert">The item was saved, but its photo could not be uploaded. {{ photoUploadError }}</p>
            <p v-else-if="navigationError" class="mt-3 text-sm text-rose-700" role="alert">{{ navigationError }}</p>
            <p v-else-if="processing" class="mt-3 text-sm text-ink-muted" role="status">{{ photoBusy ? 'Uploading photo…' : 'Opening item…' }}</p>
            <p v-else-if="photoUploaded" class="mt-3 text-sm text-sage-dark" role="status">Photo uploaded. Opening the saved item…</p>
            <div v-if="photoUploadFailed" class="mt-4 grid gap-3">
                <div class="grid gap-2"><label for="create-item-photo" class="text-sm font-medium text-ink">Choose a replacement photo</label><input id="create-item-photo" ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" :disabled="processing" class="min-h-10 w-full min-w-0 rounded-md border border-line bg-white px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-sage-soft file:px-3 file:py-1 file:font-medium file:text-sage-dark disabled:opacity-50" @change="selectPhoto"><p v-if="photoSelectionError" class="text-sm text-rose-700" role="alert">{{ photoSelectionError }}</p><p v-else-if="photo" class="break-all text-sm text-ink-muted">{{ photo.name }}</p><button v-if="photo" type="button" :disabled="processing" class="w-fit text-sm font-semibold text-sage-dark underline disabled:opacity-50" @click="removePhoto">Remove photo</button></div>
                <div class="flex flex-wrap gap-3"><button type="button" :disabled="processing || !photo || Boolean(photoSelectionError)" class="inline-flex min-h-10 items-center rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50" @click="retryPhotoUpload">Retry photo upload</button><button type="button" :disabled="processing" class="inline-flex min-h-10 items-center rounded-md border border-line px-4 text-sm font-medium text-ink disabled:opacity-50" @click="continueWithoutPhoto">Continue without photo</button></div>
            </div>
            <button v-else-if="navigationError" type="button" :disabled="processing" class="mt-4 min-h-10 rounded-md border border-line px-4 text-sm font-semibold text-ink disabled:opacity-50" @click="retryOpenItem">Retry opening item</button>
        </section>
    </div>
</template>
