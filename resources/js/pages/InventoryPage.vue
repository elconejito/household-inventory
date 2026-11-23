<script setup lang="ts">
import FormModal from '../components/FormModal.vue';
import ListPagination from '../components/ListPagination.vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { parseApiErrors } from '../lib/api-errors';
import { useItemCategoryOptionsQuery, useItemsQuery } from '../queries/items';
import { useLocationsQuery } from '../queries/stock';
import { itemBrowseRouteQuery, readItemBrowseRouteState } from '../lib/item-browse-state';
import ItemCreationPanel from '../components/ItemCreationPanel.vue';
import ItemStockSummary from '../components/ItemStockSummary.vue';
import StockStatusBadge from '../components/StockStatusBadge.vue';
import UiIcon from '../components/UiIcon.vue';
import { buildLocationPath } from '../api/stock';
import { hasActiveBuySoonAlert } from '../lib/stock-indicators';
import { getMonitoredAttentionLevels } from '../lib/stock-indicators';
import { useSessionStore } from '../stores/session';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
const perPage = ref<10 | 25 | 50 | 100>(10);
const categoryId = ref('');
const locationId = ref('');
const stockStatus = ref<'' | 'in_stock' | 'empty'>('');
const attentionStatus = ref<'' | 'needs_attention' | 'empty' | 'low' | 'buy_soon' | 'none'>('');
const sort = ref<'name' | '-name'>('name');
const draftCategoryId = ref('');
const draftLocationId = ref('');
const draftStockStatus = ref<'' | 'in_stock' | 'empty'>('');
const draftAttentionStatus = ref<'' | 'needs_attention' | 'empty' | 'low' | 'buy_soon' | 'none'>('');
const draftSort = ref<'name' | '-name'>('name');
const showCreateForm = ref(false);
const showFiltersModal = ref(false);
const creationBusy = ref(false);
let searchTimeout: ReturnType<typeof setTimeout> | undefined;
type RecentItem = { id: string; name: string; category: string };
const recentlyViewed = ref<RecentItem[]>([]);
const recentlyViewedPage = ref(1);
const recentlyViewedPerPage = ref<10 | 25 | 50 | 100>(10);
const recentStorageKey = computed(() => {
    const householdId = session.user?.membership?.household?.id;
    return householdId ? `inventory-recently-viewed:${householdId}` : '';
});

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
const categoryOptionsQuery = useItemCategoryOptionsQuery();
const locationsQuery = useLocationsQuery();
const hasActiveFilters = computed(() => Boolean(debouncedSearch.value || categoryId.value || locationId.value || stockStatus.value || attentionStatus.value));
const activeFilterCount = computed(() => [categoryId.value, locationId.value, stockStatus.value, attentionStatus.value].filter(Boolean).length);
const recentPageMeta = computed(() => ({
    total: recentlyViewed.value.length,
    current_page: recentlyViewedPage.value,
    last_page: Math.max(1, Math.ceil(recentlyViewed.value.length / recentlyViewedPerPage.value)),
}));
const recentPageItems = computed(() => {
    const start = (recentlyViewedPage.value - 1) * recentlyViewedPerPage.value;
    return recentlyViewed.value.slice(start, start + recentlyViewedPerPage.value);
});
const selectedLocationPath = computed(() => {
    const location = (locationsQuery.data.value ?? []).find((candidate) => candidate.id === locationId.value);
    return location ? buildLocationPath(location, locationsQuery.data.value ?? []) : '';
});

watch(() => route.query.create, (value) => {
    if (value === '1') {
        showCreateForm.value = true;
    }
}, { immediate: true });

watch(() => route.query, (query) => {
    const state = readItemBrowseRouteState(query);
    search.value = state.search;
    debouncedSearch.value = state.search.trim();
    categoryId.value = state.categoryId;
    locationId.value = state.locationId;
    stockStatus.value = state.stockStatus ?? '';
    attentionStatus.value = state.attentionStatus ?? '';
    sort.value = state.sort ?? 'name';
    perPage.value = state.perPage;
    page.value = state.page;

    if (['resultIds', 'resultLastPage', 'origin', 'originId', 'setup', 'restock', 'restock_location'].some((key) => key in query)) {
        void router.replace({ name: 'inventory', query: inventoryListRouteQuery(query, state) });
    }
}, { immediate: true });

