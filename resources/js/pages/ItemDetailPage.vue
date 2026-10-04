<script setup lang="ts">
import axios from 'axios';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { buildLocationPath, type InventoryLevel } from '../api/stock';
import { useActiveAlertsQuery, useBuySoonMutation, useCreateInventoryLevelMutation, useCreateLocationMutation, useLocationsQuery, useRecordMovementMutation, useResolveAlertMutation, useStockItemQuery, useUpdateThresholdMutation } from '../queries/stock';
import NotesPanel from '../components/NotesPanel.vue';
import ItemImagesPanel from '../components/ItemImagesPanel.vue';
import ArchiveResourceButton from '../components/ArchiveResourceButton.vue';
import ItemEditor from '../components/ItemEditor.vue';
import InventoryTabs from '../components/InventoryTabs.vue';
import RecentItemMovements from '../components/RecentItemMovements.vue';
import type { ItemEditSnapshot, ItemUpdate, NewItem } from '../api/items';
import { useUpdateItemMutation } from '../queries/items';
import StockStatusBadge from '../components/StockStatusBadge.vue';
import { getStockLevelIndicators } from '../lib/stock-indicators';

type Action = 'restock' | 'transfer-out' | 'transfer-in' | 'correction' | 'disposal' | 'threshold';
const route = useRoute();
const itemId = computed(() => String(route.params.item));
const itemQuery = useStockItemQuery(itemId);
const alertParams = computed(() => ({ page: 1, perPage: 10 }));
const itemAlertQuery = useActiveAlertsQuery(alertParams, itemId);
const buySoonMutation = useBuySoonMutation();
const resolveAlertMutation = useResolveAlertMutation();
const locationsQuery = useLocationsQuery();
const movementMutation = useRecordMovementMutation();
const thresholdMutation = useUpdateThresholdMutation();
const createInventoryLevelMutation = useCreateInventoryLevelMutation();
const createLocationMutation = useCreateLocationMutation();
const updateItemMutation = useUpdateItemMutation();
const showEditForm = ref(false);
const editErrors = ref<FormErrors>({ fields: {}, form: '' });
const editSuccess = ref('');
const activeAction = ref<Action | null>(null);
const selectedLevel = ref<InventoryLevel | null>(null);
const quantity = ref('1');
const observedQuantity = ref('0');
const targetLocationId = ref('');
const threshold = ref('');
const confirmation = ref(false);
const errors = ref<FormErrors>({ fields: {}, form: '' });
const success = ref('');
const newLocationName = ref('');
const newLocationParent = ref('');
const showCreateLocation = ref(false);
const locationErrors = ref<FormErrors>({ fields: {}, form: '' });
const actionError = ref('');
const showSetupPanel = ref(false);
const setupLocationId = ref('');
const setupThreshold = ref('');
const setupErrors = ref<FormErrors>({ fields: {}, form: '' });
const setupSuccess = ref('');
let isMounted = true;
let setupContextGeneration = 0;
const pending = computed(() => movementMutation.isPending.value || thresholdMutation.isPending.value || createInventoryLevelMutation.isPending.value);
const manualAlert = computed(() => itemAlertQuery.data.value?.data[0] ?? null);
const alertPending = computed(() => buySoonMutation.isPending.value || resolveAlertMutation.isPending.value);

const locationOptions = computed(() => (locationsQuery.data.value ?? []).map((location) => ({ location, path: locationPath(location) })));
const canSaveNewRestock = computed(() => locationOptions.value.some(({ location }) => location.id === targetLocationId.value));
const otherLocations = computed(() => locationOptions.value.filter(({ location }) => location.id !== selectedLevel.value?.location.id));
const existingLocationIds = computed(() => new Set((itemQuery.data.value?.inventory_levels ?? []).map((level) => level.location.id)));
const eligibleSetupLocations = computed(() => locationOptions.value.filter(({ location }) => !existingLocationIds.value.has(location.id)));
const validSetupThreshold = computed(() => setupThreshold.value === '' || (Number.isInteger(Number(setupThreshold.value)) && Number(setupThreshold.value) >= 0));
const canSaveSetup = computed(() => eligibleSetupLocations.value.some(({ location }) => location.id === setupLocationId.value) && validSetupThreshold.value && !locationsQuery.isFetching.value && !locationsQuery.isError.value);
const positiveLevels = computed(() => (itemQuery.data.value?.inventory_levels ?? []).filter((level) => level.quantity > 0 && level.id !== selectedLevel.value?.id));

