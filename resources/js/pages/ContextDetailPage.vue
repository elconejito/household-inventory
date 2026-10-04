<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { useRoute } from 'vue-router';
import NotesPanel from '../components/NotesPanel.vue';
import ArchiveResourceButton from '../components/ArchiveResourceButton.vue';
import InventoryTabs from '../components/InventoryTabs.vue';
import ItemStockSummary from '../components/ItemStockSummary.vue';
import StockStatusBadge from '../components/StockStatusBadge.vue';
import LocationStockActions, { type LocationStockAction } from '../components/LocationStockActions.vue';
import { getCategory, getLocation } from '../api/note-contexts';
import { buildLocationPath, type Location as StockLocation } from '../api/stock';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { getStockLevelIndicators, hasActiveBuySoonAlert } from '../lib/stock-indicators';
import { useCategoryItemsQuery, useLocationLevelsQuery, useUpdateCatalogLocationMutation, useUpdateCategoryMutation } from '../queries/catalog';
import { useAllItemsQuery, useLocationsQuery, useRecordMovementMutation } from '../queries/stock';

const pageSizes = [10, 25, 50, 100];
const route = useRoute();
const contextType = computed(() => route.name === 'category-detail' ? 'categories' as const : 'locations' as const);
const contextId = computed(() => String(contextType.value === 'categories' ? route.params.category : route.params.location));
const categoryId = computed(() => contextType.value === 'categories' ? contextId.value : '');
const contextQuery = useQuery({
    queryKey: computed(() => [contextType.value, 'detail', contextId.value]),
    queryFn: () => contextType.value === 'categories' ? getCategory(contextId.value) : getLocation(contextId.value),
    enabled: computed(() => Boolean(contextId.value)),
});
const title = computed(() => contextType.value === 'categories' ? 'Category' : 'Location');
const locationOptions = useLocationsQuery();
const allItemsQuery = useAllItemsQuery(computed(() => contextType.value === 'locations'));
const categorySearch = ref('');
const debouncedCategorySearch = ref('');
const categoryPage = ref(1);
const categoryPageSize = ref(10);
const inventoryScope = ref<'all' | 'direct'>('all');
const inventoryPage = ref(1);
const inventoryPageSize = ref(10);
const showCategoryEdit = ref(false);
const showLocationEdit = ref(false);
const categoryName = ref('');
const locationName = ref('');
const locationDescription = ref('');
const locationParentId = ref('');
const formErrors = ref<FormErrors>({ fields: {}, form: '' });
const feedback = ref('');
const itemToRestock = ref('');
const categoryEditSnapshot = ref('');
const updateCategoryMutation = useUpdateCategoryMutation();
const updateLocationMutation = useUpdateCatalogLocationMutation();
const stockMovementMutation = useRecordMovementMutation();
const activeStockAction = ref<{ levelId: string; action: LocationStockAction } | null>(null);
const stockActionErrors = ref<FormErrors>({ fields: {}, form: '' });

const categoryParams = computed(() => ({ search: debouncedCategorySearch.value, page: categoryPage.value, perPage: categoryPageSize.value }));
const categoryItemsQuery = useCategoryItemsQuery(categoryId, categoryParams);
const categoryHasImages = computed(() => categoryItemsQuery.data.value?.data.some((item) => item.images?.some((image) => image.is_primary)) ?? false);
const location = computed(() => contextQuery.data.value as StockLocation | undefined);
const locationPath = computed(() => location.value ? buildLocationPath(location.value, locationOptions.data.value ?? []) : '');
const descendantLocationIds = computed(() => {
    const locations = locationOptions.data.value ?? [];
    const included = new Set<string>([contextId.value]);
    let frontier = [contextId.value];

    while (frontier.length > 0) {
        const children = locations.filter((candidate) => candidate.parent?.id && frontier.includes(candidate.parent.id));
        frontier = children.filter((candidate) => !included.has(candidate.id)).map((candidate) => candidate.id);
        frontier.forEach((id) => included.add(id));
    }

    return [...included];
});
const levelLocationIds = computed(() => contextType.value === 'locations' && locationOptions.isSuccess.value
    ? (inventoryScope.value === 'direct' ? [contextId.value] : descendantLocationIds.value)
    : []);
