<script setup lang="ts">
import FormModal from '../components/FormModal.vue';
import ListPagination from '../components/ListPagination.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { parseApiErrors } from '../lib/api-errors';
import { useItemCategoryOptionsQuery, useItemsQuery } from '../queries/items';
import { useLocationsQuery } from '../queries/stock';
import ItemCreationPanel from '../components/ItemCreationPanel.vue';
import InventoryTabs from '../components/InventoryTabs.vue';
import ItemStockSummary from '../components/ItemStockSummary.vue';
import StockStatusBadge from '../components/StockStatusBadge.vue';
import { buildLocationPath } from '../api/stock';
import { hasActiveBuySoonAlert } from '../lib/stock-indicators';

const route = useRoute();
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
const perPage = ref<10 | 25 | 50 | 100>(10);
const categoryId = ref('');
const locationId = ref('');
const stockStatus = ref<'' | 'in_stock' | 'empty'>('');
const attentionStatus = ref<'' | 'needs_attention' | 'empty' | 'low' | 'buy_soon' | 'none'>('');
const sort = ref<'name' | '-name'>('name');
const showCreateForm = ref(false);
const creationBusy = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | undefined;

const listParams = computed(() => ({
    search: debouncedSearch.value,
    categoryId: categoryId.value,
    locationId: locationId.value,
    stockStatus: stockStatus.value || undefined,
    attentionStatus: attentionStatus.value || undefined,
    sort: sort.value,
    page: page.value,
    perPage: perPage.value,
}));
const itemsQuery = useItemsQuery(listParams);
const pageHasPrimaryImage = computed(() => itemsQuery.data.value?.data.some((item) => item.images?.some((image) => image.is_primary)) ?? false);
const categoryOptionsQuery = useItemCategoryOptionsQuery();
const locationsQuery = useLocationsQuery();
const hasActiveFilters = computed(() => Boolean(debouncedSearch.value || categoryId.value || locationId.value || stockStatus.value || attentionStatus.value));

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

