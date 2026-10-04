<script setup lang="ts">
import { ref } from 'vue';
import { buildLocationPath, type InventoryMovement, type Location } from '../api/stock';
import NotesPanel from './NotesPanel.vue';

withDefaults(defineProps<{
    movements: InventoryMovement[];
    locations?: Location[];
    showItemName?: boolean;
}>(), { locations: () => [], showItemName: true });

const openNoteContexts = ref(new Set<string>());

function movementLabel(type: string): string {
    const labels: Record<string, string> = { restock: 'Restock', consumption: 'Consumption', transfer: 'Transfer', correction: 'Correction', disposal: 'Disposal' };
    return labels[type] ?? type;
}

function unitFor(movement: InventoryMovement, quantity: number): string {
    return Math.abs(quantity) === 1 ? movement.item.counting_unit : movement.item.counting_unit_plural ?? movement.item.counting_unit;
}

function timestamp(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function locationPath(location: Location, locations: Location[]): string {
    return buildLocationPath(location, locations);
}

function toggleNotes(id: string, event: Event): void {
    const updated = new Set(openNoteContexts.value);
    if ((event.currentTarget as HTMLDetailsElement).open) updated.add(id);
    else updated.delete(id);
    openNoteContexts.value = updated;
}
</script>

<template>
    <ol class="divide-y divide-line">
        <li v-for="movement in movements" :key="movement.id" class="px-5 py-4 sm:px-6">
            <article>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h3 class="min-w-0 max-w-full break-words font-semibold text-ink"><template v-if="showItemName">{{ movement.item.name }} <span class="font-normal text-ink-muted">· </span></template><span class="font-normal text-ink-muted">{{ movementLabel(movement.movement_type) }}</span></h3><time class="text-sm text-ink-muted" :datetime="movement.recorded_at">{{ timestamp(movement.recorded_at) }}</time></div>
                <ul class="mt-2 grid gap-1 text-sm text-ink-muted sm:grid-cols-2">
                    <li v-for="entry in movement.entries" :key="entry.id" class="flex min-w-0 flex-wrap items-center gap-x-2"><span class="break-words font-medium text-ink">{{ locationPath(entry.location, locations) }}</span><span>{{ entry.quantity_delta > 0 ? '+' : '' }}{{ entry.quantity_delta }} {{ unitFor(movement, entry.quantity_delta) }}</span><span>· {{ entry.balance_after }} after</span></li>
                </ul>
                <p v-if="movement.recorded_by" class="mt-2 break-words text-xs text-ink-muted">Recorded by {{ movement.recorded_by.name }}</p>
                <details class="mt-3" @toggle="toggleNotes(movement.id, $event)"><summary class="w-fit cursor-pointer text-sm font-medium text-sage-dark">Notes</summary><NotesPanel v-if="openNoteContexts.has(movement.id)" class="mt-3" type="inventory-movements" :context-id="movement.id" /></details>
            </article>
        </li>
    </ol>
</template>
