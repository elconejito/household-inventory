<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useCreateItemMutation, useItemsQuery } from '../queries/items';

const pageSize = 10;
const route = useRoute();
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
const showCreateForm = ref(false);
const addItemButton = ref<HTMLButtonElement | null>(null);
const successMessage = ref('');
const formErrors = ref<FormErrors>({ fields: {}, form: '' });
const name = ref('');
const countingUnit = ref('item');
const description = ref('');
let searchTimeout: ReturnType<typeof setTimeout> | undefined;

const listParams = computed(() => ({
    search: debouncedSearch.value,
    page: page.value,
    perPage: pageSize,
}));
const itemsQuery = useItemsQuery(listParams);
const createMutation = useCreateItemMutation();

watch(() => route.query.create, (value) => {
    if (value === '1') {
        showCreateForm.value = true;
    }
}, { immediate: true });

watch(search, (value) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        page.value = 1;
        debouncedSearch.value = value.trim();
    }, 300);
});

onUnmounted(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});

function fieldError(field: string): string | undefined {
    return formErrors.value.fields[field];
}

function toggleCreateForm(): void {
    if (showCreateForm.value) {
        resetForm();
    }

    showCreateForm.value = !showCreateForm.value;
    successMessage.value = '';
    formErrors.value = { fields: {}, form: '' };
}

function resetForm(): void {
    name.value = '';
    countingUnit.value = 'item';
    description.value = '';
    formErrors.value = { fields: {}, form: '' };
}

async function submitCreateForm(): Promise<void> {
    successMessage.value = '';
    formErrors.value = { fields: {}, form: '' };

    try {
        const item = await createMutation.mutateAsync({
            name: name.value,
            counting_unit: countingUnit.value,
            description: description.value.length > 0 ? description.value : null,
        });

        resetForm();
        showCreateForm.value = false;
        successMessage.value = `${item.name} was added to your inventory.`;
        await nextTick();
        addItemButton.value?.focus();
    } catch (error) {
        formErrors.value = parseApiErrors(error);
    }
}

function retryLoading(): void {
    void itemsQuery.refetch();
}

function changePage(nextPage: number): void {
    if (nextPage >= 1 && nextPage <= (itemsQuery.data.value?.meta.last_page ?? 1)) {
        page.value = nextPage;
    }
}

function errorMessage(): string {
    if (itemsQuery.error.value) {
        return parseApiErrors(itemsQuery.error.value).form || 'Your inventory could not be loaded.';
    }

    return 'Your inventory could not be loaded.';
}
</script>

