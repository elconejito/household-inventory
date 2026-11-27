<script setup lang="ts">
import FormModal from '../components/FormModal.vue';
import ListPagination from '../components/ListPagination.vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { useRoute } from 'vue-router';
import NotesPanel from '../components/NotesPanel.vue';
import ArchiveResourceButton from '../components/ArchiveResourceButton.vue';
import ItemStockSummary from '../components/ItemStockSummary.vue';
import StockActionReceipt from '../components/StockActionReceipt.vue';
import type { ShelfActionResult } from '../api/stock';
import StockStatusBadge from '../components/StockStatusBadge.vue';
import LocationStockActions, { type LocationStockAction } from '../components/LocationStockActions.vue';
import CategoryAssignmentsPanel from '../components/CategoryAssignmentsPanel.vue';
import type { CatalogInventoryLevel } from '../api/catalog';
import { getCategory, getLocation } from '../api/note-contexts';
import { buildLocationPath, getLocationTrail, type Location as StockLocation } from '../api/stock';
import { contextListQuery, readContextListState } from '../lib/item-browse-state';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { getStockLevelIndicators, hasActiveBuySoonAlert } from '../lib/stock-indicators';
import { useCategoryItemsQuery, useDirectLocationLevelsQuery, useSetCategoryItemAssignmentMutation, useUpdateCatalogLocationMutation, useUpdateCategoryMutation } from '../queries/catalog';
import { useLocationsQuery, useSaveShelfActionMutation } from '../queries/stock';

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
const categorySearch = ref('');
const debouncedCategorySearch = ref('');
const categoryPage = ref(1);
const categoryPageSize = ref(10);
const childrenPage = ref(1);
const childrenPageSize = ref(10);
const inventoryPage = ref(1);
const inventoryPageSize = ref(10);
const itemOriginQuery = computed(() => {
    const category = contextType.value === 'categories';
    const origin = category ? 'category' : 'location';
    const listQuery = contextListQuery(origin, {
        page: category ? categoryPage.value : inventoryPage.value,
        perPage: category ? categoryPageSize.value : inventoryPageSize.value,
        search: debouncedCategorySearch.value,
        childrenPage: childrenPage.value,
        childrenPerPage: childrenPageSize.value,
    });
    return { origin, originId: contextId.value, ...listQuery };
});
function itemDestination(item: string) {
    return { name: 'inventory-item', params: { item }, query: itemOriginQuery.value };
}

const showCategoryEdit = ref(false);
const showLocationEdit = ref(false);
const categoryName = ref('');
const locationName = ref('');
const locationDescription = ref('');
const locationParentId = ref('');
const formErrors = ref<FormErrors>({ fields: {}, form: '' });
const feedback = ref('');
const categoryAssignmentError = ref('');
const categoryAssignmentPendingItemId = ref('');
const categoryEditSnapshot = ref('');
const updateCategoryMutation = useUpdateCategoryMutation();
const setCategoryItemAssignmentMutation = useSetCategoryItemAssignmentMutation();
const updateLocationMutation = useUpdateCatalogLocationMutation();
const shelfReceipt = ref<{ level: CatalogInventoryLevel; result: ShelfActionResult } | null>(null);
const stockMovementMutation = useSaveShelfActionMutation();
const activeStockAction = ref<{ levelId: string; action: LocationStockAction } | null>(null);
const stockActionErrors = ref<FormErrors>({ fields: {}, form: '' });

const categoryParams = computed(() => ({ search: debouncedCategorySearch.value, page: categoryPage.value, perPage: categoryPageSize.value }));
const categoryItemsQuery = useCategoryItemsQuery(categoryId, categoryParams);
const categoryHasImages = computed(() => categoryItemsQuery.data.value?.data.some((item) => item.images?.some((image) => image.is_primary)) ?? false);
const location = computed(() => contextQuery.data.value as StockLocation | undefined);
const browseDestination = computed(() => ({ name: contextType.value === 'categories' ? 'catalog-categories' : 'catalog-locations' }));
const browseLabel = computed(() => contextType.value === 'categories' ? 'Categories' : 'All locations');
const locationInventoryDestination = computed(() => ({ name: 'inventory', query: { location: contextId.value } }));
const locationAncestors = computed(() => contextType.value === 'locations' && location.value
    ? getLocationTrail(location.value, locationOptions.data.value ?? []).slice(0, -1)
    : []);
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
const directLocationId = computed(() => contextType.value === 'locations' ? contextId.value : '');
const locationLevelsQuery = useDirectLocationLevelsQuery(directLocationId);
const directChildren = computed(() => (locationOptions.data.value ?? [])
    .filter((candidate) => candidate.parent?.id === contextId.value)
    .sort((first, second) => first.name.localeCompare(second.name)));
