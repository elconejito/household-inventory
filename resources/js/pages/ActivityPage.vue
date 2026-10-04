<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { parseApiErrors } from '../lib/api-errors';
import { useAllItemsQuery, useLocationsQuery, useMovementRecordersQuery, useMovementsQuery } from '../queries/stock';
import { buildLocationPath, type Location } from '../api/stock';
import MovementTimeline from '../components/MovementTimeline.vue';

const route = useRoute();
const router = useRouter();
const pageSizes = [10, 25, 50, 100] as const;
const movementTypes = ['restock', 'consumption', 'transfer', 'correction', 'disposal'];
const movementLabels: Record<string, string> = { restock: 'Restock', consumption: 'Consumption', transfer: 'Transfer', correction: 'Correction', disposal: 'Disposal' };
const state = reactive({ itemId: '', locationId: '', locationMode: 'either' as 'either' | 'from' | 'to', movementType: '', recordedBy: '', recordedFrom: '', recordedUntil: '', perPage: 10, page: 1 });
const locationsQuery = useLocationsQuery();
const itemList = useAllItemsQuery();
const recordersQuery = useMovementRecordersQuery();
let syncingFromRoute = false;

function queryString(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function validId(value: unknown): string {
    const id = queryString(value);
    const parsed = Number(id);
    return /^[1-9]\d*$/.test(id) && Number.isSafeInteger(parsed) ? id : '';
}

function validDate(value: unknown): string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? value : '';
}

function integer(value: unknown, fallback: number, allowed?: readonly number[]): number {
    if (typeof value !== 'string' || !/^\d+$/.test(value)) return fallback;
    const parsed = Number(value);
    if (!Number.isSafeInteger(parsed) || parsed < 1) return fallback;
    return allowed && !allowed.includes(parsed) ? fallback : parsed;
}

function applyRouteQuery(): void {
    const query = route.query;
    syncingFromRoute = true;
    Object.assign(state, {
        itemId: validId(query.item_id), locationId: validId(query.location_id),
        locationMode: ['either', 'from', 'to'].includes(queryString(query.location_mode)) ? queryString(query.location_mode) : 'either',
        movementType: movementTypes.includes(queryString(query.movement_type)) ? queryString(query.movement_type) : '',
        recordedBy: validId(query.recorded_by), recordedFrom: validDate(query.recorded_from), recordedUntil: validDate(query.recorded_until),
        perPage: integer(query.per_page, 10, pageSizes), page: integer(query.page, 1),
    });
    queueMicrotask(() => { syncingFromRoute = false; });
}
applyRouteQuery();
watch(() => route.query, applyRouteQuery);

const filters = computed(() => ({
    page: state.page, perPage: state.perPage, itemId: state.itemId, locationId: state.locationId,
    locationMode: state.locationMode, movementType: state.movementType, recordedBy: state.recordedBy,
    recordedFrom: state.recordedFrom ? localDayBoundary(state.recordedFrom, false) : '',
    recordedUntil: state.recordedUntil ? localDayBoundary(state.recordedUntil, true) : '',
}));
const dateRangeValid = computed(() => !state.recordedFrom || !state.recordedUntil || state.recordedFrom <= state.recordedUntil);
const movementsQuery = useMovementsQuery(filters, dateRangeValid);

function localDayBoundary(day: string, end: boolean): string {
    const [year, month, date] = day.split('-').map(Number);
    const localDate = new Date(year, month - 1, date);
    if (end) {
        localDate.setDate(localDate.getDate() + 1);
        localDate.setMilliseconds(localDate.getMilliseconds() - 1);
    }
    return localDate.toISOString();
}

const filterState = computed(() => JSON.stringify({ ...state }));
watch(filterState, async () => {
    if (syncingFromRoute) return;
    const query = {
        ...(state.itemId ? { item_id: state.itemId } : {}), ...(state.locationId ? { location_id: state.locationId } : {}),
        ...(state.locationMode !== 'either' ? { location_mode: state.locationMode } : {}), ...(state.movementType ? { movement_type: state.movementType } : {}),
        ...(state.recordedBy ? { recorded_by: state.recordedBy } : {}), ...(state.recordedFrom ? { recorded_from: state.recordedFrom } : {}),
        ...(state.recordedUntil ? { recorded_until: state.recordedUntil } : {}), per_page: String(state.perPage), ...(state.page > 1 ? { page: String(state.page) } : {}),
    };
    const serialized = JSON.stringify(query);
    const current = JSON.stringify(Object.fromEntries(Object.entries(route.query).filter(([, value]) => typeof value === 'string')));
    if (serialized !== current) await router.push({ query });
});

watch(() => [state.itemId, state.locationId, state.locationMode, state.movementType, state.recordedBy, state.recordedFrom, state.recordedUntil, state.perPage], () => {
    if (!syncingFromRoute) state.page = 1;
});

watch(() => movementsQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && state.page > lastPage) state.page = lastPage;
});

function locationPath(location: Location): string {
    return buildLocationPath(location, locationsQuery.data.value ?? []);
}

function setFilter<Key extends keyof typeof state>(key: Key, value: typeof state[Key]): void {
    state[key] = value;
    state.page = 1;
}

function retry(): void { void movementsQuery.refetch(); }
</script>

