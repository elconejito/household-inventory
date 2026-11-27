<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useStockItemQuery } from '../queries/stock';
import { buildLocationPath, compareLocationPaths, type Location } from '../api/stock';
import type { CatalogInventoryLevel } from '../api/catalog';
import type { FormErrors } from '../lib/api-errors';
import FormModal from './FormModal.vue';
import StockImpact from './StockImpact.vue';
import UiIcon from './UiIcon.vue';

export type LocationStockAction = 'menu' | 'count' | 'restock' | 'move-out' | 'move-in';

const props = defineProps<{
    level: CatalogInventoryLevel;
    itemDestination?: RouteLocationRaw;
    locations: Location[];
    activeAction: { levelId: string; action: LocationStockAction } | null;
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
const quantity = ref('');
const choiceId = ref('');
const active = computed(() => props.activeAction?.levelId === props.level.id ? props.activeAction.action : null);
const moving = computed(() => active.value === 'move-in' || active.value === 'move-out');
const itemDetail = useStockItemQuery(computed(() => moving.value || active.value === 'count' ? props.level.item.id : ''));
const other = computed(() => (itemDetail.data.value?.inventory_levels ?? [])
    .filter((level) => level.location.id !== props.level.location.id && level.quantity > 0)
    .sort((a, b) => compareLocationPaths(a.location, b.location, props.locations)));
const destinations = computed(() => props.locations.filter((location) => location.id !== props.level.location.id)
    .sort((a, b) => compareLocationPaths(a, b, props.locations)));
const source = computed(() => other.value.find((level) => level.location.id === choiceId.value));
const destination = computed(() => destinations.value.find((location) => location.id === choiceId.value));
const destinationQuantity = computed(() => itemDetail.data.value?.inventory_levels?.find((level) => level.location.id === choiceId.value)?.quantity ?? 0);
const amount = computed(() => Number(quantity.value));
const validQuantity = computed(() => String(quantity.value).trim() !== '' && Number.isInteger(amount.value) && amount.value >= (active.value === 'count' ? 0 : 1) && amount.value <= 4294967295);
const ready = computed(() => {
    if (props.busy || !validQuantity.value) return false;
    if (moving.value && (!itemDetail.isSuccess.value || itemDetail.isFetching.value)) return false;
    if (active.value === 'move-in') return Boolean(source.value && source.value.quantity >= amount.value && props.level.quantity + amount.value <= 4294967295);
    if (active.value === 'move-out') return !props.locationsLoading && !props.locationsError && Boolean(destination.value) && amount.value <= props.level.quantity && destinationQuantity.value + amount.value <= 4294967295;
    return active.value === 'count' || (active.value === 'restock' && props.level.quantity + amount.value <= 4294967295);
});
const unit = (n: number) => n === 1 ? props.level.item.counting_unit : props.level.item.counting_unit_plural || props.level.item.counting_unit;
const path = computed(() => buildLocationPath(props.level.location, props.locations));
const qtyLabel = computed(() => active.value === 'count' ? 'Counted on this shelf' : active.value === 'restock' ? 'Quantity to add' : 'Quantity to move');
const title = computed(() => {
    const prefix = active.value === 'count' ? 'Count ' : active.value === 'restock' ? 'Restock ' : moving.value ? 'Move ' : '';
    return prefix + props.level.item.name;
});
const commitLabel = computed(() => {
    if (active.value === 'count') return 'Save count';
    const verb = active.value === 'restock' ? 'Add' : 'Move';
    return validQuantity.value ? `${verb} ${amount.value} ${unit(amount.value)}` : verb;
});

watch([active, () => props.level.id], () => {
    quantity.value = active.value === 'count' ? '' : '1';
    choiceId.value = '';
}, { immediate: true });

function begin(action: LocationStockAction): void {
    if (!props.busy) emit('activate', props.level.id, action);
}

function useOne(): void {
    if (props.busy || props.level.quantity < 1) return;
    emit('submit', props.level.id, { movement_type: 'consumption', item_id: props.level.item.id, location_id: props.level.location.id, quantity: 1 });
}

function submit(): void {
    if (!ready.value) return;
    const item_id = props.level.item.id;
    const location_id = props.level.location.id;
    if (moving.value) {
        const outgoing = active.value === 'move-out';
        emit('submit', props.level.id, { movement_type: 'transfer', item_id, source_location_id: outgoing ? location_id : choiceId.value, destination_location_id: outgoing ? choiceId.value : location_id, quantity: amount.value });
    } else if (active.value === 'count') {
        emit('submit', props.level.id, { movement_type: 'shelf_count', item_id, location_id, observed_quantity: amount.value });
    } else {
        emit('submit', props.level.id, { movement_type: 'restock', item_id, location_id, quantity: amount.value });
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <button v-if="level.quantity > 0" type="button" class="action" :disabled="busy" @click="useOne">Use 1</button>
        <button type="button" class="action" :disabled="busy" @click="begin('restock')">Restock</button>
        <button type="button" class="action min-w-9" :aria-label="'More stock actions for ' + level.item.name" :disabled="busy" @click="begin('menu')"><UiIcon name="more" :size="16" /></button>
        <FormModal v-if="active" :label="title" :busy="busy" @close="emit('close')">
            <div class="p-5">
                <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
                <p class="mb-4 mt-1 text-sm text-ink-muted"><template v-if="active === 'menu'">{{ level.quantity }} {{ unit(level.quantity) }} · </template>{{ path }}</p>
                <template v-if="active === 'menu'">
                    <div class="divide-y divide-line">
                        <button type="button" class="menu-action" @click="begin('count')"><b>Count on this shelf</b><small>Enter the total you see</small></button>
                        <button type="button" class="menu-action" @click="begin('restock')"><b>Restock</b><small>Add {{ unit(2) }} to this shelf</small></button>
                        <button v-if="level.quantity > 0" type="button" class="menu-action" @click="begin('move-out')"><b>Move out</b><small>Transfer from this shelf to another location</small></button>
                        <button type="button" class="menu-action" @click="begin('move-in')"><b>Move in</b><small>Transfer from another location to this shelf</small></button>
                        <RouterLink class="menu-action" :to="itemDestination ?? { name: 'inventory-item', params: { item: level.item.id } }"><b>Open item</b><small>See all stock, notes, and activity</small></RouterLink>
                    </div>
                    <div class="mt-4 text-right"><button type="button" class="action" @click="emit('close')">Close</button></div>
                </template>
                <form v-else class="grid gap-3" :aria-label="title" @submit.prevent="submit">
                    <template v-if="active === 'move-out'">
                        <p class="grid gap-2 text-sm"><b>From</b><span class="fixed-place"><UiIcon name="pin" :size="16" />{{ path }}</span></p>
                        <label :for="`move-out-destination-${level.id}`">Move to</label>
                        <p v-if="locationsLoading" role="status">Loading locations…</p>
                        <p v-else-if="locationsError" role="alert">Locations could not be loaded. <button type="button" class="underline" @click="emit('retryLocations')">Try again</button></p>
                        <p v-else-if="!destinations.length">No other active locations are available.</p>
                        <select v-else :id="`move-out-destination-${level.id}`" v-model="choiceId" required :disabled="busy">
                            <option value="" disabled>Select a destination</option><option v-for="location in destinations" :key="location.id" :value="location.id">{{ buildLocationPath(location, locations) }}</option>
                        </select>
                    </template>
                    <template v-if="active === 'move-in'">
                        <label :for="`move-in-source-${level.id}`">Move from</label>
                        <p v-if="itemDetail.isFetching.value" role="status">Loading item stock…</p>
                        <p v-else-if="itemDetail.isError.value" role="alert">Available stock could not be loaded. <button type="button" class="underline" @click="itemDetail.refetch()">Try again</button></p>
                        <select v-else :id="`move-in-source-${level.id}`" v-model="choiceId" required :disabled="busy || !other.length">
                            <option value="" disabled>{{ other.length ? 'Select a source' : 'No other locations have stock' }}</option>
                            <option v-for="stock in other" :key="stock.id" :value="stock.location.id">{{ buildLocationPath(stock.location, locations) }} ({{ stock.quantity }} {{ unit(stock.quantity) }} available)</option>
                        </select>
                        <p class="grid gap-2 text-sm"><b>To</b><span class="fixed-place"><UiIcon name="pin" :size="16" />{{ path }}</span></p>
                    </template>
                    <label :for="`stock-action-quantity-${level.id}`">{{ qtyLabel }}</label>
                    <div class="quantity"><input :id="`stock-action-quantity-${level.id}`" v-model="quantity" type="number" inputmode="numeric" :min="active === 'count' ? 0 : 1" :max="active === 'move-out' ? level.quantity : active === 'move-in' ? source?.quantity : 4294967295" step="1" :disabled="busy" required><span>{{ unit(amount) }}</span></div>
                    <template v-if="validQuantity">
                        <template v-if="active === 'restock'">
                            <StockImpact label="Shelf quantity" :before="level.quantity" :after="level.quantity + amount" :unit="unit(level.quantity + amount)" />
                            <StockImpact v-if="level.item.total_quantity !== undefined" label="Household total" :before="level.item.total_quantity" :after="level.item.total_quantity + amount" :unit="unit(level.item.total_quantity + amount)" />
                        </template>
                        <template v-else-if="active === 'count'">
                            <StockImpact label="Shelf quantity" :before="level.quantity" :after="amount" :unit="unit(amount)" />
                            <p v-if="other.length === 1 && other[0]" class="text-sm text-ink-muted">{{ other[0].location.name }} keeps its {{ other[0].quantity }} {{ unit(other[0].quantity) }}.</p>
                            <p v-else-if="other.length > 1" class="text-sm text-ink-muted">All {{ other.length }} other stocked locations keep their recorded quantities.</p>
                        </template>
                        <template v-else-if="active === 'move-out' && destination && amount <= level.quantity">
                            <StockImpact :label="path" :before="level.quantity" :after="level.quantity - amount" :unit="unit(level.quantity - amount)" />
                            <StockImpact v-if="itemDetail.isSuccess.value && !itemDetail.isFetching.value" :label="buildLocationPath(destination, locations)" :before="destinationQuantity" :after="destinationQuantity + amount" :unit="unit(destinationQuantity + amount)" />
                            <p v-if="itemDetail.isFetching.value" role="status" class="text-sm text-ink-muted">Loading destination stock…</p>
                            <p v-else-if="itemDetail.isError.value" role="alert" class="text-sm">Destination stock could not be loaded. <button type="button" class="underline" @click="itemDetail.refetch()">Try again</button></p>
                        </template>
                        <template v-else-if="active === 'move-in' && source && amount <= source.quantity">
                            <StockImpact :label="buildLocationPath(source.location, locations)" :before="source.quantity" :after="source.quantity - amount" :unit="unit(source.quantity - amount)" />
                            <StockImpact :label="path" :before="level.quantity" :after="level.quantity + amount" :unit="unit(level.quantity + amount)" />
                        </template>
                    </template>
                    <div v-if="errors.form || Object.keys(errors.fields).length" role="alert" class="text-sm text-rose-700"><p>{{ errors.form }}</p><p v-for="(message, field) in errors.fields" :key="field">{{ message }}</p></div>
                    <div class="mt-2 flex flex-wrap justify-end gap-2 border-t border-line pt-3"><button type="button" class="action" :disabled="busy" @click="emit('close')">Cancel</button><button type="submit" :disabled="!ready" class="commit">{{ busy ? 'Saving…' : commitLabel }}</button></div>
                </form>
            </div>
        </FormModal>
    </div>
</template>

<style scoped>
@reference "../styles.css";
.fixed-place { @apply flex items-center gap-2 rounded-md border border-line bg-surface-soft px-3 py-2; }
.action { @apply inline-flex min-h-9 items-center justify-center rounded-md border border-line bg-white px-3 text-sm font-medium hover:bg-surface-soft; }
.menu-action { @apply block w-full rounded px-1 py-3 text-left text-sm hover:bg-surface-soft; }
.menu-action small { @apply block text-sm text-ink-muted; }
select,.quantity { @apply min-h-11 w-full rounded-md border border-line bg-white px-3 text-sm; }
label { @apply text-sm font-semibold; }
.quantity { @apply flex items-center gap-3; }
.quantity input { @apply min-h-11 w-0 flex-1 font-semibold; }
.quantity span { @apply text-ink-muted; }
.commit { @apply min-h-10 rounded-md bg-sage-dark px-4 text-sm font-semibold text-white; }
button:disabled { @apply opacity-50; }
</style>
