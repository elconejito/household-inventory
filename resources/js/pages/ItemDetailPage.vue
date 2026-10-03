<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { buildLocationPath, type InventoryLevel } from '../api/stock';
import { useActiveAlertsQuery, useBuySoonMutation, useCreateLocationMutation, useLocationsQuery, useRecordMovementMutation, useResolveAlertMutation, useStockItemQuery, useUpdateThresholdMutation } from '../queries/stock';
import NotesPanel from '../components/NotesPanel.vue';
import ItemImagesPanel from '../components/ItemImagesPanel.vue';
import ArchiveResourceButton from '../components/ArchiveResourceButton.vue';

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
const createLocationMutation = useCreateLocationMutation();
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
const pending = computed(() => movementMutation.isPending.value || thresholdMutation.isPending.value);
const manualAlert = computed(() => itemAlertQuery.data.value?.data[0] ?? null);
const alertPending = computed(() => buySoonMutation.isPending.value || resolveAlertMutation.isPending.value);

const locationOptions = computed(() => (locationsQuery.data.value ?? []).map((location) => ({ location, path: locationPath(location) })));
const otherLocations = computed(() => locationOptions.value.filter(({ location }) => location.id !== selectedLevel.value?.location.id));
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

watch(itemId, () => {
    activeAction.value = null;
    selectedLevel.value = null;
    targetLocationId.value = '';
    showCreateLocation.value = false;
    errors.value = { fields: {}, form: '' };
    success.value = '';
    actionError.value = '';
});

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

    locationErrors.value = { fields: {}, form: '' };
    try {
        await createLocationMutation.mutateAsync({ name: newLocationName.value, parent_id: newLocationParent.value || null, description: null });
        newLocationName.value = '';
        newLocationParent.value = '';
        showCreateLocation.value = false;
        await locationsQuery.refetch();
    } catch (error) {
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
        <div v-if="itemQuery.isPending.value" class="mt-5 grid min-h-52 place-items-center text-sm text-ink-muted" role="status">Loading item stock…</div>
        <div v-else-if="itemQuery.isError.value" class="mt-5 rounded-panel border border-line bg-white p-6" role="alert">
            <h1 id="page-title" class="text-xl font-semibold text-ink">We couldn’t load this item</h1>
            <p class="mt-2 text-sm text-ink-muted">{{ errorMessage() }}</p>
            <button type="button" class="mt-4 rounded-md border border-line px-4 py-2 text-sm" @click="retry">Try again</button>
        </div>
        <template v-else-if="itemQuery.data.value">
            <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">Item stock</p>
                    <h1 id="page-title" class="page-title mt-2">{{ itemQuery.data.value.name }}</h1>
                    <p v-if="itemQuery.data.value.description" class="mt-2 text-sm text-ink-muted">{{ itemQuery.data.value.description }}</p>
                </div>
                <div class="rounded-panel border border-line bg-white px-5 py-3 text-right shadow-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Total on hand</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ itemQuery.data.value.total_quantity }} <span class="text-sm font-normal text-ink-muted">{{ unitFor(itemQuery.data.value.total_quantity ?? 0) }}</span></p>
                </div>
            </div>

            <div class="mt-4"><ArchiveResourceButton type="items" :id="itemId" label="Item" /></div>

            <ItemImagesPanel class="mt-6" :item-id="itemId" />

            <p v-if="success" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ success }}</p>
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
                    <button type="button" :disabled="pending" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft disabled:cursor-wait disabled:opacity-60" @click="startAction('restock')">Restock at another location</button>
                </div>
                <div v-if="locationsQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">Locations could not be loaded. <button class="underline" @click="locationsQuery.refetch()">Try again</button></div>
                <div v-else-if="locationsQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading locations…</div>
                <div v-else-if="!itemQuery.data.value.inventory_levels?.length" class="p-6 text-center">
                    <p class="font-medium text-ink">No stock locations yet</p><p class="mt-1 text-sm text-ink-muted">Add a location, then record the first restock.</p>
                    <button type="button" class="mt-4 rounded-md bg-sage px-4 py-2.5 text-sm font-semibold text-white" @click="showCreateLocation = true">Add a location</button>
                </div>
                <ul v-else class="divide-y divide-line">
                    <li v-for="level in itemQuery.data.value.inventory_levels" :key="level.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div><h3 class="font-semibold text-ink"><RouterLink :to="{ name: 'location-detail', params: { location: level.location.id } }" class="hover:underline">{{ locationPath(level.location) }}</RouterLink></h3><p class="mt-1 text-sm text-ink-muted">{{ level.quantity }} {{ unitFor(level.quantity) }}<span v-if="level.alert_threshold !== null"> · alert at {{ level.alert_threshold }}</span></p></div>
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

            <section v-if="activeAction" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="action-title">
                <div class="flex items-start justify-between gap-3"><div><p class="eyebrow">Stock change</p><h2 id="action-title" class="mt-1 text-xl font-semibold text-ink">{{ activeAction === 'threshold' ? 'Edit low stock threshold' : activeAction === 'correction' ? 'Correct stock count' : activeAction === 'disposal' ? 'Record disposal' : activeAction === 'transfer-in' || activeAction === 'transfer-out' ? 'Transfer stock' : 'Restock' }}</h2></div><button type="button" :disabled="pending" class="text-sm text-ink-muted underline disabled:cursor-wait disabled:opacity-60" @click="cancelAction">Cancel</button></div>
                <div v-if="errors.form" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ errors.form }}</div>
                <div v-if="Object.keys(errors.fields).length" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert"><p v-for="(message, field) in errors.fields" :key="field">{{ message }}</p></div>
                <form class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="submitAction">
                    <div v-if="activeAction === 'threshold'" class="grid gap-2 sm:col-span-2"><label for="stock-threshold" class="text-sm font-medium text-ink">Alert when quantity falls to <span class="text-ink-muted">(leave blank to disable)</span></label><input id="stock-threshold" v-model="threshold" type="number" min="0" step="1" class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    <div v-else-if="activeAction === 'correction'" class="grid gap-2"><label for="observed-quantity" class="text-sm font-medium text-ink">Observed quantity</label><input id="observed-quantity" v-model="observedQuantity" type="number" min="0" step="1" required class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    <template v-else-if="activeAction === 'restock' && !selectedLevel">
                        <div class="grid gap-2"><label for="new-level-location" class="text-sm font-medium text-ink">Location</label><select id="new-level-location" v-model="targetLocationId" required class="min-h-11 rounded-md border border-line bg-white px-3 text-ink"><option value="" disabled>Select a location</option><option v-for="option in locationOptions" :key="option.location.id" :value="option.location.id">{{ option.path }}</option></select></div>
                        <div class="grid gap-2"><label for="new-level-quantity" class="text-sm font-medium text-ink">Quantity <span>({{ unitFor(2) }})</span></label><input id="new-level-quantity" v-model="quantity" type="number" min="1" step="1" required class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    </template>
                    <template v-else>
                        <div v-if="activeAction === 'transfer-in' || activeAction === 'transfer-out'" class="grid gap-2"><label for="transfer-location" class="text-sm font-medium text-ink">{{ activeAction === 'transfer-out' ? 'Move to' : 'Move from' }}</label><select id="transfer-location" v-model="targetLocationId" required class="min-h-11 rounded-md border border-line bg-white px-3 text-ink"><option value="" disabled>Select a location</option><option v-for="option in (activeAction === 'transfer-in' ? positiveLevels.map((level) => ({ location: level.location, path: locationPath(level.location) })) : otherLocations)" :key="option.location.id" :value="option.location.id">{{ option.path }}{{ activeAction === 'transfer-in' ? ` (${positiveLevels.find((level) => level.location.id === option.location.id)?.quantity} available)` : '' }}</option></select></div>
                        <div class="grid gap-2"><label for="stock-quantity" class="text-sm font-medium text-ink">Quantity <span>({{ unitFor(2) }})</span></label><input id="stock-quantity" v-model="quantity" type="number" min="1" step="1" required :max="activeAction === 'transfer-in' ? positiveLevels.find((level) => level.location.id === targetLocationId)?.quantity : activeAction === 'transfer-out' || activeAction === 'disposal' ? selectedLevel?.quantity : undefined" class="min-h-11 rounded-md border border-line px-3 text-ink focus:border-sage focus:outline-none focus:ring-2 focus:ring-sage/20"></div>
                    </template>
                    <div v-if="activeAction !== 'threshold'" class="grid gap-2 rounded-md bg-sage-soft px-3 py-3 sm:col-span-2"><p class="text-sm font-medium text-sage-dark">{{ confirmationText() }}</p><label class="flex items-start gap-2 text-sm text-ink"><input v-model="confirmation" type="checkbox" class="mt-1 accent-sage"><span>I’ve checked this direction and quantity.</span></label></div>
                    <div class="flex flex-wrap gap-3 sm:col-span-2"><button type="submit" :disabled="pending" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark disabled:opacity-60">{{ pending ? 'Saving…' : 'Save change' }}</button><button v-if="activeAction === 'restock' && !selectedLevel" type="button" class="min-h-11 rounded-md border border-line px-4 text-sm font-medium" @click="showCreateLocation = true">Create a location</button></div>
                </form>
            </section>
            <section v-if="showCreateLocation" class="mt-6 rounded-panel border border-line bg-white p-5" aria-labelledby="create-location-title"><h2 id="create-location-title" class="text-lg font-semibold text-ink">Create a location</h2><p v-if="locationErrors.form" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ locationErrors.form }}</p><p v-for="(message, field) in locationErrors.fields" :key="field" class="mt-3 text-sm text-rose-700" role="alert">{{ message }}</p><form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="addLocation"><div class="grid gap-2"><label for="location-name" class="text-sm font-medium text-ink">Name</label><input id="location-name" v-model="newLocationName" required maxlength="255" class="min-h-11 rounded-md border border-line px-3"></div><div class="grid gap-2"><label for="location-parent" class="text-sm font-medium text-ink">Inside another location <span class="font-normal text-ink-muted">(optional)</span></label><select id="location-parent" v-model="newLocationParent" class="min-h-11 rounded-md border border-line bg-white px-3"><option value="">No parent</option><option v-for="option in locationOptions" :key="option.location.id" :value="option.location.id">{{ option.path }}</option></select></div><div class="flex gap-3 sm:col-span-2"><button :disabled="createLocationMutation.isPending.value" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white">Create location</button><button type="button" class="min-h-11 rounded-md border border-line px-4" @click="showCreateLocation = false">Cancel</button></div></form></section>
            <NotesPanel class="mt-6" type="items" :context-id="itemId" />
        </template>
    </section>
</template>
