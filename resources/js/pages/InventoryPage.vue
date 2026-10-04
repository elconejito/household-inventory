<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useCreateItemMutation, useItemCategoryOptionsQuery, useItemsQuery } from '../queries/items';
import { useLocationsQuery } from '../queries/stock';
import type { NewItem } from '../api/items';
import ItemEditor from '../components/ItemEditor.vue';
import InventoryTabs from '../components/InventoryTabs.vue';
import { buildLocationPath } from '../api/stock';

const route = useRoute();
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
const perPage = ref<10 | 25 | 50 | 100>(10);
const categoryId = ref('');
const locationId = ref('');
const sort = ref<'name' | '-name'>('name');
const showCreateForm = ref(false);
const addItemButton = ref<HTMLButtonElement | null>(null);
const successMessage = ref('');
const formErrors = ref<FormErrors>({ fields: {}, form: '' });
let searchTimeout: ReturnType<typeof setTimeout> | undefined;

const listParams = computed(() => ({
    search: debouncedSearch.value,
    categoryId: categoryId.value,
    locationId: locationId.value,
    sort: sort.value,
    page: page.value,
    perPage: perPage.value,
}));
const itemsQuery = useItemsQuery(listParams);
const pageHasPrimaryImage = computed(() => itemsQuery.data.value?.data.some((item) => item.images?.some((image) => image.is_primary)) ?? false);
const createMutation = useCreateItemMutation();
const categoryOptionsQuery = useItemCategoryOptionsQuery();
const locationsQuery = useLocationsQuery();
const selectedLocationName = computed(() => {
    const selectedLocation = locationsQuery.data.value?.find((location) => location.id === locationId.value);
    return selectedLocation ? buildLocationPath(selectedLocation, locationsQuery.data.value ?? []) : 'selected location';
});
const hasActiveFilters = computed(() => Boolean(debouncedSearch.value || categoryId.value || locationId.value));

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

watch([categoryId, locationId, sort, perPage], () => {
    page.value = 1;
});

watch(() => itemsQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && page.value > lastPage) page.value = lastPage;
});

onUnmounted(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});

function toggleCreateForm(): void {
    showCreateForm.value = !showCreateForm.value;
    successMessage.value = '';
    formErrors.value = { fields: {}, form: '' };
}

async function submitCreateForm(itemData: NewItem): Promise<void> {
    if (createMutation.isPending.value) return;
    successMessage.value = '';
    formErrors.value = { fields: {}, form: '' };

    try {
        const item = await createMutation.mutateAsync(itemData);

        showCreateForm.value = false;
        successMessage.value = `${item.name} was added to your inventory.`;
        await nextTick();
        addItemButton.value?.focus();
    } catch (error) {
        formErrors.value = parseApiErrors(error);
    }
}

function quantityAtLocation(item: { inventory_levels?: Array<{ location: { id: string }; quantity: number }> }): number {
    return (item.inventory_levels ?? []).filter((level) => level.location.id === locationId.value).reduce((total, level) => total + level.quantity, 0);
}

function unitFor(item: { counting_unit: string; counting_unit_plural?: string }, quantity: number): string {
    return quantity === 1 ? item.counting_unit : item.counting_unit_plural ?? item.counting_unit;
}

