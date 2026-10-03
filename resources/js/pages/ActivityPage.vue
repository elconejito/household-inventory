<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { parseApiErrors } from '../lib/api-errors';
import { useAllItemsQuery, useLocationsQuery, useMovementsQuery } from '../queries/stock';
import { buildLocationPath, type Location } from '../api/stock';
import NotesPanel from '../components/NotesPanel.vue';

const page = ref(1);
const itemId = ref('');
const locationId = ref('');
const locationMode = ref<'either' | 'from' | 'to'>('either');
const movementType = ref('');
const openNoteContexts = ref(new Set<string>());
const locationsQuery = useLocationsQuery();
const itemList = useAllItemsQuery();
const filters = computed(() => ({ page: page.value, perPage: 10, itemId: itemId.value, locationId: locationId.value, locationMode: locationMode.value, movementType: movementType.value }));
const movementsQuery = useMovementsQuery(filters);
watch([itemId, locationId, locationMode, movementType], () => { page.value = 1; });

function locationPath(location: Location): string {
    return buildLocationPath(location, locationsQuery.data.value ?? []);
}

function movementLabel(type: string): string {
    const labels: Record<string, string> = { restock: 'Restock', consumption: 'Consumption', transfer: 'Transfer', correction: 'Correction', disposal: 'Disposal' };
    return labels[type] ?? type;
}

function unitFor(movement: { item: { counting_unit: string; counting_unit_plural?: string } }, quantity: number): string {
    return Math.abs(quantity) === 1 ? movement.item.counting_unit : movement.item.counting_unit_plural ?? movement.item.counting_unit;
}

function timestamp(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function retry(): void {
    void movementsQuery.refetch();
}

function toggleNotes(id: string, event: Event): void {
    const updated = new Set(openNoteContexts.value);
    if ((event.currentTarget as HTMLDetailsElement).open) updated.add(id);
    else updated.delete(id);
    openNoteContexts.value = updated;
}
</script>

<template>
    <section aria-labelledby="page-title">
        <p class="eyebrow">Changes over time</p>
        <h1 id="page-title" class="page-title mt-2">Activity</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">A record of stock changes across your household.</p>

        <section class="mt-6 rounded-panel border border-line bg-white p-4 shadow-card sm:p-5" aria-label="Filter activity">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid min-w-0 gap-2"><label for="activity-item" class="text-sm font-medium text-ink">Item</label><select id="activity-item" v-model="itemId" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="">All items</option><option v-for="item in itemList.data.value ?? []" :key="item.id" :value="item.id">{{ item.name }}</option></select></div>
                <div class="grid min-w-0 gap-2"><label for="activity-location" class="text-sm font-medium text-ink">Location</label><select id="activity-location" v-model="locationId" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="">All locations</option><option v-for="location in locationsQuery.data.value ?? []" :key="location.id" :value="location.id">{{ locationPath(location) }}</option></select></div>
                <div class="grid min-w-0 gap-2"><label for="activity-location-mode" class="text-sm font-medium text-ink">Location role</label><select id="activity-location-mode" v-model="locationMode" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="either">Either location</option><option value="from">From</option><option value="to">To</option></select></div>
                <div class="grid min-w-0 gap-2"><label for="activity-type" class="text-sm font-medium text-ink">Change type</label><select id="activity-type" v-model="movementType" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm"><option value="">All changes</option><option value="restock">Restock</option><option value="consumption">Consumption</option><option value="transfer">Transfer</option><option value="correction">Correction</option><option value="disposal">Disposal</option></select></div>
            </div>
        </section>

        <section class="mt-6 rounded-panel border border-line bg-white shadow-card" aria-label="Inventory activity" :aria-busy="movementsQuery.isFetching.value">
            <div v-if="movementsQuery.isPending.value" class="grid min-h-48 place-items-center p-6 text-sm text-ink-muted" role="status">Loading activity…</div>
            <div v-else-if="movementsQuery.isError.value" class="p-6" role="alert"><p class="font-semibold text-ink">Activity could not be loaded</p><p class="mt-1 text-sm text-ink-muted">{{ parseApiErrors(movementsQuery.error.value).form || 'Try again in a moment.' }}</p><button type="button" class="mt-3 rounded-md border border-line px-4 py-2 text-sm" @click="retry">Try again</button></div>
            <div v-else-if="!movementsQuery.data.value?.data.length" class="grid min-h-48 place-items-center p-6 text-center"><div><h2 class="font-semibold text-ink">No changes found</h2><p class="mt-1 text-sm text-ink-muted">When stock is added, moved, or used, it will appear here.</p></div></div>
            <template v-else>
                <ol class="divide-y divide-line">
                    <li v-for="movement in movementsQuery.data.value.data" :key="movement.id" class="px-5 py-4 sm:px-6">
                        <article>
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 class="font-semibold text-ink">{{ movement.item.name }} <span class="font-normal text-ink-muted">· {{ movementLabel(movement.movement_type) }}</span></h2><time class="text-sm text-ink-muted" :datetime="movement.recorded_at">{{ timestamp(movement.recorded_at) }}</time></div>
                            <ul class="mt-2 grid gap-1 text-sm text-ink-muted sm:grid-cols-2">
                                <li v-for="entry in movement.entries" :key="entry.id" class="flex flex-wrap items-center gap-x-2"><span class="font-medium text-ink">{{ locationPath(entry.location) }}</span><span>{{ entry.quantity_delta > 0 ? '+' : '' }}{{ entry.quantity_delta }} {{ unitFor(movement, entry.quantity_delta) }}</span><span>· {{ entry.balance_after }} after</span></li>
                            </ul>
                            <p v-if="movement.recorded_by" class="mt-2 text-xs text-ink-muted">Recorded by {{ movement.recorded_by.name }}</p>
                            <details class="mt-3" @toggle="toggleNotes(movement.id, $event)"><summary class="w-fit cursor-pointer text-sm font-medium text-sage-dark">Notes</summary><NotesPanel v-if="openNoteContexts.has(movement.id)" class="mt-3" type="inventory-movements" :context-id="movement.id" /></details>
                        </article>
                    </li>
                </ol>
                <div v-if="movementsQuery.data.value.meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6"><p class="text-sm text-ink-muted">Page {{ movementsQuery.data.value.meta.current_page }} of {{ movementsQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="page <= 1 || movementsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="page--">Previous</button><button type="button" :disabled="page >= movementsQuery.data.value.meta.last_page || movementsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="page++">Next</button></div></div>
            </template>
        </section>
    </section>
</template>