const inventoryParams = computed(() => ({ page: inventoryPage.value, perPage: inventoryPageSize.value }));
const locationLevelsQuery = useLocationLevelsQuery(levelLocationIds, inventoryParams);
const directChildren = computed(() => (locationOptions.data.value ?? [])
    .filter((candidate) => candidate.parent?.id === contextId.value)
    .sort((first, second) => first.name.localeCompare(second.name)));
const eligibleLocationParents = computed(() => {
    const excluded = new Set(descendantLocationIds.value);

    return (locationOptions.data.value ?? []).filter((candidate) => !excluded.has(candidate.id));
});
const canSaveCategory = computed(() => categoryName.value.trim().length > 0 && !updateCategoryMutation.isPending.value);
const canSaveLocation = computed(() => locationName.value.trim().length > 0 && !updateLocationMutation.isPending.value && locationOptions.isSuccess.value);
const editPending = computed(() => updateCategoryMutation.isPending.value || updateLocationMutation.isPending.value);

let searchTimeout: ReturnType<typeof setTimeout> | undefined;
let locationEditSnapshot: { name: string; description: string | null; parentId: string | null } | null = null;
watch(categorySearch, (value) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        categoryPage.value = 1;
        debouncedCategorySearch.value = value.trim();
    }, 300);
});
onUnmounted(() => {
    if (searchTimeout) clearTimeout(searchTimeout);
});

watch([contextType, contextId], () => {
    categorySearch.value = '';
    debouncedCategorySearch.value = '';
    categoryPage.value = 1;
    categoryPageSize.value = 10;
    inventoryScope.value = 'all';
    inventoryPage.value = 1;
    inventoryPageSize.value = 10;
    showCategoryEdit.value = false;
    showLocationEdit.value = false;
    categoryName.value = '';
    locationName.value = '';
    locationDescription.value = '';
    locationParentId.value = '';
    itemToRestock.value = '';
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    activeStockAction.value = null;
    stockActionErrors.value = { fields: {}, form: '' };
    categoryEditSnapshot.value = '';
    locationEditSnapshot = null;
}, { immediate: true });

function beginCategoryEdit(): void {
    categoryName.value = contextQuery.data.value?.name ?? '';
    categoryEditSnapshot.value = categoryName.value;
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    showCategoryEdit.value = true;
}

function beginLocationEdit(): void {
    if (!location.value) return;
    locationEditSnapshot = {
        name: location.value.name,
        description: location.value.description,
        parentId: location.value.parent?.id ?? null,
    };
    locationName.value = location.value?.name ?? '';
    locationDescription.value = location.value?.description ?? '';
    locationParentId.value = location.value?.parent?.id ?? '';
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    showLocationEdit.value = true;
}

function cancelEdit(): void {
    if (editPending.value) return;
    showCategoryEdit.value = false;
    showLocationEdit.value = false;
    locationEditSnapshot = null;
    formErrors.value = { fields: {}, form: '' };
}

async function saveCategory(): Promise<void> {
    if (!contextQuery.data.value || editPending.value) return;
    const savedContextType = contextType.value;
    const savedContextId = contextId.value;
    const originalName = categoryEditSnapshot.value;
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    const nextName = categoryName.value.trim();
    if (nextName === originalName) {
        showCategoryEdit.value = false;
        return;
    }

    try {
        await updateCategoryMutation.mutateAsync({ id: savedContextId, changes: { name: nextName } });
        if (contextType.value !== savedContextType || contextId.value !== savedContextId) return;
        showCategoryEdit.value = false;
        feedback.value = `${nextName} was updated.`;
    } catch (cause) {
        if (contextType.value === savedContextType && contextId.value === savedContextId) formErrors.value = parseApiErrors(cause);
    }
}