function clearFilters(): void {
    search.value = '';
    debouncedSearch.value = '';
    categoryId.value = '';
    locationId.value = '';
    sort.value = 'name';
    perPage.value = 10;
    page.value = 1;
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

        <InventoryTabs />

        <p v-if="successMessage" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status" aria-live="polite">
            {{ successMessage }}
        </p>

        <ItemEditor v-if="showCreateForm" id="create-item-panel" class="mt-6" :item="null" :saving="createMutation.isPending.value" :errors="formErrors" @submit="submitCreateForm" @cancel="toggleCreateForm" />

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

            <div class="grid gap-3 border-b border-line px-5 py-4 sm:grid-cols-2 lg:grid-cols-4 sm:px-6">
                <div class="grid gap-1.5">
                    <label for="item-category-filter" class="text-xs font-semibold text-ink-muted">Category</label>
                    <select id="item-category-filter" v-model="categoryId" :disabled="categoryOptionsQuery.isPending.value || categoryOptionsQuery.isError.value" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm disabled:bg-surface-soft">
                        <option value="">All categories</option>
                        <option v-for="category in categoryOptionsQuery.data.value ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                    <p v-if="categoryOptionsQuery.isError.value" class="text-xs text-rose-700" role="alert">Categories unavailable. <button type="button" class="underline" @click="categoryOptionsQuery.refetch()">Retry</button></p>
                    <p v-else-if="categoryOptionsQuery.isPending.value" class="text-xs text-ink-muted" role="status">Loading categories…</p>
                </div>
                <div class="grid gap-1.5">
                    <label for="item-location-filter" class="text-xs font-semibold text-ink-muted">Location</label>
                    <select id="item-location-filter" v-model="locationId" :disabled="locationsQuery.isPending.value || locationsQuery.isError.value" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm disabled:bg-surface-soft">
                        <option value="">All locations</option>
                        <option v-for="location in locationsQuery.data.value ?? []" :key="location.id" :value="location.id">{{ buildLocationPath(location, locationsQuery.data.value ?? []) }}</option>
                    </select>
                    <p v-if="locationsQuery.isError.value" class="text-xs text-rose-700" role="alert">Locations unavailable. <button type="button" class="underline" @click="locationsQuery.refetch()">Retry</button></p>
                    <p v-else-if="locationsQuery.isPending.value" class="text-xs text-ink-muted" role="status">Loading locations…</p>
                </div>
                <div class="grid gap-1.5">
                    <label for="item-sort" class="text-xs font-semibold text-ink-muted">Sort by name</label>
                    <select id="item-sort" v-model="sort" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm"><option value="name">A to Z</option><option value="-name">Z to A</option></select>
                </div>
                <div class="grid gap-1.5">
                    <label for="item-page-size" class="text-xs font-semibold text-ink-muted">Items per page</label>
                    <select id="item-page-size" v-model.number="perPage" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm"><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select>
                </div>
            </div>

            <div v-if="itemsQuery.data.value?.data.length" class="hidden gap-6 border-b border-line bg-surface-soft/60 px-6 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted sm:grid" :class="pageHasPrimaryImage ? 'grid-cols-[3.5rem_minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]' : 'grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]'" aria-hidden="true">
                <span v-if="pageHasPrimaryImage" aria-hidden="true"></span>
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
                        <h3 class="mt-4 text-base font-semibold text-ink">{{ hasActiveFilters ? 'No items match these filters' : 'Your inventory is ready for a first item' }}</h3>
                        <p class="mt-2 text-sm leading-6 text-ink-muted">
                            {{ hasActiveFilters ? 'Try changing your search or filters.' : 'Add a supply you keep at home to start building your list.' }}
                        </p>
                        <button v-if="hasActiveFilters" type="button" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-md border border-line px-4 text-sm font-semibold text-ink hover:bg-surface-soft" @click="clearFilters">Clear filters</button>
                        <button v-if="!debouncedSearch" type="button" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="toggleCreateForm">
                            Add your first item
                        </button>
                    </div>
                </div>

                <ul v-else class="divide-y divide-line" aria-label="Inventory items">
                    <li v-for="item in itemsQuery.data.value.data" :key="item.id" class="px-5 py-4 sm:px-6 sm:py-5">
                        <article class="grid gap-3 sm:items-start sm:gap-4" :class="pageHasPrimaryImage ? 'sm:grid-cols-[3.5rem_minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]' : 'sm:grid-cols-[minmax(0,1.3fr)_9rem_8rem_minmax(0,1fr)]'">
                            <img v-if="item.images?.find((image) => image.is_primary)" :src="item.images.find((image) => image.is_primary)?.thumbnail_url" :alt="item.images.find((image) => image.is_primary)?.caption || ''" class="size-14 rounded-md bg-surface-soft object-cover" loading="lazy">
                            <span v-else-if="pageHasPrimaryImage" class="hidden size-14 sm:block" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <h3 class="break-words text-base font-semibold text-ink"><RouterLink :to="{ name: 'inventory-item', params: { item: item.id } }" class="rounded-sm hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">{{ item.name }}</RouterLink></h3>
                                <p v-if="item.description" class="mt-1 line-clamp-2 whitespace-pre-line text-sm leading-5 text-ink-muted">{{ item.description }}</p>
                            </div>
                            <p class="text-sm text-ink-muted sm:pt-0.5">
                                <span class="sr-only">Counting unit: </span>
                                Counted by <span class="font-medium text-ink">{{ item.counting_unit }}</span>
                            </p>
                            <div class="text-sm font-semibold text-ink sm:pt-0.5"><p>{{ item.total_quantity ?? 0 }} {{ unitFor(item, item.total_quantity ?? 0) }} <span class="font-normal text-ink-muted">total on hand</span></p><p v-if="locationId" class="mt-1 text-xs font-normal text-ink-muted">At {{ selectedLocationName }}: {{ quantityAtLocation(item) }} {{ unitFor(item, quantityAtLocation(item)) }}</p><ul v-if="item.inventory_levels?.some((level) => level.quantity > 0)" class="mt-2 grid gap-1 text-xs font-normal text-ink-muted"><li v-for="level in item.inventory_levels.filter((candidate) => candidate.quantity > 0)" :key="level.id">{{ buildLocationPath(level.location, locationsQuery.data.value ?? []) }} — {{ level.quantity }} {{ unitFor(item, level.quantity) }}</li></ul></div>
                            <div class="flex min-w-0 flex-wrap gap-2 sm:pt-0.5">
                                <RouterLink v-for="category in item.categories ?? []" :key="category.id" :to="{ name: 'category-detail', params: { category: category.id } }" class="max-w-full truncate rounded-md bg-sage-soft px-2.5 py-1 text-xs font-medium text-sage-dark hover:underline">{{ category.name }}</RouterLink>
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