const childrenMeta = computed(() => ({ current_page: childrenPage.value, last_page: Math.max(1, Math.ceil(directChildren.value.length / childrenPageSize.value)), total: directChildren.value.length }));
const visibleChildren = computed(() => directChildren.value.slice((childrenPage.value - 1) * childrenPageSize.value, childrenPage.value * childrenPageSize.value));
const directLevels = computed(() => (locationLevelsQuery.data.value ?? []).filter((level) => level.location.id === contextId.value)
    .sort((first, second) => first.item.name.localeCompare(second.item.name, undefined, { numeric: true, sensitivity: 'base' })));
const inventoryMeta = computed(() => ({ current_page: inventoryPage.value, last_page: Math.max(1, Math.ceil(directLevels.value.length / inventoryPageSize.value)), total: directLevels.value.length }));
const visibleLevels = computed(() => directLevels.value.slice((inventoryPage.value - 1) * inventoryPageSize.value, inventoryPage.value * inventoryPageSize.value));
watch([() => childrenMeta.value.last_page, locationOptions.isSuccess], ([lastPage, loaded]) => {
    if (loaded) childrenPage.value = Math.min(childrenPage.value, lastPage);
});
watch([() => inventoryMeta.value.last_page, locationLevelsQuery.isSuccess], ([lastPage, loaded]) => {
    if (loaded && inventoryPage.value > lastPage) {
        inventoryPage.value = lastPage;
        closeStockAction(true);
    }
});
const eligibleLocationParents = computed(() => {
    const excluded = new Set(descendantLocationIds.value);

    return (locationOptions.data.value ?? []).filter((candidate) => !excluded.has(candidate.id));
});
const canSaveCategory = computed(() => categoryName.value.trim().length > 0 && !updateCategoryMutation.isPending.value);
const canSaveLocation = computed(() => locationName.value.trim().length > 0 && !updateLocationMutation.isPending.value && locationOptions.isSuccess.value);
const editPending = computed(() => updateCategoryMutation.isPending.value || updateLocationMutation.isPending.value);
const categoryIsCurrent = computed(() => contextType.value === 'categories' && contextQuery.data.value?.id === contextId.value);

let searchTimeout: ReturnType<typeof setTimeout> | undefined;
let contextGeneration = 0;
let locationEditSnapshot: { name: string; description: string | null; parentId: string | null } | null = null;
const locationEditMode = ref<'edit' | 'move'>('edit');
watch(categorySearch, (value) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    if (value.trim() === debouncedCategorySearch.value) return;
    searchTimeout = setTimeout(() => {
        categoryPage.value = 1;
        debouncedCategorySearch.value = value.trim();
    }, 300);
});
watch(() => categoryItemsQuery.data.value?.meta.last_page, (lastPage) => {
    if (categoryIsCurrent.value && lastPage && categoryPage.value > lastPage) categoryPage.value = lastPage;
});
onUnmounted(() => {
    contextGeneration++;
    if (searchTimeout) clearTimeout(searchTimeout);
});

