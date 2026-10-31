<script setup lang="ts">
import { computed, useId } from 'vue';
import type { InventoryItem, ItemLocation } from '../api/items';
import { buildLocationPath, type Location } from '../api/stock';
import { getMonitoredAttentionLevels } from '../lib/stock-indicators';
import StockStatusBadge from './StockStatusBadge.vue';

const props = withDefaults(defineProps<{
    item: InventoryItem;
    locations: Location[];
    exactLocationId?: string;
}>(), { exactLocationId: '' });

const locationsPopoverId = useId();

const exactLocation = computed(() => props.locations.find((location) => location.id === props.exactLocationId)
    ?? props.item.inventory_levels?.find((level) => level.location.id === props.exactLocationId)?.location);
const exactLocationName = computed(() => exactLocation.value ? buildLocationPath(exactLocation.value, props.locations) : 'selected location');
const exactLocationQuantity = computed(() => (props.item.inventory_levels ?? [])
    .filter((level) => level.location.id === props.exactLocationId)
    .reduce((total, level) => total + level.quantity, 0));
const positiveLevels = computed(() => (props.item.inventory_levels ?? []).filter((level) => level.quantity > 0));
const attentionLevels = computed(() => getMonitoredAttentionLevels(props.item.inventory_levels ?? []));

function locationPath(location: ItemLocation): string {
    return buildLocationPath(location, props.locations);
}

function unitFor(quantity: number): string {
    return quantity === 1 ? props.item.counting_unit : props.item.counting_unit_plural ?? props.item.counting_unit;
}
</script>

<template>
    <div class="w-full min-w-0 text-sm font-semibold text-ink sm:pt-0.5">
        <div class="flex items-center gap-2">
            <p>{{ item.total_quantity ?? 0 }} {{ unitFor(item.total_quantity ?? 0) }} <span class="font-normal text-ink-muted">total on hand</span></p>
            <button v-if="positiveLevels.length" type="button" :popovertarget="locationsPopoverId" :aria-label="`View locations for ${item.name}`" class="inline-flex min-h-9 shrink-0 items-center rounded-md border border-line px-2 text-xs font-medium text-sage-dark hover:bg-sage-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Locations</button>
        </div>
        <p v-if="exactLocationId" class="mt-1 break-words text-xs font-normal text-ink-muted">At {{ exactLocationName }}: {{ exactLocationQuantity }} {{ unitFor(exactLocationQuantity) }}</p>
        <div v-if="attentionLevels.length" class="mt-2 flex min-w-0 flex-wrap gap-1.5">
            <StockStatusBadge v-for="{ level, indicator } in attentionLevels" :key="level.id" :label="`${indicator.label} · ${locationPath(level.location)}`" :tone="indicator.tone" />
        </div>
        <div v-if="positiveLevels.length" :id="locationsPopoverId" popover :aria-labelledby="`${locationsPopoverId}-heading`" class="fixed inset-0 m-auto max-h-[80dvh] w-[calc(100%-2rem)] max-w-sm overflow-y-auto rounded-panel border border-line bg-white p-5 text-sm text-ink shadow-card backdrop:bg-ink/20">
            <div class="flex items-start justify-between gap-4">
                <h4 :id="`${locationsPopoverId}-heading`" class="font-semibold">{{ item.name }} — locations</h4>
                <button type="button" :popovertarget="locationsPopoverId" popovertargetaction="hide" class="min-h-9 shrink-0 rounded-md border border-line px-2 text-xs font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Close</button>
            </div>
            <ul class="mt-3 grid min-w-0 gap-2 font-normal text-ink-muted">
                <li v-for="level in positiveLevels" :key="level.id" class="break-words">{{ locationPath(level.location) }} — {{ level.quantity }} {{ unitFor(level.quantity) }}</li>
            </ul>
        </div>
    </div>
</template>