watch(search, (value) => {
    if (value.trim() === debouncedSearch.value) {
        return;
    }

    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        page.value = 1;
        debouncedSearch.value = value.trim();
    }, 300);
});

watch(listParams, (params) => {
    void router.replace({
        name: 'inventory',
        query: inventoryListRouteQuery(route.query, {
            search: params.search,
            categoryId: params.categoryId,
            locationId: params.locationId,
            stockStatus: params.stockStatus,
            attentionStatus: params.attentionStatus,
            sort: params.sort,
            page: params.page,
            perPage: params.perPage,
        }),
    });
});

watch(() => itemsQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && page.value > lastPage) page.value = lastPage;
});

onUnmounted(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});

function loadRecentlyViewed(): void {
    if (!recentStorageKey.value) {
        recentlyViewed.value = [];
        return;
    }
    try {
        const storedItems: unknown = JSON.parse(window.sessionStorage.getItem(recentStorageKey.value) ?? '[]');
        if (Array.isArray(storedItems)) {
            recentlyViewed.value = storedItems.filter((item): item is RecentItem => Boolean(item)
                && typeof item.id === 'string'
                && typeof item.name === 'string'
                && typeof item.category === 'string').slice(0, 50);
        }
    } catch {
        recentlyViewed.value = [];
    }
    recentlyViewedPage.value = 1;
}

