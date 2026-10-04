<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useStockItemQuery } from '../queries/stock';
import { buildLocationPath, type InventoryLevel, type Location } from '../api/stock';
import type { CatalogInventoryLevel } from '../api/catalog';
import type { FormErrors } from '../lib/api-errors';

export type LocationStockAction = 'restock' | 'move-out' | 'move-in';

type ActiveAction = { levelId: string; action: LocationStockAction } | null;

const props = defineProps<{
    level: CatalogInventoryLevel;
    locations: Location[];
    activeAction: ActiveAction;
    busy: boolean;
    errors: FormErrors;
    locationsLoading: boolean;
    locationsError: boolean;
}>();

const emit = defineEmits<{
    activate: [levelId: string, action: LocationStockAction];
    submit: [levelId: string, data: Record<string, string | number>];
    close: [];
    retryLocations: [];
}>();

const quantity = ref('1');
const targetLocationId = ref('');
const sourceLocationId = ref('');
const confirmed = ref(false);
const activeAction = computed(() => props.activeAction?.levelId === props.level.id ? props.activeAction.action : null);
const detailItemId = computed(() => activeAction.value === 'move-in' ? props.level.item.id : '');
const itemDetailQuery = useStockItemQuery(detailItemId);
const sourceLevels = computed(() => (itemDetailQuery.data.value?.inventory_levels ?? []).filter((source: InventoryLevel) => source.quantity > 0 && source.location.id !== props.level.location.id));
const destinationLocations = computed(() => props.locations.filter((location) => location.id !== props.level.location.id));
const selectedSource = computed(() => sourceLevels.value.find((source) => source.location.id === sourceLocationId.value));
const quantityValue = computed(() => Number(quantity.value));
const validQuantity = computed(() => Number.isInteger(quantityValue.value) && quantityValue.value > 0 && (activeAction.value === 'move-out' ? quantityValue.value <= props.level.quantity : activeAction.value === 'move-in' ? Boolean(selectedSource.value && quantityValue.value <= selectedSource.value.quantity) : true));
const validChoices = computed(() => activeAction.value === 'move-out'
    ? !props.locationsLoading && !props.locationsError && destinationLocations.value.some((location) => location.id === targetLocationId.value)
    : activeAction.value === 'move-in'
        ? !itemDetailQuery.isFetching.value && !itemDetailQuery.isError.value && Boolean(selectedSource.value && itemDetailQuery.data.value?.id === props.level.item.id)
        : true);
const canSubmit = computed(() => Boolean(activeAction.value && props.activeAction?.levelId === props.level.id && !props.busy && confirmed.value && validQuantity.value && validChoices.value));
const confirmationText = computed(() => {
    const amount = quantity.value;
    const destination = buildLocationPath(props.level.location, props.locations);
    if (activeAction.value === 'move-out') {
        const target = props.locations.find((location) => location.id === targetLocationId.value);
        return target ? `Move ${amount} ${unitName(Number(amount))} from ${destination} to ${buildLocationPath(target, props.locations)}.` : '';
    }
    if (activeAction.value === 'move-in') {
        const source = selectedSource.value?.location;
        return source ? `Move ${amount} ${unitName(Number(amount))} from ${buildLocationPath(source, props.locations)} to ${destination}.` : '';
    }

    return `Restock ${amount} ${unitName(Number(amount))} at ${destination}.`;
});

watch([activeAction, () => props.level.id], () => {
    quantity.value = '1';
    targetLocationId.value = '';
    sourceLocationId.value = '';
    confirmed.value = false;
});
watch([quantity, targetLocationId, sourceLocationId], () => { confirmed.value = false; });
watch(() => itemDetailQuery.data.value, () => {
    if (activeAction.value === 'move-in') confirmed.value = false;
});

function unitName(amount: number): string {
    return Math.abs(amount) === 1 ? props.level.item.counting_unit : props.level.item.counting_unit_plural || props.level.item.counting_unit;
}

function begin(action: LocationStockAction): void {
    if (!props.busy) emit('activate', props.level.id, action);
}

function useOne(): void {
    if (props.busy || props.level.quantity < 1) return;
    emit('submit', props.level.id, { movement_type: 'consumption', item_id: props.level.item.id, location_id: props.level.location.id, quantity: 1 });
}