function locationPath(location: InventoryLevel['location']): string {
    return buildLocationPath(location, locationsQuery.data.value ?? []);
}

function hasPositiveSource(level: InventoryLevel): boolean {
    return (itemQuery.data.value?.inventory_levels ?? []).some((candidate) => candidate.quantity > 0 && candidate.id !== level.id);
}

function unitFor(quantityValue: number): string {
    const item = itemQuery.data.value;
    if (!item) return quantityValue === 1 ? 'item' : 'items';
    return quantityValue === 1 ? item.counting_unit : item.counting_unit_plural ?? item.counting_unit;
}

function startAction(action: Action, level: InventoryLevel | null = null): void {
    if (pending.value) {
        return;
    }

    activeAction.value = action;
    showSetupPanel.value = false;
    setupSuccess.value = '';
    selectedLevel.value = level;
    quantity.value = '1';
    observedQuantity.value = String(level?.quantity ?? 0);
    threshold.value = level?.alert_threshold === null || level?.alert_threshold === undefined ? '' : String(level.alert_threshold);
    targetLocationId.value = '';
    confirmation.value = false;
    errors.value = { fields: {}, form: '' };
    actionError.value = '';
    success.value = '';
}

function startLocationSetup(): void {
    if (pending.value) return;
    activeAction.value = null;
    selectedLevel.value = null;
    showSetupPanel.value = true;
    showCreateLocation.value = false;
    setupLocationId.value = '';
    setupThreshold.value = '';
    setupErrors.value = { fields: {}, form: '' };
    setupSuccess.value = '';
    success.value = '';
    actionError.value = '';
}

function closeLocationSetup(): void {
    if (pending.value) return;
    showSetupPanel.value = false;
    setupErrors.value = { fields: {}, form: '' };
}

async function submitLocationSetup(): Promise<void> {
    if (pending.value || !showSetupPanel.value) return;
    const operationItemId = itemId.value;
    const operationContext = setupContextGeneration;
    const currentItem = itemQuery.data.value;
    if (!currentItem || currentItem.id !== operationItemId) return;
    setupErrors.value = { fields: {}, form: '' };
    setupSuccess.value = '';

    const selectedLocation = eligibleSetupLocations.value.find(({ location }) => location.id === setupLocationId.value);
    if (!selectedLocation) {
        setupErrors.value.form = 'Choose an active location that does not already have a stock level for this item.';
        return;
    }

    if (!validSetupThreshold.value) {
        setupErrors.value.fields.alert_threshold = 'Enter a whole number of zero or more, or leave the threshold blank.';
        return;
    }

    const alertThreshold = setupThreshold.value === '' ? null : Number(setupThreshold.value);
    try {
        await createInventoryLevelMutation.mutateAsync({ item_id: operationItemId, location_id: selectedLocation.location.id, alert_threshold: alertThreshold });
        if (!isMounted || setupContextGeneration !== operationContext || itemId.value !== operationItemId) return;
        showSetupPanel.value = false;
        setupSuccess.value = `Stock location set up with 0 ${unitFor(0)}. No stock was added; restock later when you have this item.`;
    } catch (error) {
        if (!isMounted || setupContextGeneration !== operationContext || itemId.value !== operationItemId) return;
        const parsedErrors = parseApiErrors(error);
        if (axios.isAxiosError(error) && error.response?.status === 409) {
            setupErrors.value = {
                fields: parsedErrors.fields,
                form: 'This item already has a stock level at that location. We refreshed its current stock; choose a different location if you still need one.',
            };
            await itemQuery.refetch();
            if (isMounted && setupContextGeneration === operationContext && itemId.value === operationItemId) {
                setupLocationId.value = '';
            }
            return;
        }
        setupErrors.value = parsedErrors;
    }
}

watch(itemId, () => {
    setupContextGeneration++;
    activeAction.value = null;
    selectedLevel.value = null;
    targetLocationId.value = '';
    showCreateLocation.value = false;
    errors.value = { fields: {}, form: '' };
    success.value = '';
    actionError.value = '';
    showEditForm.value = false;
    editErrors.value = { fields: {}, form: '' };
    editSuccess.value = '';
    showSetupPanel.value = false;
    setupLocationId.value = '';
    setupThreshold.value = '';
    setupErrors.value = { fields: {}, form: '' };
    setupSuccess.value = '';
});

onUnmounted(() => {
    isMounted = false;
    setupContextGeneration++;
});