watch([contextType, contextId, () => route.query], () => {
    contextGeneration++;
    shelfReceipt.value = null;
    if (searchTimeout) clearTimeout(searchTimeout);
    const state = readContextListState(route.query ?? {});
    categorySearch.value = state.search;
    debouncedCategorySearch.value = state.search;
    categoryPage.value = state.page;
    categoryPageSize.value = state.perPage;
    childrenPage.value = state.childrenPage;
    childrenPageSize.value = state.childrenPerPage;
    inventoryPage.value = state.page;
    inventoryPageSize.value = state.perPage;
    showCategoryEdit.value = false;
    showLocationEdit.value = false;
    categoryName.value = '';
    locationName.value = '';
    locationDescription.value = '';
    locationParentId.value = '';
    formErrors.value = { fields: {}, form: '' };
    feedback.value = '';
    categoryAssignmentError.value = '';
    categoryAssignmentPendingItemId.value = '';
    activeStockAction.value = null;
    stockActionErrors.value = { fields: {}, form: '' };
    categoryEditSnapshot.value = '';
    locationEditSnapshot = null;
    locationEditMode.value = 'edit';
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
    locationEditMode.value = 'edit';
}

function beginLocationMove(): void {
    beginLocationEdit();
    locationEditMode.value = 'move';
}

function cancelEdit(): void {
    if (editPending.value) return;
    showCategoryEdit.value = false;
    showLocationEdit.value = false;
    locationEditSnapshot = null;
    locationEditMode.value = 'edit';
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
        feedback.value = locationEditMode.value === 'move' ? `${nextName} was moved.` : `${nextName} was updated.`;
        locationEditMode.value = 'edit';
    } catch (cause) {
        if (contextType.value === savedContextType && contextId.value === savedContextId) formErrors.value = parseApiErrors(cause);
    }
}

function changePage(kind: 'category' | 'children' | 'inventory', nextPage: number): void {
    const lastPage = kind === 'category' ? categoryItemsQuery.data.value?.meta.last_page ?? 1
        : kind === 'children' ? childrenMeta.value.last_page
            : inventoryMeta.value.last_page;
    const page = kind === 'category' ? categoryPage : kind === 'children' ? childrenPage : inventoryPage;
    if (nextPage >= 1 && Number.isInteger(nextPage) && nextPage <= lastPage) {
        page.value = nextPage;
        if (kind === 'inventory') closeStockAction(true);
    }
}

function locationForPath(levelLocation: StockLocation): string {
    return buildLocationPath(levelLocation, locationOptions.data.value ?? []);
}

function parseError(queryError: unknown, fallback: string): string {
    return parseApiErrors(queryError).form || fallback;
}

