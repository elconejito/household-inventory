<script setup lang="ts">
import { computed } from 'vue';
import type { InventoryItem, ItemLocation } from '../api/items';
import { buildLocationPath, type Location } from '../api/stock';
import { getMonitoredAttentionLevels } from '../lib/stock-indicators';
import StockStatusBadge from './StockStatusBadge.vue';

const props = withDefaults(defineProps<{
    item: InventoryItem;
    locations: Location[];
    exactLocationId?: string;
}>(), { exactLocationId: '' });

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
        <p>{{ item.total_quantity ?? 0 }} {{ unitFor(item.total_quantity ?? 0) }} <span class="font-normal text-ink-muted">total on hand</span></p>
        <p v-if="exactLocationId" class="mt-1 break-words text-xs font-normal text-ink-muted">At {{ exactLocationName }}: {{ exactLocationQuantity }} {{ unitFor(exactLocationQuantity) }}</p>
        <div v-if="attentionLevels.length" class="mt-2 flex min-w-0 flex-wrap gap-1.5">
            <StockStatusBadge v-for="{ level, indicator } in attentionLevels" :key="level.id" :label="`${indicator.label} · ${locationPath(level.location)}`" :tone="indicator.tone" />
        </div>
        <ul v-if="positiveLevels.length" class="mt-2 grid min-w-0 gap-1 text-xs font-normal text-ink-muted">
            <li v-for="level in positiveLevels" :key="level.id" class="break-words">{{ locationPath(level.location) }} — {{ level.quantity }} {{ unitFor(level.quantity) }}</li>
        </ul>
    </div>
</template>