async function saveLocation(): Promise<void> {
    if (!location.value || !locationEditSnapshot || editPending.value) return;
    const savedContextType = contextType.value;
    const savedContextId = contextId.value;
    const original = { ...locationEditSnapshot };
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    const nextName = locationName.value.trim();
    const nextDescription = locationDescription.value.length > 0 ? locationDescription.value : null;
    const nextParentId = locationParentId.value || null;
    const currentParentId = original.parentId;
    const changes: { name?: string; description?: string | null; parent_id?: string | null } = {};

    if (nextName !== original.name) changes.name = nextName;
    if (nextDescription !== original.description) changes.description = nextDescription;
    if (nextParentId !== currentParentId) changes.parent_id = nextParentId;
    if (Object.keys(changes).length === 0) {
        showLocationEdit.value = false;
        return;
    }

    try {
        await updateLocationMutation.mutateAsync({ id: savedContextId, changes });
        if (contextType.value !== savedContextType || contextId.value !== savedContextId) return;
        showLocationEdit.value = false;
        feedback.value = `${nextName} was updated.`;
    } catch (cause) {
        if (contextType.value === savedContextType && contextId.value === savedContextId) formErrors.value = parseApiErrors(cause);
    }
}

function changePage(kind: 'category' | 'inventory', nextPage: number): void {
    const query = kind === 'category' ? categoryItemsQuery : locationLevelsQuery;
    const page = kind === 'category' ? categoryPage : inventoryPage;
    if (nextPage >= 1 && nextPage <= (query.data.value?.meta.last_page ?? 1)) {
        page.value = nextPage;
        if (kind === 'inventory') closeStockAction(true);
    }
}

function changePageSize(kind: 'category' | 'inventory', value: string): void {
    const nextSize = Number(value);
    if (!pageSizes.includes(nextSize)) return;
    if (kind === 'category') {
        categoryPageSize.value = nextSize;
        categoryPage.value = 1;
    } else {
        inventoryPageSize.value = nextSize;
        inventoryPage.value = 1;
        closeStockAction(true);
    }
}

function setInventoryScope(value: string): void {
    inventoryScope.value = value === 'direct' ? 'direct' : 'all';
    inventoryPage.value = 1;
    closeStockAction(true);
}

function locationForPath(levelLocation: StockLocation): string {
    return buildLocationPath(levelLocation, locationOptions.data.value ?? []);
}

function parseError(queryError: unknown, fallback: string): string {
    return parseApiErrors(queryError).form || fallback;
}

function itemDetailLocation(itemId: string, exactLocationId = contextId.value): { name: string; params: { item: string }; query: Record<string, string> } {
    return { name: 'inventory-item', params: { item: itemId }, query: { restock_location: exactLocationId } };
}

function beginStockAction(levelId: string, action: LocationStockAction): void {
    if (stockMovementMutation.isPending.value) return;
    activeStockAction.value = { levelId, action };
    stockActionErrors.value = { fields: {}, form: '' };
    feedback.value = '';
}

async function saveStockAction(levelId: string, data: Record<string, string | number>): Promise<void> {
    if (stockMovementMutation.isPending.value) return;
    const isQuickUse = data.movement_type === 'consumption' && data.quantity === 1;
    if (isQuickUse) {
        activeStockAction.value = null;
        stockActionErrors.value = { fields: {}, form: '' };
    } else if (activeStockAction.value?.levelId !== levelId) {
        return;
    }
    const savedContextKey = `${contextType.value}:${contextId.value}`;
    const actionWasOpen = !isQuickUse && activeStockAction.value?.levelId === levelId;
    stockActionErrors.value = { fields: {}, form: '' };
    feedback.value = '';

    try {
        await stockMovementMutation.mutateAsync(data);
        if (`${contextType.value}:${contextId.value}` !== savedContextKey || (actionWasOpen && activeStockAction.value?.levelId !== levelId) || (!actionWasOpen && activeStockAction.value)) return;
        if (actionWasOpen) activeStockAction.value = null;
        feedback.value = 'Stock updated.';
    } catch (cause) {
        if (`${contextType.value}:${contextId.value}` === savedContextKey && (actionWasOpen ? activeStockAction.value?.levelId === levelId : !activeStockAction.value)) {
            stockActionErrors.value = parseApiErrors(cause);
        }
    }
}

function closeStockAction(force = false): void {
    if (stockMovementMutation.isPending.value && !force) return;
    activeStockAction.value = null;
    stockActionErrors.value = { fields: {}, form: '' };
}
</script>