function submit(): void {
    if (!activeAction.value || !canSubmit.value) return;
    const amount = Number(quantity.value);
    if (activeAction.value === 'restock') {
        emit('submit', props.level.id, { movement_type: 'restock', item_id: props.level.item.id, location_id: props.level.location.id, quantity: amount });
        return;
    }
    if (activeAction.value === 'move-out' && targetLocationId.value) {
        emit('submit', props.level.id, { movement_type: 'transfer', item_id: props.level.item.id, source_location_id: props.level.location.id, destination_location_id: targetLocationId.value, quantity: amount });
        return;
    }
    if (activeAction.value === 'move-in' && selectedSource.value) {
        emit('submit', props.level.id, { movement_type: 'transfer', item_id: props.level.item.id, source_location_id: selectedSource.value.location.id, destination_location_id: props.level.location.id, quantity: amount });
    }
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <button v-if="level.quantity > 0" type="button" :disabled="busy" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:opacity-50" @click="useOne">Use 1</button>
            <button type="button" :disabled="busy" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:opacity-50" @click="begin('restock')">Restock</button>
            <button v-if="level.quantity > 0" type="button" :disabled="busy" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:opacity-50" @click="begin('move-out')">Move out</button>
            <button type="button" :disabled="busy" class="min-h-9 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft disabled:opacity-50" @click="begin('move-in')">Move in</button>
        </div>

        <form v-if="activeAction" class="grid gap-3 rounded-md border border-line bg-canvas p-3 sm:grid-cols-2" :aria-labelledby="`stock-action-title-${level.id}`" @submit.prevent="submit">
            <p :id="`stock-action-title-${level.id}`" class="font-semibold text-ink sm:col-span-2">{{ activeAction === 'restock' ? 'Restock' : activeAction === 'move-out' ? 'Move stock out' : 'Move stock in' }}</p>
            <template v-if="activeAction === 'move-out'">
                <div class="grid min-w-0 gap-2 sm:col-span-2"><label :for="`move-out-destination-${level.id}`" class="text-sm font-medium text-ink">Move to</label><p v-if="locationsLoading" class="text-sm text-ink-muted" role="status">Loading locations…</p><p v-else-if="locationsError" class="text-sm text-rose-700" role="alert">Locations could not be loaded. <button type="button" class="underline" @click="emit('retryLocations')">Try again</button></p><p v-else-if="!destinationLocations.length" class="text-sm text-ink-muted">No other active locations are available.</p><select v-else :id="`move-out-destination-${level.id}`" v-model="targetLocationId" required :disabled="locationsLoading || locationsError" class="min-h-10 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="" disabled>Select a destination</option><option v-for="location in destinationLocations" :key="location.id" :value="location.id">{{ buildLocationPath(location, locations) }}</option></select></div>
            </template>
            <template v-else-if="activeAction === 'move-in'">
                <div class="grid min-w-0 gap-2 sm:col-span-2"><label :for="`move-in-source-${level.id}`" class="text-sm font-medium text-ink">Move from</label><p v-if="itemDetailQuery.isFetching.value" class="text-sm text-ink-muted" role="status">Loading item stock…</p><p v-else-if="itemDetailQuery.isError.value" class="text-sm text-rose-700" role="alert">Available stock could not be loaded. <button type="button" class="underline" @click="itemDetailQuery.refetch()">Try again</button></p><template v-else><select :id="`move-in-source-${level.id}`" v-model="sourceLocationId" required :disabled="!sourceLevels.length" class="min-h-10 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="" disabled>{{ sourceLevels.length ? 'Select a source' : 'No other locations have stock' }}</option><option v-for="source in sourceLevels" :key="source.id" :value="source.location.id">{{ buildLocationPath(source.location, locations) }} ({{ source.quantity }} {{ unitName(source.quantity) }} available)</option></select></template></div>
            </template>
            <div class="grid min-w-0 gap-2"><label :for="`stock-action-quantity-${level.id}`" class="text-sm font-medium text-ink">Quantity ({{ unitName(2) }})</label><input :id="`stock-action-quantity-${level.id}`" v-model="quantity" type="number" min="1" step="1" required :max="activeAction === 'move-out' ? level.quantity : activeAction === 'move-in' ? selectedSource?.quantity : undefined" :disabled="activeAction === 'move-in' && (!selectedSource || itemDetailQuery.isFetching.value || itemDetailQuery.isError.value)" class="min-h-10 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"></div>
            <div class="grid min-w-0 gap-2 rounded-md bg-sage-soft px-3 py-3 sm:col-span-2"><p class="break-words text-sm font-medium text-sage-dark">{{ confirmationText || 'Choose a source or destination to review this stock change.' }}</p><label class="flex items-start gap-2 text-sm text-ink"><input v-model="confirmed" type="checkbox" class="mt-1 accent-sage"><span>Confirm this stock change.</span></label></div>
            <div v-if="errors.form || Object.keys(errors.fields).length" class="grid gap-1 text-sm text-rose-700 sm:col-span-2" role="alert"><p v-if="errors.form">{{ errors.form }}</p><p v-for="(message, field) in errors.fields" :key="field">{{ message }}</p></div>
            <div class="flex gap-2 sm:col-span-2"><button type="submit" :disabled="!canSubmit" class="min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ busy ? 'Saving…' : 'Save change' }}</button><button type="button" :disabled="busy" class="min-h-10 rounded-md border border-line px-4 text-sm" @click="emit('close')">Cancel</button></div>
        </form>
    </div>
</template>