<template>
    <section aria-labelledby="page-title">
        <p class="eyebrow">Browse your supplies</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 id="page-title" class="page-title">Inventory</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">
                    Find household items and see where they are stored.
                </p>
            </div>
            <button
                ref="addItemButton"
                type="button"
                :aria-expanded="showCreateForm"
                aria-controls="create-item-panel"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage"
                @click="toggleCreateForm"
            >
                <span aria-hidden="true">＋</span>
                {{ showCreateForm ? 'Cancel' : 'Add item' }}
            </button>
        </div>

        <p v-if="successMessage" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status" aria-live="polite">
            {{ successMessage }}
        </p>

        <section v-if="showCreateForm" id="create-item-panel" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-7" aria-labelledby="create-item-title">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">New supply</p>
                    <h2 id="create-item-title" class="mt-1 text-xl font-semibold tracking-tight text-ink">Add an item</h2>
                </div>
                <p class="text-sm text-ink-muted">Fields marked required must be filled in.</p>
            </div>

            <div v-if="formErrors.form" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-5 text-rose-800" role="alert">
                {{ formErrors.form }}
            </div>

            <form class="mt-6 grid gap-5 sm:grid-cols-2" @submit.prevent="submitCreateForm">
                <div class="grid content-start gap-2">
                    <label for="item-name" class="text-sm font-medium text-ink">Name <span aria-hidden="true">*</span></label>
                    <input
                        id="item-name"
                        v-model="name"
                        name="name"
                        autocomplete="off"
                        required
                        maxlength="255"
                        :aria-invalid="Boolean(fieldError('name'))"
                        :aria-describedby="fieldError('name') ? 'item-name-error' : undefined"
                        class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                    >
                    <p v-if="fieldError('name')" id="item-name-error" class="text-sm text-rose-700">{{ fieldError('name') }}</p>
                </div>

                <div class="grid content-start gap-2">
                    <label for="item-counting-unit" class="text-sm font-medium text-ink">Counting unit <span aria-hidden="true">*</span></label>
                    <input
                        id="item-counting-unit"
                        v-model="countingUnit"
                        name="counting_unit"
                        required
                        maxlength="255"
                        placeholder="item, roll, box…"
                        :aria-invalid="Boolean(fieldError('counting_unit'))"
                        :aria-describedby="fieldError('counting_unit') ? 'item-counting-unit-error' : undefined"
                        class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                    >
                    <p v-if="fieldError('counting_unit')" id="item-counting-unit-error" class="text-sm text-rose-700">{{ fieldError('counting_unit') }}</p>
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <label for="item-description" class="text-sm font-medium text-ink">Description <span class="font-normal text-ink-muted">(optional)</span></label>
                    <textarea
                        id="item-description"
                        v-model="description"
                        name="description"
                        rows="3"
                        :aria-invalid="Boolean(fieldError('description'))"
                        :aria-describedby="fieldError('description') ? 'item-description-error' : undefined"
                        class="min-h-24 resize-y rounded-md border border-line bg-white px-3.5 py-3 text-base leading-6 text-ink outline-none transition placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                    />
                    <p v-if="fieldError('description')" id="item-description-error" class="text-sm text-rose-700">{{ fieldError('description') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <button
                        type="submit"
                        :disabled="createMutation.isPending.value"
                        class="inline-flex min-h-11 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ createMutation.isPending.value ? 'Saving item…' : 'Save item' }}
                    </button>
                    <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-md px-4 text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="toggleCreateForm">
                        Cancel
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-7 rounded-panel border border-line bg-white shadow-card" aria-labelledby="items-heading" :aria-busy="itemsQuery.isFetching.value">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div>
                    <h2 id="items-heading" class="text-base font-semibold text-ink">Your items</h2>
                    <p v-if="itemsQuery.data.value" class="mt-1 text-sm text-ink-muted" aria-live="polite">
                        {{ itemsQuery.data.value.meta.total }} {{ itemsQuery.data.value.meta.total === 1 ? 'item' : 'items' }}
                    </p>
                </div>
                <div class="relative w-full sm:max-w-xs">
                    <label for="item-search" class="sr-only">Search items</label>
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted" aria-hidden="true">⌕</span>
                    <input
                        id="item-search"
                        v-model="search"
                        type="search"
                        name="search"
                        placeholder="Search items"
                        class="min-h-11 w-full rounded-md border border-line bg-canvas pl-9 pr-3 text-sm text-ink outline-none transition placeholder:text-ink-muted focus:border-sage focus:ring-2 focus:ring-sage/20"
                    >
                </div>
            </div>

            <div v-if="itemsQuery.data.value?.data.length" class="hidden grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)] gap-6 border-b border-line bg-surface-soft/60 px-6 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted sm:grid" aria-hidden="true">
                <span>Item</span>
                <span>Counting unit</span>
                <span>On hand</span>
                <span>Categories</span>
            </div>

            <div v-if="itemsQuery.isPending.value" class="grid min-h-52 place-items-center px-5 py-10 text-center" role="status" aria-live="polite">
                <div>
                    <span class="mx-auto grid size-10 place-items-center rounded-full bg-sage-soft text-lg text-sage" aria-hidden="true">…</span>
                    <p class="mt-3 text-sm font-medium text-ink">Loading your items</p>
                    <p class="mt-1 text-sm text-ink-muted">This should only take a moment.</p>
                </div>
            </div>

            <div v-else-if="itemsQuery.isError.value" class="grid min-h-52 place-items-center px-5 py-10 text-center" role="alert">
                <div class="max-w-md">
                    <h3 class="text-base font-semibold text-ink">We couldn’t load your inventory</h3>
                    <p class="mt-2 text-sm leading-6 text-ink-muted">{{ errorMessage() }}</p>
                    <button type="button" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-md border border-line px-4 text-sm font-medium text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="retryLoading">
                        Try again
                    </button>
                </div>
            </div>

            <template v-else-if="itemsQuery.data.value">
                <div v-if="itemsQuery.data.value.data.length === 0" class="grid min-h-52 place-items-center px-5 py-10 text-center">
                    <div class="max-w-md">
                        <span class="mx-auto grid size-11 place-items-center rounded-[10px] bg-sage-soft text-xl text-sage" aria-hidden="true">⌂</span>
                        <h3 class="mt-4 text-base font-semibold text-ink">{{ debouncedSearch ? 'No items match that search' : 'Your inventory is ready for a first item' }}</h3>
                        <p class="mt-2 text-sm leading-6 text-ink-muted">
                            {{ debouncedSearch ? 'Try another name or clear the search.' : 'Add a supply you keep at home to start building your list.' }}
                        </p>
                        <button v-if="!debouncedSearch" type="button" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="toggleCreateForm">
                            Add your first item
                        </button>
                    </div>
                </div>

                <ul v-else class="divide-y divide-line" aria-label="Inventory items">
                    <li v-for="item in itemsQuery.data.value.data" :key="item.id" class="px-5 py-4 sm:px-6 sm:py-5">
                        <article class="grid gap-2 sm:grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)] sm:items-start sm:gap-6">
                            <div class="min-w-0">
                                <h3 class="break-words text-base font-semibold text-ink"><RouterLink :to="{ name: 'inventory-item', params: { item: item.id } }" class="rounded-sm hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">{{ item.name }}</RouterLink></h3>
                                <p v-if="item.description" class="mt-1 line-clamp-2 whitespace-pre-line text-sm leading-5 text-ink-muted">{{ item.description }}</p>
                            </div>
                            <p class="text-sm text-ink-muted sm:pt-0.5">
                                <span class="sr-only">Counting unit: </span>
                                Counted by <span class="font-medium text-ink">{{ item.counting_unit }}</span>
                            </p>
                            <p class="text-sm font-semibold text-ink sm:pt-0.5">{{ item.total_quantity ?? 0 }} <span class="font-normal text-ink-muted">on hand</span></p>
                            <div class="flex min-w-0 flex-wrap gap-2 sm:pt-0.5">
                                <span v-for="category in item.categories ?? []" :key="category.id" class="max-w-full truncate rounded-md bg-sage-soft px-2.5 py-1 text-xs font-medium text-sage-dark">{{ category.name }}</span>
                                <span v-if="!item.categories?.length" class="text-sm text-ink-muted">{{ item.description ? 'No categories yet' : 'No description or categories yet' }}</span>
                            </div>
                        </article>
                    </li>
                </ul>

                <div v-if="itemsQuery.data.value.meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6">
                    <p class="text-sm text-ink-muted">Page {{ itemsQuery.data.value.meta.current_page }} of {{ itemsQuery.data.value.meta.last_page }}</p>
                    <div class="flex gap-2">
                        <button type="button" :disabled="page <= 1 || itemsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-not-allowed disabled:opacity-50" @click="changePage(page - 1)">Previous</button>
                        <button type="button" :disabled="page >= itemsQuery.data.value.meta.last_page || itemsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-not-allowed disabled:opacity-50" @click="changePage(page + 1)">Next</button>
                    </div>
                </div>
            </template>
        </section>
    </section>
</template>