watch([categoryId, locationId, stockStatus, attentionStatus, sort, perPage], () => {
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
    if (creationBusy.value) return;
    showCreateForm.value = !showCreateForm.value;
}

function clearFilters(): void {
    search.value = '';
    debouncedSearch.value = '';
    categoryId.value = '';
    locationId.value = '';
    stockStatus.value = '';
    attentionStatus.value = '';
    sort.value = 'name';
    perPage.value = 10;
    page.value = 1;
}

function retryLoading(): void {
    void itemsQuery.refetch();
}

function changePage(nextPage: number): void {
    if (Number.isInteger(nextPage) && nextPage >= 1 && nextPage <= (itemsQuery.data.value?.meta.last_page ?? 1)) {
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
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 id="page-title" class="page-title">Inventory</h1>
            </div>
            <button
                type="button"
                aria-haspopup="dialog" :aria-expanded="showCreateForm"
                aria-controls="create-item-panel"
                :disabled="creationBusy"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage"
                @click="toggleCreateForm"
            >
                <span aria-hidden="true">＋</span>
                Add item
            </button>
        </div>

        <InventoryTabs />

        <FormModal v-if="showCreateForm" label="Add item" :busy="creationBusy" @close="showCreateForm = false">
            <ItemCreationPanel @busy="creationBusy = $event" @cancel="showCreateForm = false" />
        </FormModal>

        <section class="mt-7 rounded-panel border border-line bg-white shadow-card" aria-label="Inventory" :aria-busy="itemsQuery.isFetching.value">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div class="relative min-w-0 flex-1">
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
                <button type="button" popovertarget="inventory-filters" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-line px-4 text-sm font-semibold text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">
                    Filters
                    <span v-if="categoryId || locationId || stockStatus || attentionStatus" class="size-2 rounded-full bg-sage" role="img" aria-label="Filters active"></span>
                </button>
            </div>

            <div id="inventory-filters" popover aria-labelledby="inventory-filters-heading" class="fixed inset-0 m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-panel border border-line bg-white p-5 text-ink shadow-card backdrop:bg-ink/20 sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-4">
                    <h2 id="inventory-filters-heading" class="text-base font-semibold">Filters and display</h2>
                    <button type="button" popovertarget="inventory-filters" popovertargetaction="hide" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Done</button>
                </div>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-category-filter" class="text-xs font-semibold text-ink-muted">Category</label>
                        <select id="item-category-filter" v-model="categoryId" :disabled="categoryOptionsQuery.isPending.value || categoryOptionsQuery.isError.value" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm disabled:bg-surface-soft">
                            <option value="">{{ categoryOptionsQuery.isPending.value ? 'Loading categories…' : 'All categories' }}</option>
                            <option v-for="category in categoryOptionsQuery.data.value ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <p v-if="categoryOptionsQuery.isError.value" class="text-xs text-rose-700" role="alert">Categories unavailable. <button type="button" class="underline" @click="categoryOptionsQuery.refetch()">Retry</button></p>
                        <p v-else-if="categoryOptionsQuery.isPending.value" class="sr-only" role="status">Loading categories…</p>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-location-filter" class="text-xs font-semibold text-ink-muted">Location</label>
                        <select id="item-location-filter" v-model="locationId" :disabled="locationsQuery.isPending.value || locationsQuery.isError.value" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm disabled:bg-surface-soft">
                            <option value="">{{ locationsQuery.isPending.value ? 'Loading locations…' : 'All locations' }}</option>
                            <option v-for="location in locationsQuery.data.value ?? []" :key="location.id" :value="location.id">{{ buildLocationPath(location, locationsQuery.data.value ?? []) }}</option>
                        </select>
                        <p v-if="locationsQuery.isError.value" class="text-xs text-rose-700" role="alert">Locations unavailable. <button type="button" class="underline" @click="locationsQuery.refetch()">Retry</button></p>
                        <p v-else-if="locationsQuery.isPending.value" class="sr-only" role="status">Loading locations…</p>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-stock-filter" class="text-xs font-semibold text-ink-muted">Stock on hand</label>
                        <select id="item-stock-filter" v-model="stockStatus" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm">
                            <option value="">All stock levels</option>
                            <option value="in_stock">Stock on hand</option>
                            <option value="empty">No stock on hand</option>
                        </select>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-attention-filter" class="text-xs font-semibold text-ink-muted">Attention</label>
                        <select id="item-attention-filter" v-model="attentionStatus" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm">
                            <option value="">All attention states</option>
                            <option value="needs_attention">Needs attention</option>
                            <option value="empty">Empty monitored location</option>
                            <option value="low">Low monitored location</option>
                            <option value="buy_soon">Buy soon</option>
                            <option value="none">No attention needed</option>
                        </select>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-sort" class="text-xs font-semibold text-ink-muted">Sort by name</label>
                        <select id="item-sort" v-model="sort" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm"><option value="name">A to Z</option><option value="-name">Z to A</option></select>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-page-size" class="text-xs font-semibold text-ink-muted">Items per page</label>
                        <select id="item-page-size" v-model.number="perPage" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm"><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select>
                    </div>
                </div>
                <p class="mt-4 text-xs leading-5 text-ink-muted">Stock and attention filters evaluate the whole item across every active location. The Location filter limits the list to items tracked there and changes the per-location details shown.</p>

                <button type="button" class="mt-4 min-h-10 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="clearFilters">Reset filters</button>
            </div>

            <div v-if="itemsQuery.data.value?.data.length" class="hidden gap-4 border-b border-line bg-surface-soft/60 px-6 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted sm:grid" :class="pageHasPrimaryImage ? 'grid-cols-[3.5rem_minmax(0,calc((100%_-_24.5rem)*13/23))_minmax(0,1fr)_minmax(0,0.65fr)]' : 'grid-cols-[minmax(0,calc((100%_-_20rem)*13/23))_minmax(0,1fr)_minmax(0,0.65fr)]'" aria-hidden="true">
                <span v-if="pageHasPrimaryImage" aria-hidden="true"></span>
                <span>Item</span>
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
                        <button v-if="!hasActiveFilters" type="button" class="mt-4 inline-flex min-h-10 items-center justify-center rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="toggleCreateForm">
                            Add your first item
                        </button>
                    </div>
                </div>

                <ul v-else class="divide-y divide-line" aria-label="Inventory items">
                    <li v-for="item in itemsQuery.data.value.data" :key="item.id" class="px-5 py-4 sm:px-6 sm:py-5">
                        <article class="grid gap-3 sm:items-start sm:gap-4" :class="pageHasPrimaryImage ? 'sm:grid-cols-[3.5rem_minmax(0,calc((100%_-_24.5rem)*13/23))_minmax(0,1fr)_minmax(0,0.65fr)]' : 'sm:grid-cols-[minmax(0,calc((100%_-_20rem)*13/23))_minmax(0,1fr)_minmax(0,0.65fr)]'">
                            <img v-if="item.images?.find((image) => image.is_primary)" :src="item.images.find((image) => image.is_primary)?.thumbnail_url" :alt="item.images.find((image) => image.is_primary)?.caption || ''" class="size-14 rounded-md bg-surface-soft object-cover" loading="lazy">
                            <span v-else-if="pageHasPrimaryImage" class="hidden size-14 sm:block" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <h3 class="break-words text-base font-semibold text-ink"><RouterLink :to="{ name: 'inventory-item', params: { item: item.id } }" class="rounded-sm hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">{{ item.name }}</RouterLink></h3>
                                <div v-if="hasActiveBuySoonAlert(item.active_alerts)" class="mt-2"><StockStatusBadge label="Buy soon" tone="reminder" /></div>
                                <p v-if="item.description" class="mt-1 line-clamp-2 whitespace-pre-line text-sm leading-5 text-ink-muted">{{ item.description }}</p>
                            </div>
                            <ItemStockSummary :item="item" :locations="locationsQuery.data.value ?? []" :exact-location-id="locationId" />
                            <div class="flex min-w-0 flex-wrap gap-2 sm:pt-0.5">
                                <RouterLink v-for="category in item.categories ?? []" :key="category.id" :to="{ name: 'category-detail', params: { category: category.id } }" class="max-w-full truncate rounded-md bg-sage-soft px-2.5 py-1 text-xs font-medium text-sage-dark hover:underline">{{ category.name }}</RouterLink>
                                <span v-if="!item.categories?.length" class="text-sm text-ink-muted">{{ item.description ? 'No categories yet' : 'No description or categories yet' }}</span>
                            </div>
                        </article>
                    </li>
                </ul>

                <ListPagination :meta="itemsQuery.data.value.meta" :page="page" :busy="itemsQuery.isFetching.value" label="Inventory pagination" input-id="inventory-page-number" @change="changePage" />
            </template>
        </section>
    </section>
</template>
