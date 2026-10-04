<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useCategoryAssignmentItemsQuery } from '../queries/catalog';

const props = defineProps<{
    categoryId: string;
    pending?: boolean;
    error?: string;
}>();
const emit = defineEmits<{
    assign: [itemId: string, assigned: boolean, itemName: string];
}>();

const itemsQuery = useCategoryAssignmentItemsQuery();
const search = ref('');
const selectedItemId = ref('');
const visibleItems = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();
    return (itemsQuery.data.value ?? []).filter((item) => !term || item.name.toLocaleLowerCase().includes(term));
});
const selectedItem = computed(() => visibleItems.value.find((item) => item.id === selectedItemId.value) ?? null);
const selectedItemAlreadyAssigned = computed(() => selectedItem.value?.categories?.some((category) => category.id === props.categoryId) ?? false);
const canSubmitAssignment = computed(() => Boolean(selectedItem.value && !selectedItemAlreadyAssigned.value));

watch(() => selectedItemAlreadyAssigned.value, (isAssigned, wasAssigned) => {
    if (isAssigned && !wasAssigned) selectedItemId.value = '';
});
watch(() => props.categoryId, () => {
    search.value = '';
    selectedItemId.value = '';
});
watch(visibleItems, (items) => {
    if (selectedItemId.value && !items.some((item) => item.id === selectedItemId.value)) selectedItemId.value = '';
});

function submit(): void {
    if (props.pending || !selectedItem.value || !canSubmitAssignment.value) return;
    emit('assign', selectedItemId.value, true, selectedItem.value.name);
}
</script>

<template>
    <section class="rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="category-assignment-heading" :aria-busy="itemsQuery.isFetching.value || pending">
        <div>
            <p class="eyebrow">Category items</p>
            <h2 id="category-assignment-heading" class="mt-1 text-lg font-semibold text-ink">Add items to this category</h2>
            <p class="mt-1 text-sm text-ink-muted">Choose an existing active inventory item. Other category assignments remain unchanged.</p>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,1fr)_auto] sm:items-end">
            <div class="grid min-w-0 gap-2">
                <label for="category-item-search" class="text-sm font-medium text-ink">Search inventory items</label>
                <input id="category-item-search" v-model="search" type="search" autocomplete="off" :disabled="pending" class="min-h-11 min-w-0 w-full max-w-full rounded-md border border-line bg-canvas px-3 text-base text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20 disabled:opacity-50" placeholder="Search by name">
            </div>
            <div class="grid min-w-0 gap-2">
                <label for="category-item-to-add" class="text-sm font-medium text-ink">Item to add</label>
                <select id="category-item-to-add" v-model="selectedItemId" :disabled="itemsQuery.isPending.value || itemsQuery.isError.value || pending" class="min-h-11 min-w-0 w-full max-w-full rounded-md border border-line bg-white px-3 text-base text-ink disabled:opacity-50">
                    <option value="" disabled>Select an item</option>
                    <option v-for="item in visibleItems" :key="item.id" :value="item.id" :disabled="item.categories?.some((category) => category.id === categoryId)">{{ item.name }}{{ item.categories?.some((category) => category.id === categoryId) ? ' — already in this category' : '' }}</option>
                </select>
            </div>
            <button type="button" :disabled="pending || !canSubmitAssignment || itemsQuery.isPending.value || itemsQuery.isError.value" class="inline-flex min-h-11 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:cursor-not-allowed disabled:opacity-50" @click="submit">{{ pending ? 'Saving…' : 'Add to category' }}</button>
        </div>
        <p v-if="itemsQuery.isPending.value" class="mt-3 text-sm text-ink-muted" role="status">Loading active inventory items…</p>
        <div v-else-if="itemsQuery.isError.value" class="mt-3 flex flex-wrap items-center gap-3 text-sm text-rose-700" role="alert">
            <span>Inventory items could not be loaded.</span>
            <button type="button" class="font-semibold underline" @click="itemsQuery.refetch()">Try again</button>
        </div>
        <p v-else-if="visibleItems.length === 0" class="mt-3 text-sm text-ink-muted" role="status">No items match that search.</p>
        <p v-if="error" class="mt-3 text-sm text-rose-700" role="alert">{{ error }}</p>
    </section>
</template>