<template>
    <section aria-labelledby="context-title">
        <RouterLink :to="{ name: 'inventory' }" class="text-sm font-medium text-sage-dark hover:underline">← Inventory</RouterLink>
        <InventoryTabs />
        <div v-if="contextQuery.isPending.value" class="mt-5 grid min-h-40 place-items-center text-sm text-ink-muted" role="status">Loading {{ title.toLowerCase() }}…</div>
        <div v-else-if="contextQuery.isError.value" class="mt-5 rounded-panel border border-line bg-white p-6" role="alert"><h1 id="context-title" class="text-xl font-semibold text-ink">We couldn’t load this {{ title.toLowerCase() }}</h1><p class="mt-2 text-sm text-ink-muted">{{ parseError(contextQuery.error.value, 'Try again in a moment.') }}</p><button type="button" class="mt-3 text-sm text-sage-dark underline" @click="contextQuery.refetch()">Try again</button></div>
        <template v-else-if="contextQuery.data.value">
            <p class="eyebrow mt-5">{{ title }}</p>
            <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0 max-w-full">
                    <h1 id="context-title" class="page-title break-words">{{ contextQuery.data.value.name }}</h1>
                    <p v-if="contextType === 'locations' && locationPath" class="mt-2 text-sm text-ink-muted">{{ locationPath }}</p>
                    <p v-if="contextType === 'locations' && location?.description" class="mt-2 whitespace-pre-line text-sm leading-6 text-ink-muted">{{ location.description }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="contextType === 'categories'" type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft disabled:opacity-50" @click="beginCategoryEdit">Edit category</button>
                    <button v-else type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft disabled:opacity-50" @click="beginLocationEdit">Edit location</button>
                    <RouterLink v-if="contextType === 'locations'" :to="{ name: 'catalog-locations', query: { parent: contextId, create: '1' } }" class="inline-flex min-h-10 items-center rounded-md bg-sage px-3 text-sm font-semibold text-white hover:bg-sage-dark">Add child location</RouterLink>
                </div>
            </div>
            <div class="mt-4"><ArchiveResourceButton :type="contextType" :id="contextId" :label="title" /></div>

            <p v-if="feedback" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm text-sage-dark" role="status">{{ feedback }}</p>
            <div v-if="!activeStockAction && (stockActionErrors.form || Object.keys(stockActionErrors.fields).length)" class="mt-4 text-sm text-rose-700" role="alert"><p v-if="stockActionErrors.form">{{ stockActionErrors.form }}</p><p v-for="(message, field) in stockActionErrors.fields" :key="field">{{ message }}</p></div>

            <section v-if="showCategoryEdit" class="mt-5 rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="edit-category-title">
                <h2 id="edit-category-title" class="text-lg font-semibold text-ink">Edit category</h2>
                <p v-if="formErrors.form" class="mt-3 text-sm text-rose-700" role="alert">{{ formErrors.form }}</p>
                <form class="mt-4 grid max-w-xl gap-4" @submit.prevent="saveCategory">
                    <div class="grid gap-2"><label for="edit-category-name" class="text-sm font-medium text-ink">Category name</label><input id="edit-category-name" v-model="categoryName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3 text-ink"><p v-if="formErrors.fields.name" class="text-sm text-rose-700">{{ formErrors.fields.name }}</p></div>
                    <div class="flex gap-2"><button type="submit" :disabled="!canSaveCategory" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ updateCategoryMutation.isPending.value ? 'Saving…' : 'Save changes' }}</button><button type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-4 text-sm" @click="cancelEdit">Cancel</button></div>
                </form>
            </section>

            <section v-if="showLocationEdit && location" class="mt-5 rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="edit-location-title">
                <h2 id="edit-location-title" class="text-lg font-semibold text-ink">Edit location</h2>
                <p v-if="formErrors.form" class="mt-3 text-sm text-rose-700" role="alert">{{ formErrors.form }}</p>
                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="saveLocation">
                    <div class="grid gap-2"><label for="edit-location-name" class="text-sm font-medium text-ink">Location name</label><input id="edit-location-name" v-model="locationName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3 text-ink"><p v-if="formErrors.fields.name" class="text-sm text-rose-700">{{ formErrors.fields.name }}</p></div>
                    <div class="grid gap-2"><label for="edit-location-parent" class="text-sm font-medium text-ink">Parent location</label><p v-if="locationOptions.isPending.value" class="text-sm text-ink-muted" role="status">Loading locations…</p><p v-else-if="locationOptions.isError.value" class="text-sm text-rose-700" role="alert">Parent choices could not be loaded. <button type="button" class="font-semibold underline" @click="locationOptions.refetch()">Try again</button></p><select id="edit-location-parent" v-model="locationParentId" :disabled="!locationOptions.isSuccess.value" class="min-h-11 rounded-md border border-line bg-white px-3 text-ink disabled:opacity-50"><option value="">No parent</option><option v-for="candidate in eligibleLocationParents" :key="candidate.id" :value="candidate.id">{{ locationForPath(candidate) }}</option></select><p v-if="formErrors.fields.parent_id" class="text-sm text-rose-700">{{ formErrors.fields.parent_id }}</p></div>
                    <div class="grid gap-2 sm:col-span-2"><label for="edit-location-description" class="text-sm font-medium text-ink">Description <span class="font-normal text-ink-muted">(optional)</span></label><textarea id="edit-location-description" v-model="locationDescription" rows="3" class="min-h-24 resize-y rounded-md border border-line px-3 py-2 text-ink" /></div>
                    <div class="flex gap-2 sm:col-span-2"><button type="submit" :disabled="!canSaveLocation" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ updateLocationMutation.isPending.value ? 'Saving…' : 'Save changes' }}</button><button type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-4 text-sm" @click="cancelEdit">Cancel</button></div>
                </form>
            </section>

            <section v-if="contextType === 'categories'" class="mt-6 overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="category-items-heading" :aria-busy="categoryItemsQuery.isFetching.value">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                    <div><h2 id="category-items-heading" class="text-base font-semibold text-ink">Items in this category</h2><p v-if="categoryItemsQuery.data.value" class="mt-1 text-sm text-ink-muted">{{ categoryItemsQuery.data.value.meta.total }} items</p></div>
                    <div class="flex flex-wrap items-center gap-3">
                        <label for="category-items-page-size" class="sr-only">Items per page</label><select id="category-items-page-size" :value="categoryPageSize" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm" @change="changePageSize('category', ($event.target as HTMLSelectElement).value)"><option v-for="size in pageSizes" :key="size" :value="size">{{ size }} per page</option></select>
                        <label for="category-items-search" class="sr-only">Search category items</label><input id="category-items-search" v-model="categorySearch" type="search" placeholder="Search items" class="min-h-10 rounded-md border border-line bg-canvas px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20">
                    </div>
                </div>
                <div v-if="categoryItemsQuery.isPending.value" class="grid min-h-40 place-items-center p-6 text-sm text-ink-muted" role="status">Loading category items…</div>
                <div v-else-if="categoryItemsQuery.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">{{ parseError(categoryItemsQuery.error.value, 'Category items could not be loaded.') }}</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="categoryItemsQuery.refetch()">Try again</button></div>
                <div v-else-if="categoryItemsQuery.data.value?.data.length === 0" class="grid min-h-40 place-items-center p-6 text-center"><div><h3 class="font-semibold text-ink">{{ debouncedCategorySearch ? 'No items match that search' : 'No items in this category yet' }}</h3><p class="mt-1 text-sm text-ink-muted">{{ debouncedCategorySearch ? 'Try another name or clear your search.' : 'Items assigned to this category will appear here.' }}</p></div></div>
                <ul v-else class="divide-y divide-line" aria-label="Items in this category">
                    <li v-for="item in categoryItemsQuery.data.value?.data ?? []" :key="item.id" class="grid gap-3 px-5 py-4 sm:items-start sm:px-6" :class="categoryHasImages ? 'sm:grid-cols-[3.5rem_minmax(0,1fr)_minmax(0,1fr)]' : 'sm:grid-cols-2'">
                        <img v-if="item.images?.find((image) => image.is_primary)" :src="item.images.find((image) => image.is_primary)?.thumbnail_url" :alt="item.images.find((image) => image.is_primary)?.caption || ''" class="size-14 rounded-md bg-surface-soft object-cover" loading="lazy">
                        <span v-else-if="categoryHasImages" class="hidden size-14 sm:block" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <RouterLink :to="{ name: 'inventory-item', params: { item: item.id } }" class="break-words font-semibold text-ink hover:text-sage-dark hover:underline">{{ item.name }}</RouterLink>
                            <p v-if="item.description" class="mt-1 line-clamp-2 whitespace-pre-line break-words text-sm text-ink-muted">{{ item.description }}</p>
                            <p class="mt-1 text-sm text-ink-muted">Counted by {{ item.counting_unit }}</p>
                            <div v-if="hasActiveBuySoonAlert(item.active_alerts)" class="mt-2"><StockStatusBadge label="Buy soon" tone="reminder" /></div>
                        </div>
                        <ItemStockSummary :item="item" :locations="locationOptions.data.value ?? []" />
                    </li>
                </ul>
                <div v-if="categoryItemsQuery.data.value && categoryItemsQuery.data.value.meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6"><p class="text-sm text-ink-muted">Page {{ categoryItemsQuery.data.value.meta.current_page }} of {{ categoryItemsQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="categoryPage <= 1 || categoryItemsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="changePage('category', categoryPage - 1)">Previous</button><button type="button" :disabled="categoryPage >= categoryItemsQuery.data.value.meta.last_page || categoryItemsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="changePage('category', categoryPage + 1)">Next</button></div></div>
            </section>

            <template v-else>
                <section class="mt-6 overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="location-children-heading">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6"><h2 id="location-children-heading" class="text-base font-semibold text-ink">Child locations</h2><span class="text-sm text-ink-muted">{{ directChildren.length }} {{ directChildren.length === 1 ? 'location' : 'locations' }}</span></div>
                    <div v-if="locationOptions.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading child locations…</div>
                    <div v-else-if="locationOptions.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">Locations could not be loaded.</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="locationOptions.refetch()">Try again</button></div>
                    <div v-else-if="directChildren.length === 0" class="grid min-h-32 place-items-center p-5 text-center"><p class="text-sm text-ink-muted">No child locations yet. Add one to map the next level of storage.</p></div>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="child in directChildren" :key="child.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6"><RouterLink :to="{ name: 'location-detail', params: { location: child.id } }" class="font-semibold text-ink hover:text-sage-dark hover:underline">{{ child.name }}</RouterLink><span class="text-sm text-ink-muted">{{ locationForPath(child) }}</span></li>
                    </ul>
                </section>

                <section class="mt-6 overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="location-stock-heading" :aria-busy="locationLevelsQuery.isFetching.value">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                        <div><h2 id="location-stock-heading" class="text-base font-semibold text-ink">Stock by location</h2><p v-if="locationLevelsQuery.data.value" class="mt-1 text-sm text-ink-muted">{{ locationLevelsQuery.data.value.meta.total }} stock {{ locationLevelsQuery.data.value.meta.total === 1 ? 'record' : 'records' }}</p></div>
                        <div class="flex flex-wrap items-center gap-3">
                            <template v-if="directChildren.length"><label for="inventory-scope" class="text-sm font-medium text-ink">Inventory scope</label><select id="inventory-scope" :value="inventoryScope" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm" @change="setInventoryScope(($event.target as HTMLSelectElement).value)"><option value="all">All within this location</option><option value="direct">Directly here</option></select></template>
                            <label for="inventory-page-size" class="sr-only">Stock records per page</label><select id="inventory-page-size" :value="inventoryPageSize" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm" @change="changePageSize('inventory', ($event.target as HTMLSelectElement).value)"><option v-for="size in pageSizes" :key="size" :value="size">{{ size }} per page</option></select>
                        </div>
                    </div>
                    <div v-if="locationOptions.isPending.value" class="grid min-h-40 place-items-center p-6 text-sm text-ink-muted" role="status">Loading location hierarchy before stock…</div>
                    <div v-else-if="locationOptions.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">The location hierarchy could not be loaded, so stock scope is unavailable.</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="locationOptions.refetch()">Try again</button></div>
                    <div v-else-if="locationLevelsQuery.isPending.value" class="grid min-h-40 place-items-center p-6 text-sm text-ink-muted" role="status">Loading location stock…</div>
                    <div v-else-if="locationLevelsQuery.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">{{ parseError(locationLevelsQuery.error.value, 'Location stock could not be loaded.') }}</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="locationLevelsQuery.refetch()">Try again</button></div>
                    <div v-else-if="locationLevelsQuery.data.value?.data.length === 0" class="grid min-h-32 place-items-center p-5 text-center"><p class="text-sm text-ink-muted">No stock records in this scope yet.</p></div>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="level in locationLevelsQuery.data.value?.data ?? []" :key="level.id" class="grid gap-3 px-5 py-4 sm:grid-cols-2 sm:items-start sm:px-6">
                            <div class="min-w-0">
                                <RouterLink :to="{ name: 'inventory-item', params: { item: level.item.id } }" class="break-words font-semibold text-ink hover:text-sage-dark hover:underline">{{ level.item.name }}</RouterLink>
                                <p class="mt-1 break-words text-sm text-ink-muted">{{ locationForPath(level.location) }} · {{ level.quantity }} {{ level.quantity === 1 ? level.item.counting_unit : (level.item.counting_unit_plural || level.item.counting_unit) }}</p>
                                <p v-if="level.alert_threshold !== null" class="mt-1 text-xs text-ink-muted">Alert at {{ level.alert_threshold }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5" aria-label="Stock level status">
                                    <StockStatusBadge v-for="indicator in getStockLevelIndicators(level)" :key="indicator.label" :label="indicator.label" :tone="indicator.tone" />
                                    <StockStatusBadge v-if="hasActiveBuySoonAlert(level.item.active_alerts)" label="Buy soon" tone="reminder" />
                                </div>
                            </div>
                            <LocationStockActions :key="`${contextType}:${contextId}:${level.id}`" :level="level" :locations="locationOptions.data.value ?? []" :active-action="activeStockAction" :busy="stockMovementMutation.isPending.value" :errors="activeStockAction?.levelId === level.id ? stockActionErrors : { fields: {}, form: '' }" :locations-loading="locationOptions.isPending.value" :locations-error="locationOptions.isError.value" @activate="beginStockAction" @submit="saveStockAction" @close="closeStockAction" @retry-locations="locationOptions.refetch()" />
                        </li>
                    </ul>
                    <div v-if="locationLevelsQuery.data.value && locationLevelsQuery.data.value.meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6"><p class="text-sm text-ink-muted">Page {{ locationLevelsQuery.data.value.meta.current_page }} of {{ locationLevelsQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="inventoryPage <= 1 || locationLevelsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="changePage('inventory', inventoryPage - 1)">Previous</button><button type="button" :disabled="inventoryPage >= locationLevelsQuery.data.value.meta.last_page || locationLevelsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="changePage('inventory', inventoryPage + 1)">Next</button></div></div>
                </section>

                <section class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="restock-here-heading">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="restock-here-heading" class="font-semibold text-ink">Restock here</h2><p class="mt-1 text-sm text-ink-muted">Choose any household item, including one not yet stored here.</p></div></div>
                    <div v-if="allItemsQuery.isError.value" class="mt-3 text-sm text-rose-700" role="alert">Items could not be loaded. <button type="button" class="underline" @click="allItemsQuery.refetch()">Try again</button></div>
                    <div v-else class="mt-4 flex flex-wrap items-end gap-3">
                        <div class="grid min-w-0 flex-1 gap-2"><label for="item-to-restock" class="text-sm font-medium text-ink">Item to restock</label><select id="item-to-restock" v-model="itemToRestock" :disabled="allItemsQuery.isPending.value" class="min-h-11 min-w-0 w-full rounded-md border border-line bg-white px-3 text-ink"><option value="" disabled>{{ allItemsQuery.isPending.value ? 'Loading items…' : 'Select an item' }}</option><option v-for="item in allItemsQuery.data.value ?? []" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                        <RouterLink v-if="itemToRestock" :to="itemDetailLocation(itemToRestock)" class="inline-flex min-h-11 items-center rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark">Restock here</RouterLink>
                    </div>
                </section>
            </template>

            <NotesPanel :key="`${contextType}:${contextId}`" class="mt-6" :type="contextType" :context-id="contextId" />
        </template>
    </section>
</template>