onMounted(loadRecentlyViewed);
watch(recentStorageKey, loadRecentlyViewed);
watch(() => recentlyViewed.value.length, () => {
    if (recentlyViewedPage.value > recentPageMeta.value.last_page) {
        recentlyViewedPage.value = recentPageMeta.value.last_page;
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

function openFiltersModal(): void {
    draftCategoryId.value = categoryId.value;
    draftLocationId.value = locationId.value;
    draftStockStatus.value = stockStatus.value;
    draftAttentionStatus.value = attentionStatus.value;
    draftSort.value = sort.value;
    showFiltersModal.value = true;
}

function applyFilters(): void {
    categoryId.value = draftCategoryId.value;
    locationId.value = draftLocationId.value;
    stockStatus.value = draftStockStatus.value;
    attentionStatus.value = draftAttentionStatus.value;
    sort.value = draftSort.value;
    page.value = 1;
    showFiltersModal.value = false;
}

function resetFilterDrafts(): void {
    draftCategoryId.value = '';
    draftLocationId.value = '';
    draftStockStatus.value = '';
    draftAttentionStatus.value = '';
    draftSort.value = 'name';
}

function retryLoading(): void {
    void itemsQuery.refetch();
}

function changePage(nextPage: number): void {
    if (Number.isInteger(nextPage) && nextPage >= 1 && nextPage <= (itemsQuery.data.value?.meta.last_page ?? 1)) {
        page.value = nextPage;
    }
}

function inventoryListRouteQuery(sourceQuery: typeof route.query, state: ReturnType<typeof readItemBrowseRouteState>) {
    const query = { ...sourceQuery };
    for (const key of ['search', 'category', 'location', 'stockStatus', 'attentionStatus', 'sort', 'page', 'perPage', 'resultIds', 'resultLastPage', 'origin', 'originId', 'setup', 'restock', 'restock_location']) {
        delete query[key];
    }

    return {
        ...query,
        ...itemBrowseRouteQuery(state, []),
    };
}

function itemRouteQuery(itemId: string) {
    const resultIds = (itemsQuery.data.value?.data ?? []).map((item) => item.id);
    if (!resultIds.includes(itemId)) {
        resultIds.push(itemId);
    }

    return itemBrowseRouteQuery({
        search: debouncedSearch.value,
        categoryId: categoryId.value,
        locationId: locationId.value,
        stockStatus: stockStatus.value || undefined,
        attentionStatus: attentionStatus.value || undefined,
        sort: sort.value,
        page: page.value,
        perPage: perPage.value,
    }, resultIds, itemsQuery.data.value?.meta.last_page);
}

function rememberRecentlyViewed(item: { id: string; name: string; categories?: Array<{ name: string }> }): void {
    recentlyViewed.value = [
        { id: item.id, name: item.name, category: item.categories?.map((category) => category.name).join(' · ') ?? '' },
        ...recentlyViewed.value.filter((recentItem) => recentItem.id !== item.id),
    ].slice(0, 50);
    if (recentStorageKey.value) {
        window.sessionStorage.setItem(recentStorageKey.value, JSON.stringify(recentlyViewed.value));
    }
}

function clearFilter(name: 'search' | 'category' | 'location' | 'stock' | 'attention'): void {
    if (name === 'search') {
        search.value = '';
        debouncedSearch.value = '';
        page.value = 1;
        return;
    }
    if (name === 'category') categoryId.value = '';
    if (name === 'location') locationId.value = '';
    if (name === 'stock') stockStatus.value = '';
    if (name === 'attention') attentionStatus.value = '';
    page.value = 1;
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
                <h1 id="page-title" class="page-title">Items</h1>
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

        <FormModal v-if="showCreateForm" label="Add item" :busy="creationBusy" @close="showCreateForm = false">
            <ItemCreationPanel @busy="creationBusy = $event" @cancel="showCreateForm = false" />
        </FormModal>

        <div class="mt-7 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <section class="min-w-0 rounded-panel border border-line bg-white shadow-card" aria-label="Inventory" :aria-busy="itemsQuery.isFetching.value">
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
                <button type="button" aria-haspopup="dialog" :aria-expanded="showFiltersModal" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-line px-4 text-sm font-semibold text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="openFiltersModal">
                    Filters<span v-if="activeFilterCount" class="text-ink-muted">· {{ activeFilterCount }}</span>
                </button>
            </div>

            <FormModal v-if="showFiltersModal" label="Filters and display" @close="showFiltersModal = false">
                <div class="p-5 sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-4">
                    <h2 id="inventory-filters-heading" class="text-base font-semibold">Filters and display</h2>
                    <button type="button" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="showFiltersModal = false">Close</button>
                </div>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-category-filter" class="text-xs font-semibold text-ink-muted">Category</label>
                        <select id="item-category-filter" v-model="draftCategoryId" :disabled="categoryOptionsQuery.isPending.value || categoryOptionsQuery.isError.value" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm disabled:bg-surface-soft">
                            <option value="">{{ categoryOptionsQuery.isPending.value ? 'Loading categories…' : 'All categories' }}</option>
                            <option v-for="category in categoryOptionsQuery.data.value ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                        <p v-if="categoryOptionsQuery.isError.value" class="text-xs text-rose-700" role="alert">Categories unavailable. <button type="button" class="underline" @click="categoryOptionsQuery.refetch()">Retry</button></p>
                        <p v-else-if="categoryOptionsQuery.isPending.value" class="sr-only" role="status">Loading categories…</p>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-location-filter" class="text-xs font-semibold text-ink-muted">Location</label>
                        <select id="item-location-filter" v-model="draftLocationId" :disabled="locationsQuery.isPending.value || locationsQuery.isError.value" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm disabled:bg-surface-soft">
                            <option value="">{{ locationsQuery.isPending.value ? 'Loading locations…' : 'All locations' }}</option>
                            <option v-for="location in locationsQuery.data.value ?? []" :key="location.id" :value="location.id">{{ buildLocationPath(location, locationsQuery.data.value ?? []) }}</option>
                        </select>
                        <p v-if="locationsQuery.isError.value" class="text-xs text-rose-700" role="alert">Locations unavailable. <button type="button" class="underline" @click="locationsQuery.refetch()">Retry</button></p>
                        <p v-else-if="locationsQuery.isPending.value" class="sr-only" role="status">Loading locations…</p>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-stock-filter" class="text-xs font-semibold text-ink-muted">Stock on hand</label>
                        <select id="item-stock-filter" v-model="draftStockStatus" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm">
                            <option value="">All stock levels</option>
                            <option value="in_stock">Stock on hand</option>
                            <option value="empty">No stock on hand</option>
                        </select>
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <label for="item-attention-filter" class="text-xs font-semibold text-ink-muted">Attention</label>
                        <select id="item-attention-filter" v-model="draftAttentionStatus" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm">
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
                        <select id="item-sort" v-model="draftSort" class="min-h-10 w-full min-w-0 max-w-full select-input rounded-md border border-line bg-white pl-3 pr-10 text-sm"><option value="name">A to Z</option><option value="-name">Z to A</option></select>
                    </div>
                </div>
                <p class="mt-4 text-xs leading-5 text-ink-muted">Stock and attention filters evaluate the whole item across every active location. The Location filter limits the list to items tracked there and changes the per-location details shown.</p>

                <div class="mt-5 flex flex-wrap justify-between gap-3 border-t border-line pt-4">
                    <button type="button" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="resetFilterDrafts">Reset</button>
                    <div class="flex gap-2">
                        <button type="button" class="min-h-10 rounded-md border border-line px-4 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="showFiltersModal = false">Cancel</button>
                        <button type="button" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="applyFilters">Apply filters</button>
                    </div>
                </div>
                </div>
            </FormModal>

            <div v-if="hasActiveFilters" class="flex flex-wrap gap-2 border-b border-line px-5 py-3 sm:px-6" aria-label="Active filters">
                <button v-if="debouncedSearch" type="button" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-surface-soft px-3 text-xs font-medium text-ink" @click="clearFilter('search')">Search: {{ debouncedSearch }} <span aria-hidden="true">×</span></button>
                <button v-if="categoryId" type="button" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-surface-soft px-3 text-xs font-medium text-ink" @click="clearFilter('category')">{{ (categoryOptionsQuery.data.value ?? []).find((category) => category.id === categoryId)?.name ?? 'Category' }} <span aria-hidden="true">×</span></button>
                <button v-if="locationId" type="button" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-surface-soft px-3 text-xs font-medium text-ink" @click="clearFilter('location')">{{ selectedLocationPath || 'Location' }} <span aria-hidden="true">×</span></button>
                <button v-if="stockStatus" type="button" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-surface-soft px-3 text-xs font-medium text-ink" @click="clearFilter('stock')">{{ stockStatus === 'empty' ? 'Out of stock' : 'In stock' }} <span aria-hidden="true">×</span></button>
                <button v-if="attentionStatus" type="button" class="inline-flex min-h-8 items-center gap-1 rounded-full bg-surface-soft px-3 text-xs font-medium text-ink" @click="clearFilter('attention')">{{ attentionStatus === 'needs_attention' ? 'Needs attention' : attentionStatus === 'buy_soon' ? 'Buy soon' : attentionStatus === 'none' ? 'No attention needed' : attentionStatus === 'low' ? 'Low stock' : 'Empty monitored location' }} <span aria-hidden="true">×</span></button>
            </div>

            <div v-if="itemsQuery.data.value?.data.length" class="flex items-center justify-between gap-3 border-b border-line bg-surface-soft/50 px-5 py-3 sm:px-6">
                <h2 class="text-sm font-semibold text-ink">{{ selectedLocationPath ? `Items at ${selectedLocationPath}` : 'Household inventory' }}</h2>
                <span class="text-xs text-ink-muted">{{ sort === 'name' ? 'Name A–Z' : 'Name Z–A' }}</span>
            </div>

            <div v-if="itemsQuery.data.value?.data.length" class="hidden gap-4 border-b border-line bg-surface-soft/60 px-6 py-2.5 text-xs font-semibold uppercase tracking-wide text-ink-muted sm:grid sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.75fr)]" aria-hidden="true">
                <span>Item / category</span>
                <span>{{ selectedLocationPath ? 'At this location' : 'Total on hand' }}</span>
                <span>Household alerts</span>
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
                    <li v-for="item in itemsQuery.data.value.data" :id="`inventory-item-${item.id}`" :key="item.id" class="px-5 py-4 sm:px-6 sm:py-5">
                        <article class="grid gap-3 sm:items-start sm:gap-4 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.75fr)]">
                            <div class="flex min-w-0 items-start gap-3">
                                <img v-if="item.images?.find((image) => image.is_primary)" :src="item.images.find((image) => image.is_primary)?.thumbnail_url" :alt="item.images.find((image) => image.is_primary)?.caption || ''" class="size-12 shrink-0 rounded-md bg-surface-soft object-cover" loading="lazy">
                                <span v-else class="grid size-12 shrink-0 place-items-center rounded-md bg-sage-soft text-sage" aria-hidden="true"><UiIcon name="box" /></span>
                                <div class="min-w-0">
                                <h3 class="break-words text-base font-semibold text-ink"><RouterLink :to="{ name: 'inventory-item', params: { item: item.id }, query: itemRouteQuery(item.id) }" class="rounded-sm hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="rememberRecentlyViewed(item)">{{ item.name }}</RouterLink></h3>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <RouterLink v-for="category in item.categories ?? []" :key="category.id" :to="{ name: 'category-detail', params: { category: category.id } }" class="max-w-full truncate rounded-md bg-sage-soft px-2 py-0.5 text-xs font-medium text-sage-dark hover:underline">{{ category.name }}</RouterLink>
                                    <span v-if="!item.categories?.length" class="text-xs text-ink-muted">No category</span>
                                </div>
                            </div>
                            </div>
                            <ItemStockSummary :item="item" :locations="locationsQuery.data.value ?? []" :exact-location-id="locationId" :show-attention="false" />
                            <div class="flex min-w-0 flex-wrap items-start gap-1.5 sm:pt-0.5">
                                <StockStatusBadge v-if="hasActiveBuySoonAlert(item.active_alerts)" label="Buy soon" tone="reminder" />
                                <StockStatusBadge v-for="{ level, indicator } in getMonitoredAttentionLevels(item.inventory_levels ?? [])" :key="level.id" :label="`${indicator.label} · ${buildLocationPath(level.location, locationsQuery.data.value ?? [])}`" :tone="indicator.tone" />
                                <span v-if="!hasActiveBuySoonAlert(item.active_alerts) && !getMonitoredAttentionLevels(item.inventory_levels ?? []).length" class="text-sm text-ink-muted">—</span>
                            </div>
                        </article>
                    </li>
                </ul>

                <ListPagination :meta="itemsQuery.data.value.meta" :page="page" v-model:per-page="perPage" :busy="itemsQuery.isFetching.value" label="Inventory pagination" input-id="inventory-page-number" @change="changePage" />
            </template>
        </section>
        <aside class="hidden rounded-panel border border-line bg-white p-5 shadow-card lg:block" aria-labelledby="recently-viewed-title">
            <div class="flex items-center gap-2"><UiIcon name="clock" :size="17" /><h2 id="recently-viewed-title" class="text-base font-semibold text-ink">Recently viewed</h2></div>
            <p v-if="!recentlyViewed.length" class="mt-4 text-sm text-ink-muted">Items you open will appear here.</p>
            <ul v-else class="mt-4 divide-y divide-line">
                <li v-for="item in recentPageItems" :key="item.id" class="py-3 first:pt-0 last:pb-0">
                    <RouterLink :to="{ name: 'inventory-item', params: { item: item.id } }" class="font-medium text-ink hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">{{ item.name }}</RouterLink>
                    <p v-if="item.category" class="mt-1 text-sm text-ink-muted">{{ item.category }}</p>
                </li>
            </ul>
            <ListPagination v-if="recentlyViewed.length" v-model:per-page="recentlyViewedPerPage" :meta="recentPageMeta" :page="recentlyViewedPage" singular="item" plural="items" label="Recently viewed pagination" @change="recentlyViewedPage = $event" />
        </aside>
        </div>
    </section>
</template>