let handledRestockRequest = '';
watch(() => [itemId.value, route.query.restock_location, route.query.restock, itemQuery.data.value, locationsQuery.data.value, locationsQuery.isSuccess.value, pending.value] as const, ([currentItemId, requestedLocation, restockIntent, item, locations, locationsLoaded, stockChangePending]) => {
    const hasLocationRequest = Object.prototype.hasOwnProperty.call(route.query, 'restock_location');
    const hasGenericRequest = Object.prototype.hasOwnProperty.call(route.query, 'restock');
    if (!hasLocationRequest && !hasGenericRequest) {
        handledRestockRequest = '';
        return;
    }
    if (!item || item.id !== currentItemId || !locationsLoaded || stockChangePending) return;

    if (hasLocationRequest) {
        if (typeof requestedLocation !== 'string' || !requestedLocation || !locations?.some((location) => location.id === requestedLocation)) return;
        const requestKey = `${currentItemId}:location:${requestedLocation}`;
        if (requestKey === handledRestockRequest) return;

        const existingLevel = item.inventory_levels?.find((level) => level.location.id === requestedLocation);
        startAction('restock', existingLevel ?? null);
        if (!existingLevel) targetLocationId.value = requestedLocation;
        if (activeAction.value === 'restock') handledRestockRequest = requestKey;
        return;
    }

    if (typeof restockIntent !== 'string' || restockIntent !== '1') {
        handledRestockRequest = '';
        return;
    }
    const requestKey = `${currentItemId}:new`;
    if (requestKey === handledRestockRequest) return;
    startAction('restock');
    if (activeAction.value === 'restock') handledRestockRequest = requestKey;
});

function normalizedCategoryIds(categories: Array<{ id: string }> | undefined): string[] {
    return [...new Set((categories ?? []).map((category) => String(category.id)))].sort();
}

async function saveItemDetails(values: NewItem, initialItem: ItemEditSnapshot | null): Promise<void> {
    const item = itemQuery.data.value;
    if (!item || !initialItem || itemId.value !== initialItem.id || updateItemMutation.isPending.value) return;
    const operationItemId = initialItem.id;
    const changes: ItemUpdate = {};

    if (values.name !== initialItem.name) changes.name = values.name;
    if (values.counting_unit !== initialItem.counting_unit) changes.counting_unit = values.counting_unit;
    if (values.description !== initialItem.description) changes.description = values.description;

    const currentCategoryIds = normalizedCategoryIds(initialItem.category_ids.map((id) => ({ id })));
    const nextCategoryIds = normalizedCategoryIds(values.category_ids?.map((id) => ({ id })));
    if (currentCategoryIds.length !== nextCategoryIds.length || currentCategoryIds.some((id, index) => id !== nextCategoryIds[index])) {
        changes.category_ids = nextCategoryIds;
    }

    editErrors.value = { fields: {}, form: '' };
    editSuccess.value = '';
    if (Object.keys(changes).length === 0) {
        showEditForm.value = false;
        editSuccess.value = 'Item details are unchanged.';
        return;
    }

    try {
        await updateItemMutation.mutateAsync({ id: operationItemId, item: changes });
        if (itemId.value !== operationItemId) return;
        showEditForm.value = false;
        editSuccess.value = 'Item details saved.';
    } catch (error) {
        if (itemId.value !== operationItemId) return;
        editErrors.value = parseApiErrors(error);
    }
}

function cancelAction(): void {
    if (pending.value) {
        return;
    }

    activeAction.value = null;
    errors.value = { fields: {}, form: '' };
    confirmation.value = false;
}

function movementPayload(): Record<string, string | number> {
    const action = activeAction.value;
    const current = selectedLevel.value;
    if (!action) throw new Error('Choose a stock action first.');
    const amount = Number(quantity.value);
    if (action === 'restock' && !current) return { movement_type: 'restock', item_id: itemId.value, location_id: targetLocationId.value, quantity: amount };
    if (!current) throw new Error('Choose a stock location first.');
    if (action === 'correction') return { movement_type: 'correction', item_id: itemId.value, location_id: current.location.id, observed_quantity: Number(observedQuantity.value) };
    if (action === 'transfer-out') return { movement_type: 'transfer', item_id: itemId.value, source_location_id: current.location.id, destination_location_id: targetLocationId.value, quantity: amount };
    if (action === 'transfer-in') return { movement_type: 'transfer', item_id: itemId.value, source_location_id: targetLocationId.value, destination_location_id: current.location.id, quantity: amount };
    return { movement_type: action, item_id: itemId.value, location_id: current.location.id, quantity: amount };
}