async function setItemCategoryAssignment(itemId: string, assigned: boolean, itemName: string): Promise<void> {
    if (!categoryIsCurrent.value || !categoryId.value || setCategoryItemAssignmentMutation.isPending.value) return;
    const savedCategoryId = categoryId.value;
    const savedContextGeneration = contextGeneration;
    categoryAssignmentPendingItemId.value = itemId;
    categoryAssignmentError.value = '';
    feedback.value = '';

    try {
        await setCategoryItemAssignmentMutation.mutateAsync({ categoryId: savedCategoryId, itemId, assigned });
        if (contextGeneration !== savedContextGeneration || !categoryIsCurrent.value || categoryId.value !== savedCategoryId) return;
        feedback.value = assigned ? `${itemName} was added to this category.` : `${itemName} was removed from this category.`;
    } catch (cause) {
        if (contextGeneration !== savedContextGeneration || !categoryIsCurrent.value || categoryId.value !== savedCategoryId) return;
        const errors = parseApiErrors(cause);
        categoryAssignmentError.value = errors.form || Object.values(errors.fields).join(' ') || 'The item assignment could not be saved. Try again.';
    } finally {
        if (contextGeneration === savedContextGeneration) categoryAssignmentPendingItemId.value = '';
    }
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
        const level = locationLevelsQuery.data.value?.find((entry) => entry.id === levelId);
        const result = await stockMovementMutation.mutateAsync(data);
        if (`${contextType.value}:${contextId.value}` !== savedContextKey || (actionWasOpen && activeStockAction.value?.levelId !== levelId) || (!actionWasOpen && activeStockAction.value)) return;
        if (actionWasOpen) activeStockAction.value = null;
        if (result && level) shelfReceipt.value = { level, result };
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
        <RouterLink v-if="!contextQuery.data.value || contextQuery.isError.value" :to="browseDestination" class="text-sm font-medium text-sage-dark hover:underline">← {{ browseLabel }}</RouterLink>
        <div v-if="contextQuery.isPending.value" class="mt-5 grid min-h-40 place-items-center text-sm text-ink-muted" role="status">Loading {{ title.toLowerCase() }}…</div>
        <div v-else-if="contextQuery.isError.value" class="mt-5 rounded-panel border border-line bg-white p-6" role="alert"><h1 id="context-title" class="text-xl font-semibold text-ink">We couldn’t load this {{ title.toLowerCase() }}</h1><p class="mt-2 text-sm text-ink-muted">{{ parseError(contextQuery.error.value, 'Try again in a moment.') }}</p><button type="button" class="mt-3 text-sm text-sage-dark underline" @click="contextQuery.refetch()">Try again</button></div>
        <template v-else-if="contextQuery.data.value">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0 max-w-full">
                    <nav aria-label="Breadcrumb" class="mb-2 max-w-full overflow-x-auto pb-1">
                        <ol class="flex w-max items-center gap-2 text-sm font-medium">
                            <li class="shrink-0"><RouterLink :to="browseDestination" class="text-sage-dark hover:underline">← {{ browseLabel }}</RouterLink></li>
                            <li v-for="ancestor in locationAncestors" :key="ancestor.id" class="flex shrink-0 items-center gap-2">
                                <span aria-hidden="true" class="text-ink-muted">/</span>
                                <RouterLink :to="{ name: 'location-detail', params: { location: ancestor.id } }" :title="ancestor.name" class="block max-w-40 truncate text-sage-dark hover:underline sm:max-w-56">{{ ancestor.name }}</RouterLink>
                            </li>
                        </ol>
                    </nav>
                    <h1 id="context-title" class="page-title break-words">{{ contextQuery.data.value.name }}</h1>
                    <p v-if="contextType === 'locations' && location?.description" class="mt-2 whitespace-pre-line text-sm leading-6 text-ink-muted">{{ location.description }}</p>
                </div>
                <div class="flex flex-wrap items-start gap-2" aria-label="Record actions">
                    <button v-if="contextType === 'categories'" type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft disabled:opacity-50" @click="beginCategoryEdit">Edit category</button>
                    <button v-else type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft disabled:opacity-50" @click="beginLocationEdit">Edit location</button>
                    <button v-if="contextType === 'locations'" type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft disabled:opacity-50" @click="beginLocationMove">Move location</button>
                    <RouterLink v-if="contextType === 'locations'" :to="{ name: 'location-intake', params: { location: contextId } }" class="inline-flex min-h-10 items-center rounded-md bg-sage px-3 text-sm font-semibold text-white hover:bg-sage-dark">Add items here</RouterLink>
                    <ArchiveResourceButton :type="contextType" :id="contextId" :label="title" />
                </div>
            </div>

            <p v-if="feedback" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm text-sage-dark" role="status">{{ feedback }}</p>
            <div v-if="!activeStockAction && (stockActionErrors.form || Object.keys(stockActionErrors.fields).length)" class="mt-4 text-sm text-rose-700" role="alert"><p v-if="stockActionErrors.form">{{ stockActionErrors.form }}</p><p v-for="(message, field) in stockActionErrors.fields" :key="field">{{ message }}</p></div>

            <CategoryAssignmentsPanel
                v-if="categoryIsCurrent"
                class="mt-5"
                :category-id="categoryId"
                :pending="setCategoryItemAssignmentMutation.isPending.value"
                :error="categoryAssignmentError"
                @assign="setItemCategoryAssignment"
            />

            <FormModal v-if="showCategoryEdit" label="Edit category" :busy="editPending" @close="cancelEdit">
                <section class="rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="edit-category-title">
                    <h2 id="edit-category-title" class="text-lg font-semibold text-ink">Edit category</h2>
                    <p v-if="formErrors.form" class="mt-3 text-sm text-rose-700" role="alert">{{ formErrors.form }}</p>
                    <form class="mt-4 grid max-w-xl gap-4" @submit.prevent="saveCategory">
                        <div class="grid gap-2"><label for="edit-category-name" class="text-sm font-medium text-ink">Category name</label><input id="edit-category-name" v-model="categoryName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3 text-ink"><p v-if="formErrors.fields.name" class="text-sm text-rose-700">{{ formErrors.fields.name }}</p></div>
                        <div class="flex gap-2"><button type="submit" :disabled="!canSaveCategory" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ updateCategoryMutation.isPending.value ? 'Saving…' : 'Save changes' }}</button><button type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-4 text-sm" @click="cancelEdit">Cancel</button></div>
                    </form>
                </section>
            </FormModal>

            <FormModal v-if="showLocationEdit && location" :label="locationEditMode === 'move' ? 'Move location' : 'Edit location'" :busy="editPending" @close="cancelEdit">
                <section class="rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="edit-location-title">
                    <h2 id="edit-location-title" class="text-lg font-semibold text-ink">{{ locationEditMode === 'move' ? 'Move location' : 'Edit location' }}</h2>
                    <p v-if="locationEditMode === 'move'" class="mt-2 text-sm text-ink-muted">Choose a new parent. Sublocations and the items stored directly here move with this location.</p>
                    <p v-if="formErrors.form" class="mt-3 text-sm text-rose-700" role="alert">{{ formErrors.form }}</p>
                    <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="saveLocation">
                        <div v-if="locationEditMode === 'edit'" class="grid gap-2"><label for="edit-location-name" class="text-sm font-medium text-ink">Location name</label><input id="edit-location-name" v-model="locationName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3 text-ink"><p v-if="formErrors.fields.name" class="text-sm text-rose-700">{{ formErrors.fields.name }}</p></div>
                        <div class="grid gap-2"><label for="edit-location-parent" class="text-sm font-medium text-ink">Parent location</label><p v-if="locationOptions.isPending.value" class="text-sm text-ink-muted" role="status">Loading locations…</p><p v-else-if="locationOptions.isError.value" class="text-sm text-rose-700" role="alert">Parent choices could not be loaded. <button type="button" class="font-semibold underline" @click="locationOptions.refetch()">Try again</button></p><select id="edit-location-parent" v-model="locationParentId" :disabled="!locationOptions.isSuccess.value" class="min-h-11 rounded-md border border-line bg-white px-3 text-ink disabled:opacity-50"><option value="">No parent</option><option v-for="candidate in eligibleLocationParents" :key="candidate.id" :value="candidate.id">{{ locationForPath(candidate) }}</option></select><p v-if="formErrors.fields.parent_id" class="text-sm text-rose-700">{{ formErrors.fields.parent_id }}</p></div>
                        <div v-if="locationEditMode === 'edit'" class="grid gap-2 sm:col-span-2"><label for="edit-location-description" class="text-sm font-medium text-ink">Description <span class="font-normal text-ink-muted">(optional)</span></label><textarea id="edit-location-description" v-model="locationDescription" rows="3" class="min-h-24 resize-y rounded-md border border-line px-3 py-2 text-ink" /></div>
                        <div class="flex gap-2 sm:col-span-2"><button type="submit" :disabled="!canSaveLocation" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ updateLocationMutation.isPending.value ? 'Saving…' : (locationEditMode === 'move' ? 'Move location' : 'Save changes') }}</button><button type="button" :disabled="editPending" class="min-h-10 rounded-md border border-line px-3 text-sm" @click="cancelEdit">Cancel</button></div>
                    </form>
                </section>
            </FormModal>

            <section v-if="contextType === 'categories'" class="mt-6 overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="category-items-heading" :aria-busy="categoryItemsQuery.isFetching.value">
                <h2 id="category-items-heading" class="sr-only">Items in this category</h2>
                <div class="flex items-center gap-3 border-b border-line px-5 py-4 sm:px-6">
                    <div class="min-w-0 flex-1"><label for="category-items-search" class="sr-only">Search category items</label><input id="category-items-search" v-model="categorySearch" type="search" placeholder="Search items" class="min-h-11 w-full rounded-md border border-line bg-canvas px-3 text-sm outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"></div>
                </div>
                <div v-if="categoryItemsQuery.isPending.value" class="grid min-h-40 place-items-center p-6 text-sm text-ink-muted" role="status">Loading category items…</div>
                <div v-else-if="categoryItemsQuery.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">{{ parseError(categoryItemsQuery.error.value, 'Category items could not be loaded.') }}</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="categoryItemsQuery.refetch()">Try again</button></div>
                <div v-else-if="categoryItemsQuery.data.value?.data.length === 0" class="grid min-h-40 place-items-center p-6 text-center"><div><h3 class="font-semibold text-ink">{{ debouncedCategorySearch ? 'No items match that search' : 'No items in this category yet' }}</h3><p class="mt-1 text-sm text-ink-muted">{{ debouncedCategorySearch ? 'Try another name or clear your search.' : 'Items assigned to this category will appear here.' }}</p></div></div>
                <ul v-else class="divide-y divide-line" aria-label="Items in this category">
                    <li v-for="item in categoryItemsQuery.data.value?.data ?? []" :key="item.id" class="grid gap-3 px-5 py-4 sm:items-start sm:px-6" :class="categoryHasImages ? 'sm:grid-cols-[3.5rem_minmax(0,1fr)_minmax(0,1fr)]' : 'sm:grid-cols-2'">
                        <img v-if="item.images?.find((image) => image.is_primary)" :src="item.images.find((image) => image.is_primary)?.thumbnail_url" :alt="item.images.find((image) => image.is_primary)?.caption || ''" class="size-14 rounded-md bg-surface-soft object-cover" loading="lazy">
                        <span v-else-if="categoryHasImages" class="hidden size-14 sm:block" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <RouterLink :to="itemDestination(item.id)" class="break-words font-semibold text-ink hover:text-sage-dark hover:underline">{{ item.name }}</RouterLink>
                            <p v-if="item.description" class="mt-1 line-clamp-2 whitespace-pre-line break-words text-sm text-ink-muted">{{ item.description }}</p>
                            <p class="mt-1 text-sm text-ink-muted">Counted by {{ item.counting_unit }}</p>
                            <div v-if="hasActiveBuySoonAlert(item.active_alerts)" class="mt-2"><StockStatusBadge label="Buy soon" tone="reminder" /></div>
                            <button v-if="categoryIsCurrent" type="button" :disabled="setCategoryItemAssignmentMutation.isPending.value" class="mt-3 inline-flex min-h-9 items-center rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft disabled:cursor-wait disabled:opacity-50" @click="setItemCategoryAssignment(item.id, false, item.name)">{{ categoryAssignmentPendingItemId === item.id ? 'Removing…' : 'Remove from category' }}</button>
                        </div>
                        <ItemStockSummary :item="item" :locations="locationOptions.data.value ?? []" />
                    </li>
                </ul>
                <ListPagination v-if="categoryItemsQuery.data.value" v-model:per-page="categoryPageSize" :meta="categoryItemsQuery.data.value.meta" :page="categoryPage" :busy="categoryItemsQuery.isFetching.value" singular="item" plural="items" @change="changePage('category', $event)" />
            </section>

            <div v-else class="mt-6 grid gap-6">
                <section class="overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="sublocations-heading" :aria-busy="locationOptions.isFetching.value">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                        <h2 id="sublocations-heading" class="text-base font-semibold text-ink">Sublocations</h2>
                        <RouterLink :to="{ name: 'catalog-locations', query: { parent: contextId, create: '1' } }" class="inline-flex min-h-10 items-center rounded-md border border-line px-3 text-sm font-semibold text-sage-dark hover:bg-sage-soft">Add sublocation</RouterLink>
                    </div>
                    <div v-if="locationOptions.isPending.value" class="grid min-h-32 place-items-center p-5 text-sm text-ink-muted" role="status">Loading sublocations…</div>
                    <div v-else-if="locationOptions.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">Sublocations could not be loaded.</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="locationOptions.refetch()">Try again</button></div>
                    <div v-else-if="!directChildren.length" class="grid min-h-32 place-items-center p-5 text-center"><p class="text-sm text-ink-muted">No sublocations inside this location yet.</p></div>
                    <ul v-else class="divide-y divide-line" aria-label="Sublocations">
                        <li v-for="child in visibleChildren" :key="child.id" class="flex items-center gap-3 px-5 py-4 sm:px-6">
                            <svg class="size-5 shrink-0 text-sage" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10H3Z" /></svg>
                            <div class="min-w-0 flex-1"><RouterLink :to="{ name: 'location-detail', params: { location: child.id } }" class="break-words font-semibold text-ink hover:text-sage-dark hover:underline">{{ child.name }}</RouterLink><p class="mt-1 text-xs text-ink-muted">{{ locationForPath(child) }}</p></div>
                            <span class="text-sm text-ink-muted">Browse location →</span>
                        </li>
                    </ul>
                    <ListPagination v-if="locationOptions.isSuccess.value" v-model:per-page="childrenPageSize" :meta="childrenMeta" :page="childrenPage" :busy="locationOptions.isFetching.value" singular="sublocation" plural="sublocations" label="Sublocation pagination" @change="changePage('children', $event)" />
                </section>

                <section class="overflow-hidden rounded-panel border border-line bg-white shadow-card" aria-labelledby="direct-items-heading" :aria-busy="locationLevelsQuery.isFetching.value">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                        <h2 id="direct-items-heading" class="text-base font-semibold text-ink">Items stored directly here</h2>
                        <div class="flex flex-wrap gap-2"><RouterLink :to="{ name: 'location-count', params: { location: contextId } }" class="inline-flex min-h-10 items-center rounded-md border border-line px-3 text-sm font-semibold text-ink hover:bg-surface-soft">Check shelf quantities</RouterLink><RouterLink :to="locationInventoryDestination" class="inline-flex min-h-10 items-center rounded-md border border-line px-3 text-sm font-semibold text-sage-dark hover:bg-sage-soft">View location inventory</RouterLink></div>
                    </div>
                    <div v-if="locationLevelsQuery.isPending.value" class="grid min-h-32 place-items-center p-5 text-sm text-ink-muted" role="status">Loading directly stored items…</div>
                    <div v-else-if="locationLevelsQuery.isError.value" class="p-5" role="alert"><p class="text-sm text-rose-700">{{ parseError(locationLevelsQuery.error.value, 'Items stored here could not be loaded.') }}</p><button type="button" class="mt-2 text-sm text-sage-dark underline" @click="locationLevelsQuery.refetch()">Try again</button></div>
                    <div v-else-if="!directLevels.length" class="grid min-h-32 place-items-center p-5 text-center"><div><strong class="text-sm font-semibold text-ink">No items stored here yet</strong><p class="mt-1 text-sm text-ink-muted">Stock recorded in sublocations stays in those sublocations.</p></div></div>
                    <ul v-else class="divide-y divide-line" aria-label="Items stored directly here">
                        <li v-for="level in visibleLevels" :key="level.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-start sm:px-6">
                            <div class="flex min-w-0 items-start gap-3">
                                <svg class="mt-0.5 size-5 shrink-0 text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 9 5v8l-9 5-9-5V8Zm0 10 9-5M12 13 3 8m9 5v8M7.5 5.5l9 5" /></svg>
                                <div class="min-w-0"><RouterLink :to="itemDestination(level.item.id)" class="break-words font-semibold text-ink hover:text-sage-dark hover:underline">{{ level.item.name }}</RouterLink><p class="mt-1 text-sm text-ink-muted">{{ level.quantity }} {{ level.quantity === 1 ? level.item.counting_unit : (level.item.counting_unit_plural || level.item.counting_unit) }}</p><p v-if="level.alert_threshold !== null" class="mt-1 text-xs text-ink-muted">Alert at {{ level.alert_threshold }}</p><div class="mt-2 flex flex-wrap gap-1.5" aria-label="Stock level status"><StockStatusBadge v-for="indicator in getStockLevelIndicators(level)" :key="indicator.label" :label="indicator.label" :tone="indicator.tone" /><StockStatusBadge v-if="hasActiveBuySoonAlert(level.item.active_alerts)" label="Buy soon" tone="reminder" /></div></div>
                            </div>
                            <LocationStockActions :key="`${contextType}:${contextId}:${level.id}`" :level="level" :item-destination="itemDestination(level.item.id)" :locations="locationOptions.data.value ?? []" :active-action="activeStockAction" :busy="stockMovementMutation.isPending.value" :errors="activeStockAction?.levelId === level.id ? stockActionErrors : { fields: {}, form: '' }" :locations-loading="locationOptions.isPending.value" :locations-error="locationOptions.isError.value" @activate="beginStockAction" @submit="saveStockAction" @close="closeStockAction" @retry-locations="locationOptions.refetch()" />
                        </li>
                    </ul>
                    <ListPagination v-if="locationLevelsQuery.isSuccess.value" v-model:per-page="inventoryPageSize" :meta="inventoryMeta" :page="inventoryPage" :busy="locationLevelsQuery.isFetching.value || stockMovementMutation.isPending.value" singular="item" plural="items" label="Direct item pagination" @change="changePage('inventory', $event)" />
                </section>
            </div>

            <NotesPanel :key="`${contextType}:${contextId}`" class="mt-6" :type="contextType" :context-id="contextId" />
        </template>
        <StockActionReceipt v-if="shelfReceipt" :level="shelfReceipt.level" :result="shelfReceipt.result" :locations="locationOptions.data.value ?? []" @close="shelfReceipt = null" />
    </section>
</template>
