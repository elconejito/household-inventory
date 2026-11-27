<script setup lang="ts">
import { computed } from 'vue';
import type { CatalogInventoryLevel } from '../api/catalog';
import { buildLocationPath, type Location, type ShelfActionResult } from '../api/stock';
import FormModal from './FormModal.vue';
import StockImpact from './StockImpact.vue';

const props = defineProps<{ result: ShelfActionResult; level: CatalogInventoryLevel; locations: Location[] }>();
const emit = defineEmits<{ close: [] }>();
const movement = computed(() => props.result.kind === 'movement' ? props.result.movement : null);
const transfer = computed(() => movement.value?.movement_type === 'transfer');
const title = computed(() => props.result.kind === 'count' ? 'Count saved' : transfer.value ? 'Transfer saved' : 'Restock saved');
const added = computed(() => movement.value?.entries.find((entry) => entry.quantity_delta > 0)?.quantity_delta ?? 0);
const unit = (quantity: number) => quantity === 1 ? props.level.item.counting_unit : props.level.item.counting_unit_plural || props.level.item.counting_unit;
</script>

<template>
    <FormModal :label="title" @close="emit('close')">
        <div class="grid gap-4 p-5">
            <div><h2 class="text-xl font-semibold">{{ title }}</h2><p class="text-sm text-ink-muted">{{ buildLocationPath(level.location, locations) }}</p></div>
            <p class="rounded-md border border-line bg-sage-soft p-3 text-sm text-sage-dark" role="status">
                <template v-if="result.kind === 'count'">{{ level.item.name }} counted at this shelf.</template>
                <template v-else>{{ transfer ? 'Moved' : 'Added' }} {{ added }} {{ unit(added) }} of {{ level.item.name }}.</template>
            </p>
            <StockImpact v-if="result.kind === 'count'" label="Shelf quantity" :before="result.before" :after="result.after" :unit="unit(result.after)" />
            <StockImpact v-for="entry in movement?.entries" :key="entry.id" :label="transfer ? buildLocationPath(entry.location, locations) : 'Shelf quantity'" :before="entry.balance_after - entry.quantity_delta" :after="entry.balance_after" :unit="unit(entry.balance_after)" />
            <StockImpact v-if="movement && !transfer && movement.item.total_quantity !== undefined" label="Household total" :before="movement.item.total_quantity - added" :after="movement.item.total_quantity" :unit="unit(movement.item.total_quantity)" />
            <div class="text-right"><button type="button" class="min-h-10 rounded-md bg-sage-dark px-4 text-sm font-semibold text-white" @click="emit('close')">Done</button></div>
        </div>
    </FormModal>
</template>