function confirmationText(): string {
    const action = activeAction.value;
    const current = selectedLevel.value;
    const locations = locationOptions.value;
    const target = locations.find(({ location }) => location.id === targetLocationId.value)?.path ?? 'the selected location';
    if (action === 'restock' && !current) return `Restock ${quantity.value} ${unitFor(Number(quantity.value))} at ${target}.`;
    if (action === 'transfer-out') return `Move ${quantity.value} ${unitFor(Number(quantity.value))} from ${current ? locationPath(current.location) : 'this location'} to ${target}.`;
    if (action === 'transfer-in') return `Move ${quantity.value} ${unitFor(Number(quantity.value))} from ${target} to ${current ? locationPath(current.location) : 'this location'}.`;
    if (action === 'correction') return `Set stock at ${current ? locationPath(current.location) : 'this location'} to ${observedQuantity.value}.`;
    if (action === 'disposal') return `Dispose of ${quantity.value} ${unitFor(Number(quantity.value))} at ${current ? locationPath(current.location) : 'this location'}.`;
    return `Restock ${quantity.value} ${unitFor(Number(quantity.value))} at ${current ? locationPath(current.location) : 'this location'}.`;
}

async function submitAction(): Promise<void> {
    if (pending.value) {
        return;
    }

    const operationItemId = itemId.value;
    errors.value = { fields: {}, form: '' };
    actionError.value = '';
    if (activeAction.value !== 'threshold' && !confirmation.value) {
        errors.value.form = 'Confirm the stock change before saving.';
        return;
    }
    try {
        if (activeAction.value === 'threshold' && selectedLevel.value) {
            const nextThreshold = threshold.value === '' ? null : Number(threshold.value);
            if (nextThreshold === selectedLevel.value.alert_threshold) {
                success.value = 'Low stock threshold is unchanged.';
                activeAction.value = null;
                return;
            }
            await thresholdMutation.mutateAsync({ id: selectedLevel.value.id, alertThreshold: nextThreshold });
            if (itemId.value !== operationItemId) return;
            success.value = 'Low stock threshold updated.';
        } else {
            await movementMutation.mutateAsync(movementPayload());
            if (itemId.value !== operationItemId) return;
            success.value = 'Stock updated.';
        }
        activeAction.value = null;
        confirmation.value = false;
    } catch (error) {
        if (itemId.value !== operationItemId) return;
        if (activeAction.value) {
            errors.value = parseApiErrors(error);
        } else {
            actionError.value = parseApiErrors(error).form || 'The stock change could not be saved.';
        }
    }
}

async function addLocation(): Promise<void> {
    if (createLocationMutation.isPending.value) {
        return;
    }

    const operationItemId = itemId.value;
    const operationContext = setupContextGeneration;
    locationErrors.value = { fields: {}, form: '' };
    try {
        await createLocationMutation.mutateAsync({ name: newLocationName.value, parent_id: newLocationParent.value || null, description: null });
        if (!isMounted || setupContextGeneration !== operationContext || itemId.value !== operationItemId) return;
        newLocationName.value = '';
        newLocationParent.value = '';
        showCreateLocation.value = false;
        await locationsQuery.refetch();
    } catch (error) {
        if (!isMounted || setupContextGeneration !== operationContext || itemId.value !== operationItemId) return;
        locationErrors.value = parseApiErrors(error);
    }
}

function errorMessage(): string {
    return parseApiErrors(itemQuery.error.value).form || 'This item could not be loaded.';
}

function retry(): void {
    void itemQuery.refetch();
}

async function quickConsume(level: InventoryLevel): Promise<void> {
    if (pending.value || level.quantity < 1) {
        return;
    }

    actionError.value = '';
    success.value = '';
    const operationItemId = itemId.value;

    try {
        await movementMutation.mutateAsync({
            movement_type: 'consumption',
            item_id: itemId.value,
            location_id: level.location.id,
            quantity: 1,
        });
        if (itemId.value !== operationItemId) return;
        success.value = `Used 1 ${unitFor(1)} from ${locationPath(level.location)}.`;
    } catch (error) {
        if (itemId.value !== operationItemId) return;
        actionError.value = parseApiErrors(error).form || 'The stock change could not be saved.';
    }
}