<template>
    <section aria-labelledby="page-title">
        <p class="eyebrow">Changes over time</p>
        <h1 id="page-title" class="page-title mt-2">Activity</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">A record of stock changes across your household.</p>

        <section class="mt-6 rounded-panel border border-line bg-white p-4 shadow-card sm:p-5" aria-label="Filter activity">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid min-w-0 gap-2"><label for="activity-item" class="text-sm font-medium text-ink">Item</label><select id="activity-item" :value="state.itemId" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('itemId', ($event.target as HTMLSelectElement).value)"><option value="">All items</option><option v-for="item in itemList.data.value ?? []" :key="item.id" :value="item.id">{{ item.name }}</option></select><p v-if="itemList.isPending.value" role="status" class="text-xs text-ink-muted">Loading items…</p><p v-else-if="itemList.isError.value" role="alert" class="text-xs text-red-700">Items could not be loaded. <button type="button" class="underline" @click="itemList.refetch()">Retry</button></p></div>
                <div class="grid min-w-0 gap-2"><label for="activity-location" class="text-sm font-medium text-ink">Location</label><select id="activity-location" :value="state.locationId" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('locationId', ($event.target as HTMLSelectElement).value)"><option value="">All locations</option><option v-for="location in locationsQuery.data.value ?? []" :key="location.id" :value="location.id">{{ locationPath(location) }}</option></select><p v-if="locationsQuery.isPending.value" role="status" class="text-xs text-ink-muted">Loading locations…</p><p v-else-if="locationsQuery.isError.value" role="alert" class="text-xs text-red-700">Locations could not be loaded. <button type="button" class="underline" @click="locationsQuery.refetch()">Retry</button></p></div>
                <div class="grid min-w-0 gap-2"><label for="activity-location-mode" class="text-sm font-medium text-ink">Location role</label><select id="activity-location-mode" :value="state.locationMode" class="min-h-11 w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('locationMode', ($event.target as HTMLSelectElement).value as typeof state.locationMode)"><option value="either">Either location</option><option value="from">From</option><option value="to">To</option></select></div>
                <div class="grid min-w-0 gap-2"><label for="activity-type" class="text-sm font-medium text-ink">Change type</label><select id="activity-type" :value="state.movementType" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('movementType', ($event.target as HTMLSelectElement).value)"><option value="">All changes</option><option v-for="type in movementTypes" :key="type" :value="type">{{ movementLabels[type] }}</option></select></div>
                <div class="grid min-w-0 gap-2"><label for="activity-recorder" class="text-sm font-medium text-ink">Recorded by</label><select id="activity-recorder" :value="state.recordedBy" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('recordedBy', ($event.target as HTMLSelectElement).value)"><option value="">Anyone</option><option v-for="recorder in recordersQuery.data.value ?? []" :key="recorder.id" :value="recorder.id">{{ recorder.name }}</option></select><p v-if="recordersQuery.isPending.value" role="status" class="text-xs text-ink-muted">Loading people…</p><p v-else-if="recordersQuery.isError.value" role="alert" class="text-xs text-red-700">People could not be loaded. <button type="button" class="underline" @click="recordersQuery.refetch()">Retry</button></p></div>
                <div class="grid min-w-0 gap-2"><label for="activity-from" class="text-sm font-medium text-ink">From date</label><input id="activity-from" type="date" :value="state.recordedFrom" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('recordedFrom', ($event.target as HTMLInputElement).value)"></div>
                <div class="grid min-w-0 gap-2"><label for="activity-until" class="text-sm font-medium text-ink">Until date</label><input id="activity-until" type="date" :value="state.recordedUntil" class="min-h-11 w-full min-w-0 max-w-full rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('recordedUntil', ($event.target as HTMLInputElement).value)"></div>
                <div class="grid min-w-0 gap-2"><label for="activity-page-size" class="text-sm font-medium text-ink">Movements per page</label><select id="activity-page-size" :value="state.perPage" class="min-h-11 rounded-md border border-line bg-white px-3 text-sm" @change="setFilter('perPage', Number(($event.target as HTMLSelectElement).value))"><option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option></select></div>
            </div>
        </section>

        <p v-if="!dateRangeValid" class="mt-4 text-sm text-red-700" role="alert">The start date must be on or before the end date.</p>
        <section class="mt-6 rounded-panel border border-line bg-white shadow-card" aria-label="Inventory activity" :aria-busy="movementsQuery.isFetching.value">
            <div v-if="!dateRangeValid" class="grid min-h-48 place-items-center p-6 text-center text-sm text-ink-muted">Adjust the date range to view activity.</div>
            <div v-else-if="movementsQuery.isPending.value" class="grid min-h-48 place-items-center p-6 text-sm text-ink-muted" role="status">Loading activity…</div>
            <div v-else-if="movementsQuery.isError.value" class="p-6" role="alert"><p class="font-semibold text-ink">Activity could not be loaded</p><p class="mt-1 text-sm text-ink-muted">{{ parseApiErrors(movementsQuery.error.value).form || 'Try again in a moment.' }}</p><button type="button" class="mt-3 rounded-md border border-line px-4 py-2 text-sm" @click="retry">Try again</button></div>
            <div v-else-if="!movementsQuery.data.value?.data.length" class="grid min-h-48 place-items-center p-6 text-center"><div><h2 class="font-semibold text-ink">No changes found</h2><p class="mt-1 text-sm text-ink-muted">When stock is added, moved, or used, it will appear here.</p></div></div>
            <template v-else>
                <MovementTimeline :movements="movementsQuery.data.value.data" :locations="locationsQuery.data.value ?? []" />
                <div v-if="movementsQuery.data.value.meta.last_page > 1" class="flex items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6"><p class="text-sm text-ink-muted">Page {{ movementsQuery.data.value.meta.current_page }} of {{ movementsQuery.data.value.meta.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="state.page <= 1 || movementsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="state.page--">Previous</button><button type="button" :disabled="state.page >= movementsQuery.data.value.meta.last_page || movementsQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="state.page++">Next</button></div></div>
            </template>
        </section>
    </section>
</template>
