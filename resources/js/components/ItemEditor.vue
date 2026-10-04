<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { FormErrors } from '../lib/api-errors';
import type { InventoryItem, ItemEditSnapshot, NewItem } from '../api/items';
import { useItemCategoryOptionsQuery } from '../queries/items';

const props = defineProps<{
    item: InventoryItem | null;
    saving?: boolean;
    errors?: FormErrors;
}>();
const emit = defineEmits<{
    submit: [item: NewItem, initialItem: ItemEditSnapshot | null];
    cancel: [];
}>();

const categoriesQuery = useItemCategoryOptionsQuery();
const name = ref('');
const countingUnit = ref('item');
const description = ref('');
const categoryIds = ref<string[]>([]);
const initialItem = ref<ItemEditSnapshot | null>(null);
const formErrors = computed(() => props.errors ?? { fields: {}, form: '' });
const categoryFieldErrors = computed(() => Object.entries(formErrors.value.fields)
    .filter(([field]) => field === 'category_ids' || field.startsWith('category_ids.') || field.startsWith('category_ids/'))
    .map(([, message]) => message));

watch(() => props.item?.id, () => {
    const item = props.item;
    name.value = item?.name ?? '';
    countingUnit.value = item?.counting_unit ?? 'item';
    description.value = item?.description ?? '';
    categoryIds.value = (item?.categories ?? []).map((category) => String(category.id)).sort();
    initialItem.value = item ? {
        id: item.id,
        name: item.name,
        counting_unit: item.counting_unit,
        description: item.description,
        category_ids: [...categoryIds.value],
    } : null;
}, { immediate: true });

function fieldError(field: string): string | undefined {
    return formErrors.value.fields[field];
}

function submit(): void {
    if (props.item && !categoriesQuery.isSuccess.value) return;
    emit('submit', {
        name: name.value,
        counting_unit: countingUnit.value,
        description: description.value.length > 0 ? description.value : null,
        category_ids: [...new Set(categoryIds.value.map(String))].sort(),
    }, initialItem.value ? { ...initialItem.value, category_ids: [...initialItem.value.category_ids] } : null);
}
</script>

<template>
    <section class="rounded-panel border border-line bg-white p-5 shadow-card sm:p-7" :aria-labelledby="item ? 'edit-item-title' : 'create-item-title'">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="eyebrow">{{ item ? 'Item details' : 'New supply' }}</p>
                <h2 :id="item ? 'edit-item-title' : 'create-item-title'" class="mt-1 text-xl font-semibold tracking-tight text-ink">{{ item ? 'Edit item' : 'Add an item' }}</h2>
            </div>
            <p v-if="!item" class="text-sm text-ink-muted">Fields marked required must be filled in.</p>
        </div>

        <div v-if="formErrors.form" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-5 text-rose-800" role="alert">{{ formErrors.form }}</div>

        <form class="mt-6 grid gap-5 sm:grid-cols-2" @submit.prevent="submit">
            <div class="grid content-start gap-2">
                <label for="item-name" class="text-sm font-medium text-ink">Name <span aria-hidden="true">*</span></label>
                <input id="item-name" v-model="name" name="name" autocomplete="off" required maxlength="255" :aria-invalid="Boolean(fieldError('name'))" :aria-describedby="fieldError('name') ? 'item-name-error' : undefined" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20">
                <p v-if="fieldError('name')" id="item-name-error" class="text-sm text-rose-700">{{ fieldError('name') }}</p>
            </div>

            <div class="grid content-start gap-2">
                <label for="item-counting-unit" class="text-sm font-medium text-ink">Counting unit <span aria-hidden="true">*</span></label>
                <input id="item-counting-unit" v-model="countingUnit" name="counting_unit" required maxlength="255" placeholder="item, roll, box…" :aria-invalid="Boolean(fieldError('counting_unit'))" :aria-describedby="fieldError('counting_unit') ? 'item-counting-unit-error' : undefined" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20">
                <p v-if="fieldError('counting_unit')" id="item-counting-unit-error" class="text-sm text-rose-700">{{ fieldError('counting_unit') }}</p>
                <p v-if="item" class="text-xs leading-5 text-ink-muted">Changing this label updates quantity and movement-history labels at all locations; it does not convert or change existing stock counts.</p>
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <label for="item-description" class="text-sm font-medium text-ink">Description <span class="font-normal text-ink-muted">(optional)</span></label>
                <textarea id="item-description" v-model="description" name="description" rows="3" :aria-invalid="Boolean(fieldError('description'))" :aria-describedby="fieldError('description') ? 'item-description-error' : undefined" class="min-h-24 resize-y rounded-md border border-line bg-white px-3.5 py-3 text-base leading-6 text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20" />
                <p v-if="fieldError('description')" id="item-description-error" class="text-sm text-rose-700">{{ fieldError('description') }}</p>
            </div>

            <fieldset class="grid gap-2 sm:col-span-2" :aria-invalid="categoryFieldErrors.length ? 'true' : undefined" :aria-describedby="categoryFieldErrors.length ? 'item-categories-error' : undefined">
                <legend class="text-sm font-medium text-ink">Categories <span class="font-normal text-ink-muted">(optional; choose any that fit)</span></legend>
                <p v-if="categoriesQuery.isPending.value" class="text-sm text-ink-muted" role="status">Loading categories…</p>
                <div v-else-if="categoriesQuery.isError.value" class="flex flex-wrap items-center gap-3 text-sm text-rose-700" role="alert">
                    <span>Categories could not be loaded. Assignments are unchanged until the list is available.</span>
                    <button type="button" class="font-semibold underline" @click="categoriesQuery.refetch()">Try again</button>
                </div>
                <p v-else-if="!categoriesQuery.data.value?.length" class="text-sm text-ink-muted">No active categories yet.</p>
                <p v-if="categoryFieldErrors.length" id="item-categories-error" class="text-sm text-rose-700" role="alert">{{ categoryFieldErrors.join(' ') }}</p>
                <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="category in categoriesQuery.data.value" :key="category.id" class="flex min-h-10 items-center gap-2 rounded-md border border-line px-3 py-2 text-sm text-ink">
                        <input v-model="categoryIds" type="checkbox" :value="String(category.id)" class="size-4 accent-sage">
                        <span>{{ category.name }}</span>
                    </label>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                <button type="submit" :disabled="saving || Boolean(item && (categoriesQuery.isPending.value || categoriesQuery.isError.value))" class="inline-flex min-h-11 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-wait disabled:opacity-70">{{ saving ? 'Saving…' : item ? 'Save changes' : 'Save item' }}</button>
                <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-md px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="emit('cancel')">Cancel</button>
            </div>
        </form>
    </section>
</template>