async function toggleBuySoon(): Promise<void> {
    if (alertPending.value) return;
    const operationItemId = itemId.value;
    try {
        if (manualAlert.value) {
            await resolveAlertMutation.mutateAsync(manualAlert.value.id);
        } else {
            await buySoonMutation.mutateAsync(itemId.value);
        }
    } catch (error) {
        if (itemId.value !== operationItemId) return;
        actionError.value = parseApiErrors(error).form || 'The Buy soon status could not be updated.';
    }
}
</script>

<template>
    <section aria-labelledby="page-title">
        <RouterLink :to="{ name: 'inventory' }" class="text-sm font-medium text-sage-dark hover:underline">← Inventory</RouterLink>
        <InventoryTabs />
        <div v-if="itemQuery.isPending.value" class="mt-5 grid min-h-52 place-items-center text-sm text-ink-muted" role="status">Loading item stock…</div>
        <div v-else-if="itemQuery.isError.value" class="mt-5 rounded-panel border border-line bg-white p-6" role="alert">
            <h1 id="page-title" class="text-xl font-semibold text-ink">We couldn’t load this item</h1>
            <p class="mt-2 text-sm text-ink-muted">{{ errorMessage() }}</p>
            <button type="button" class="mt-4 rounded-md border border-line px-4 py-2 text-sm" @click="retry">Try again</button>
        </div>
        <template v-else-if="itemQuery.data.value">
            <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0 max-w-full">
                    <p class="eyebrow">Item stock</p>
                    <h1 id="page-title" class="page-title mt-2 break-words">{{ itemQuery.data.value.name }}</h1>
                    <p v-if="itemQuery.data.value.description" class="mt-2 whitespace-pre-line text-sm text-ink-muted">{{ itemQuery.data.value.description }}</p>
                    <nav v-if="itemQuery.data.value.categories?.length" class="mt-3 flex max-w-full flex-wrap gap-2" aria-label="Item categories"><RouterLink v-for="category in itemQuery.data.value.categories" :key="category.id" :to="{ name: 'category-detail', params: { category: category.id } }" class="max-w-full truncate rounded-md bg-sage-soft px-2.5 py-1 text-xs font-medium text-sage-dark hover:underline">{{ category.name }}</RouterLink></nav>
                    <button type="button" class="mt-3 min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" :aria-expanded="showEditForm" aria-controls="edit-item-panel" @click="showEditForm = !showEditForm; editSuccess = ''; editErrors = { fields: {}, form: '' }">{{ showEditForm ? 'Cancel edit' : 'Edit item' }}</button>
                </div>
                <div class="rounded-panel border border-line bg-white px-5 py-3 text-right shadow-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Total on hand</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ itemQuery.data.value.total_quantity }} <span class="text-sm font-normal text-ink-muted">{{ unitFor(itemQuery.data.value.total_quantity ?? 0) }}</span></p>
                </div>
            </div>

            <div class="mt-4"><ArchiveResourceButton type="items" :id="itemId" label="Item" /></div>

            <p v-if="editSuccess" class="mt-4 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ editSuccess }}</p>
            <ItemEditor v-if="showEditForm" id="edit-item-panel" class="mt-5" :item="itemQuery.data.value" :saving="updateItemMutation.isPending.value" :errors="editErrors" @submit="saveItemDetails" @cancel="showEditForm = false" />

            <ItemImagesPanel class="mt-6" :item-id="itemId" />

            <p v-if="success" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ success }}</p>
            <p v-if="setupSuccess" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ setupSuccess }}</p>
            <p v-if="actionError" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ actionError }}</p>

            <section class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card" aria-labelledby="buy-soon-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h2 id="buy-soon-title" class="font-semibold text-ink">Buy soon</h2><p class="mt-1 text-sm text-ink-muted">A personal reminder for your next shop.</p></div>
                    <button type="button" :disabled="alertPending || itemAlertQuery.isPending.value || itemAlertQuery.isError.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium disabled:opacity-50" @click="toggleBuySoon">{{ manualAlert ? 'Resolve Buy soon' : 'Mark Buy soon' }}</button>
                </div>
                <p v-if="itemAlertQuery.isError.value" class="mt-3 text-sm text-rose-700" role="alert">Buy soon status could not be loaded. <button type="button" class="underline" @click="itemAlertQuery.refetch()">Try again</button></p>
                <p v-else-if="itemAlertQuery.isPending.value" class="mt-2 text-sm text-ink-muted" role="status">Checking Buy soon status…</p>
                <p v-else-if="manualAlert" class="mt-2 text-sm text-sage-dark" aria-live="polite">Marked to buy soon.</p>
                <p v-else class="mt-2 text-sm text-ink-muted" aria-live="polite">No active Buy soon reminder.</p>
            </section>

            <section class="mt-7 rounded-panel border border-line bg-white shadow-card" aria-labelledby="locations-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                    <div><h2 id="locations-heading" class="text-base font-semibold text-ink">Stock by location</h2><p class="mt-1 text-sm text-ink-muted">Use quick actions for everyday changes; corrections and disposals ask for confirmation.</p></div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" :disabled="pending" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startLocationSetup">Set up a stock location</button>
                        <button type="button" :disabled="pending" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('restock')">Restock at another location</button>
                    </div>
                </div>
                <div v-if="locationsQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">Locations could not be loaded. <button class="underline" @click="locationsQuery.refetch()">Try again</button></div>
                <div v-else-if="locationsQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading locations…</div>
                <div v-else-if="!itemQuery.data.value.inventory_levels?.length" class="p-6 text-center">
                    <p class="font-medium text-ink">No stock locations yet</p><p class="mt-1 text-sm text-ink-muted">Add a location, then record the first restock.</p>
                    <button type="button" class="mt-4 rounded-md bg-sage px-4 py-2.5 text-sm font-semibold text-white" @click="showCreateLocation = true">Add a location</button>
                </div>
                <ul v-else class="divide-y divide-line">
                    <li v-for="level in itemQuery.data.value.inventory_levels" :key="level.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div><h3 class="font-semibold text-ink"><RouterLink :to="{ name: 'location-detail', params: { location: level.location.id } }" class="hover:underline">{{ locationPath(level.location) }}</RouterLink></h3><p class="mt-1 text-sm text-ink-muted">{{ level.quantity }} {{ unitFor(level.quantity) }}<span v-if="level.alert_threshold !== null"> · alert at {{ level.alert_threshold }}</span></p><div class="mt-2 flex flex-wrap gap-1.5" aria-label="Stock level status"><StockStatusBadge v-for="indicator in getStockLevelIndicators(level)" :key="indicator.label" :label="indicator.label" :tone="indicator.tone" /></div></div>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="level.quantity > 0" type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="quickConsume(level)">{{ movementMutation.isPending.value ? 'Saving…' : 'Use 1' }}</button>
                            <button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('restock', level)">Restock</button>
                            <button v-if="level.quantity > 0" type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('transfer-out', level)">Move out</button>
                            <button v-if="hasPositiveSource(level)" type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('transfer-in', level)">Move in</button>
                            <button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('correction', level)">Correct</button>
                            <button type="button" :disabled="pending" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium text-rose-700 hover:bg-rose-50 disabled:cursor-wait disabled:opacity-60" @click="startAction('disposal', level)">Dispose</button>
                            <button type="button" :disabled="pending" class="min-h-9 rounded-md px-3 text-sm font-medium text-sage-dark hover:bg-sage-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('threshold', level)">Threshold</button>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="showSetupPanel" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" :aria-busy="pending || locationsQuery.isFetching.value" aria-labelledby="setup-location-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="eyebrow">Zero-stock setup</p>
                        <h2 id="setup-location-title" class="mt-1 text-xl font-semibold text-ink">Set up stock location</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">This starts tracking this item at the selected location with 0 {{ unitFor(0) }} and adds no stock. You can record a restock later. Leave the alert threshold blank to keep it unmonitored; choosing 0 explicitly enables an empty-stock alert.</p>
                    </div>
                    <button type="button" :disabled="pending" class="min-h-10 rounded-md px-3 text-sm font-medium text-ink-muted underline disabled:cursor-wait disabled:opacity-60" @click="closeLocationSetup">Cancel</button>
                </div>
                <p v-if="setupErrors.form" aria-label="setup-location-error" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ setupErrors.form }}</p>
                <p v-for="(message, field) in setupErrors.fields" :key="field" class="mt-3 text-sm text-rose-700" role="alert">{{ message }}</p>
                <p v-if="locationsQuery.isFetching.value" class="mt-4 text-sm text-ink-muted" role="status">Loading locations…</p>
                <div v-else-if="locationsQuery.isError.value" class="mt-4 text-sm text-rose-700" role="alert">Locations could not be loaded. <button type="button" class="font-medium underline" @click="locationsQuery.refetch()">Try again</button></div>
                <form v-else id="setup-location-form" class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="submitLocationSetup">
                    <div v-if="eligibleSetupLocations.length" class="grid min-w-0 gap-2">
                        <label for="setup-location" class="text-sm font-medium text-ink">Location</label>
                        <select id="setup-location" v-model="setupLocationId" required :disabled="pending" class="min-h-11 w-full min-w-0 rounded-md border border-line bg-white px-3 text-ink disabled:opacity-60"><option value="" disabled>Select a location</option><option v-for="option in eligibleSetupLocations" :key="option.location.id" :value="option.location.id">{{ option.path }}</option></select>
                    </div>
                    <div v-if="eligibleSetupLocations.length" class="grid min-w-0 gap-2">
                        <label for="setup-threshold" class="text-sm font-medium text-ink">Alert when quantity reaches <span class="font-normal text-ink-muted">(optional)</span></label>
                        <input id="setup-threshold" v-model="setupThreshold" type="number" min="0" step="1" :disabled="pending" :aria-invalid="Boolean(setupErrors.fields.alert_threshold) || (setupThreshold !== '' && !validSetupThreshold)" :aria-describedby="setupThreshold !== '' && !validSetupThreshold ? 'setup-threshold-error' : undefined" class="min-h-11 w-full min-w-0 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20 disabled:opacity-60">
                        <p class="text-xs leading-5 text-ink-muted">Blank leaves alerts off; 0 is a valid threshold and marks this location empty.</p>
                        <p v-if="setupThreshold !== '' && !validSetupThreshold" id="setup-threshold-error" class="text-sm text-rose-700" role="alert">Enter a whole number of zero or more.</p>
                    </div>
                    <div v-if="!eligibleSetupLocations.length" class="grid gap-3 sm:col-span-2" role="status">
                        <p v-if="locationOptions.length === 0" class="text-sm text-ink-muted">There are no active locations yet. Create one to set up a stock location.</p>
                        <p v-else class="text-sm text-ink-muted">Every active location already has a stock level for this item.</p>
                        <button type="button" :disabled="pending" class="min-h-10 w-fit rounded-md border border-line px-3 text-sm font-medium text-ink disabled:opacity-60" @click="showCreateLocation = true">Create a location</button>
                    </div>
                    <div v-if="eligibleSetupLocations.length" class="flex flex-wrap gap-3 sm:col-span-2">
                        <button type="submit" :disabled="pending || !canSaveSetup" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:cursor-wait disabled:opacity-60">{{ createInventoryLevelMutation.isPending.value ? 'Setting up…' : 'Set up location' }}</button>
                    </div>
                </form>
            </section>

            <section v-if="activeAction" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="action-title">
                <div class="flex items-start justify-between gap-3"><div><p class="eyebrow">Stock change</p><h2 id="action-title" class="mt-1 text-xl font-semibold text-ink">{{ activeAction === 'threshold' ? 'Edit low stock threshold' : activeAction === 'correction' ? 'Correct stock count' : activeAction === 'disposal' ? 'Record disposal' : activeAction === 'transfer-in' || activeAction === 'transfer-out' ? 'Transfer stock' : 'Restock' }}</h2></div><button type="button" :disabled="pending" class="text-sm text-ink-muted underline disabled:cursor-wait disabled:opacity-60" @click="cancelAction">Cancel</button></div>
                <div v-if="errors.form" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ errors.form }}</div>
                <div v-if="Object.keys(errors.fields).length" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert"><p v-for="(message, field) in errors.fields" :key="field">{{ message }}</p></div>
                <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="submitAction">
                    <div v-if="activeAction === 'threshold'" class="grid gap-2 sm:col-span-2"><label for="stock-threshold" class="text-sm font-medium text-ink">Alert when quantity falls to <span class="text-ink-muted">(leave blank to disable)</span></label><input id="stock-threshold" v-model="threshold" type="number" min="0" step="1" class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    <div v-else-if="activeAction === 'correction'" class="grid gap-2"><label for="observed-quantity" class="text-sm font-medium text-ink">Observed quantity</label><input id="observed-quantity" v-model="observedQuantity" type="number" min="0" step="1" required class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    <template v-else-if="activeAction === 'restock' && !selectedLevel">
                        <p v-if="locationsQuery.isSuccess.value && locationOptions.length === 0" class="text-sm text-ink-muted sm:col-span-2" role="status">Add a location before recording this restock.</p>
                        <div class="grid gap-2"><label for="new-level-location" class="text-sm font-medium text-ink">Location</label><select id="new-level-location" v-model="targetLocationId" required class="min-h-11 rounded-md border border-line bg-white px-3 text-ink"><option value="" disabled>Select a location</option><option v-for="option in locationOptions" :key="option.location.id" :value="option.location.id">{{ option.path }}</option></select></div>
                        <div class="grid gap-2"><label for="new-level-quantity" class="text-sm font-medium text-ink">Quantity <span>({{ unitFor(2) }})</span></label><input id="new-level-quantity" v-model="quantity" type="number" min="1" step="1" required class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    </template>
                    <template v-else>
                        <div v-if="activeAction === 'transfer-in' || activeAction === 'transfer-out'" class="grid gap-2"><label for="transfer-location" class="text-sm font-medium text-ink">{{ activeAction === 'transfer-out' ? 'Move to' : 'Move from' }}</label><select id="transfer-location" v-model="targetLocationId" required class="min-h-11 rounded-md border border-line bg-white px-3 text-ink"><option value="" disabled>Select a location</option><option v-for="option in (activeAction === 'transfer-in' ? positiveLevels.map((level) => ({ location: level.location, path: locationPath(level.location) })) : otherLocations)" :key="option.location.id" :value="option.location.id">{{ option.path }}{{ activeAction === 'transfer-in' ? ` (${positiveLevels.find((level) => level.location.id === option.location.id)?.quantity} available)` : '' }}</option></select></div>
                        <div class="grid gap-2"><label for="stock-quantity" class="text-sm font-medium text-ink">Quantity <span>({{ unitFor(2) }})</span></label><input id="stock-quantity" v-model="quantity" type="number" min="1" step="1" required :max="activeAction === 'transfer-in' ? positiveLevels.find((level) => level.location.id === targetLocationId)?.quantity : activeAction === 'transfer-out' || activeAction === 'disposal' ? selectedLevel?.quantity : undefined" class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    </template>
                    <div v-if="activeAction !== 'threshold'" class="grid gap-2 rounded-md bg-sage-soft px-3 py-3 sm:col-span-2"><p class="text-sm font-medium text-sage-dark">{{ confirmationText() }}</p><label class="flex items-start gap-2 text-sm text-ink"><input v-model="confirmation" type="checkbox" class="mt-1 accent-sage"><span>I’ve checked this direction and quantity.</span></label></div>
                    <div class="flex flex-wrap gap-3 sm:col-span-2"><button type="submit" :disabled="pending || (activeAction === 'restock' && !selectedLevel && !canSaveNewRestock)" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ pending ? 'Saving…' : 'Save change' }}</button><button v-if="activeAction === 'restock' && !selectedLevel" type="button" class="min-h-11 rounded-md border border-line px-4 text-sm font-medium" @click="showCreateLocation = true">Create a location</button></div>
                </form>
            </section>
            <section v-if="showCreateLocation" class="mt-6 rounded-panel border border-line bg-white p-5" aria-labelledby="create-location-title"><h2 id="create-location-title" class="text-lg font-semibold text-ink">Create a location</h2><p v-if="locationErrors.form" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ locationErrors.form }}</p><p v-for="(message, field) in locationErrors.fields" :key="field" class="mt-3 text-sm text-rose-700" role="alert">{{ message }}</p><form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="addLocation"><div class="grid gap-2"><label for="location-name" class="text-sm font-medium text-ink">Name</label><input id="location-name" v-model="newLocationName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3"></div><div class="grid gap-2"><label for="location-parent" class="text-sm font-medium text-ink">Inside another location <span class="font-normal text-ink-muted">(optional)</span></label><select id="location-parent" v-model="newLocationParent" class="min-h-11 rounded-md border border-line bg-white px-3"><option value="">No parent</option><option v-for="option in locationOptions" :key="option.location.id" :value="option.location.id">{{ option.path }}</option></select></div><div class="flex gap-3 sm:col-span-2"><button :disabled="createLocationMutation.isPending.value" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white">Create location</button><button type="button" class="min-h-11 rounded-md border border-line px-4" @click="showCreateLocation = false">Cancel</button></div></form></section>
            <RecentItemMovements v-if="itemQuery.data.value.id === itemId" :key="itemQuery.data.value.id" :item-id="itemQuery.data.value.id" :locations="locationsQuery.data.value ?? []" />
            <NotesPanel class="mt-6" type="items" :context-id="itemQuery.data.value.id" />
        </template>
    </section>
</template>
