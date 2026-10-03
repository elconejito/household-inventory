<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { getItems, type InventoryItem } from '../api/items';
import { buildLocationPath, getActiveAlerts, type InventoryLevel, type InventoryAlert } from '../api/stock';
import { parseApiErrors } from '../lib/api-errors';
import { useActiveAlertsQuery, useBuySoonMutation, useLocationsQuery, useRecordMovementMutation, useResolveAlertMutation, useTriggeredLevelsQuery } from '../queries/stock';

const pageSize = 10;
const search = ref('');
const debouncedSearch = ref('');
const resultsPage = ref(1);
const emptyPage = ref(1);
const lowPage = ref(1);
const buySoonPage = ref(1);
const selectedItem = ref<InventoryItem | null>(null);
const action = ref<'consume' | 'restock' | null>(null);
const selectedLocationId = ref('');
const quantity = ref('1');
const confirmation = ref(false);
const actionError = ref('');
const success = ref('');
let searchTimeout: ReturnType<typeof setTimeout> | undefined;

const itemParams = computed(() => ({ search: debouncedSearch.value, page: resultsPage.value, perPage: pageSize }));
const emptyParams = computed(() => ({ page: emptyPage.value, perPage: pageSize }));
const lowParams = computed(() => ({ page: lowPage.value, perPage: pageSize }));
const buySoonParams = computed(() => ({ page: buySoonPage.value, perPage: pageSize }));
const itemQuery = useQuery({
    queryKey: computed(() => ['items', 'dashboard-search', itemParams.value]),
    queryFn: () => getItems(itemParams.value, 'inventory_levels.location'),
    enabled: computed(() => debouncedSearch.value.length > 0),
});
const emptyQuery = useTriggeredLevelsQuery(emptyParams, 'empty');
const lowQuery = useTriggeredLevelsQuery(lowParams, 'low');
const activeAlertQuery = useActiveAlertsQuery(buySoonParams);
const locationsQuery = useLocationsQuery();
const movementMutation = useRecordMovementMutation();
const buySoonMutation = useBuySoonMutation();
const resolveMutation = useResolveAlertMutation();
const itemIds = computed(() => (itemQuery.data.value?.data ?? []).map((item) => item.id));
const itemAlertQuery = useQuery({
    queryKey: computed(() => ['inventory-alerts', 'active-by-visible-item', itemIds.value]),
    enabled: computed(() => itemIds.value.length > 0),
    queryFn: async () => {
        const results = await Promise.all(itemIds.value.map((id) => getItemsActiveAlert(id)));
        return new Map(results.filter((alert): alert is InventoryAlert => alert !== null).map((alert) => [alert.item.id, alert]));
    },
});

async function getItemsActiveAlert(itemId: string): Promise<InventoryAlert | null> {
    const response = await getActiveAlerts({ page: 1, perPage: pageSize }, itemId);
    return response.data[0] ?? null;
}

const levels = computed(() => (selectedItem.value?.inventory_levels ?? []) as InventoryLevel[]);
const positiveLevels = computed(() => levels.value.filter((level) => level.quantity > 0));
const locationOptions = computed(() => (locationsQuery.data.value ?? []).map((location) => ({
    location,
    path: buildLocationPath(location, locationsQuery.data.value ?? []),
})));
const actionLocationOptions = computed(() => action.value === 'consume'
    ? positiveLevels.value.map((level) => ({
        location: level.location,
        path: buildLocationPath(level.location, locationsQuery.data.value ?? []),
        quantity: level.quantity,
    }))
    : locationOptions.value.map((option) => ({ ...option, quantity: null as number | null })));
const pending = computed(() => movementMutation.isPending.value || buySoonMutation.isPending.value || resolveMutation.isPending.value);
const emptyLevels = computed(() => emptyQuery.data.value?.data ?? []);
const lowLevels = computed(() => lowQuery.data.value?.data ?? []);

watch(() => emptyQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && emptyPage.value > lastPage) emptyPage.value = lastPage;
});
watch(() => lowQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && lowPage.value > lastPage) lowPage.value = lastPage;
});
watch(() => activeAlertQuery.data.value?.meta.last_page, (lastPage) => {
    if (lastPage && buySoonPage.value > lastPage) buySoonPage.value = lastPage;
});

watch(search, (value) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        resultsPage.value = 1;
        debouncedSearch.value = value.trim();
    }, 300);
});

onUnmounted(() => { if (searchTimeout) clearTimeout(searchTimeout); });

function unitFor(count: number): string {
    const unit = selectedItem.value?.counting_unit ?? 'item';
    return count === 1 ? unit : selectedItem.value?.counting_unit_plural ?? unit;
}

function totalFor(item: InventoryItem): number {
    return item.total_quantity ?? item.inventory_levels?.reduce((total, level) => total + level.quantity, 0) ?? 0;
}

function openAction(item: NonNullable<typeof selectedItem.value>, nextAction: 'consume' | 'restock'): void {
    if (pending.value) return;
    selectedItem.value = item;
    action.value = nextAction;
    selectedLocationId.value = '';
    quantity.value = '1';
    confirmation.value = false;
    actionError.value = '';
    success.value = '';
}

function cancelAction(): void {
    selectedItem.value = null;
    action.value = null;
    confirmation.value = false;
    actionError.value = '';
}

function chosenLocation(): string {
    return locationOptions.value.find(({ location }) => location.id === selectedLocationId.value)?.path ?? 'the selected location';
}

async function submitMovement(): Promise<void> {
    if (pending.value) return;
    actionError.value = '';
    const item = selectedItem.value;
    if (!item || !action.value || !selectedLocationId.value || !confirmation.value) return;
    try {
        await movementMutation.mutateAsync({
            movement_type: action.value === 'consume' ? 'consumption' : 'restock',
            item_id: item.id,
            location_id: selectedLocationId.value,
            quantity: Number(quantity.value),
        });
        success.value = action.value === 'consume' ? `Used 1 ${unitFor(1)} from ${chosenLocation()}.` : `Restocked ${quantity.value} ${unitFor(Number(quantity.value))} at ${chosenLocation()}.`;
        cancelAction();
    } catch (error) {
        const parsedErrors = parseApiErrors(error);
        actionError.value = parsedErrors.form || Object.values(parsedErrors.fields).join(' ') || 'The stock change could not be saved.';
    }
}

async function toggleBuySoon(item: NonNullable<typeof selectedItem.value>): Promise<void> {
    if (pending.value) return;
    actionError.value = '';
    try {
        const existing = itemAlertQuery.data.value?.get(item.id);
        if (existing) await resolveMutation.mutateAsync(existing.id);
        else await buySoonMutation.mutateAsync(item.id);
    } catch (error) {
        actionError.value = parseApiErrors(error).form || 'The Buy soon status could not be updated.';
    }
}

async function resolveBuySoon(alertId: string): Promise<void> {
    if (pending.value) return;
    actionError.value = '';
    try {
        await resolveMutation.mutateAsync(alertId);
    } catch (error) {
        actionError.value = parseApiErrors(error).form || 'The Buy soon reminder could not be resolved.';
    }
}

function retryQueries(): void {
    if (debouncedSearch.value.length > 0) void itemQuery.refetch();
    void emptyQuery.refetch();
    void lowQuery.refetch();
    void activeAlertQuery.refetch();
}

function changePage(which: 'results' | 'empty' | 'low' | 'buySoon', delta: number): void {
    const refs = { results: resultsPage, empty: emptyPage, low: lowPage, buySoon: buySoonPage };
    const query = { results: itemQuery, empty: emptyQuery, low: lowQuery, buySoon: activeAlertQuery }[which];
    refs[which].value = Math.max(1, Math.min(query.data.value?.meta.last_page ?? 1, refs[which].value + delta));
}

</script>

<template>
    <section aria-labelledby="page-title">
        <p class="eyebrow">Your home</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 id="page-title" class="page-title">Dashboard</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted sm:text-base">
                    Find a supply or see what needs attention.
                </p>
            </div>
            <RouterLink
                :to="{ name: 'inventory', query: { create: '1' } }"
                class="inline-flex min-h-11 items-center rounded-md bg-sage px-4 text-sm font-semibold text-white hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage"
            >
                ＋ Add item
            </RouterLink>
        </div>

        <p v-if="success" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ success }}</p>
        <p v-if="actionError" class="mt-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ actionError }}</p>

        <section class="mt-7 rounded-panel border border-line bg-white shadow-card" aria-labelledby="finder-title">
            <div class="border-b border-line p-5 sm:p-6">
                <h2 id="finder-title" class="text-base font-semibold text-ink">Find an item</h2>
                <label for="dashboard-search" class="mt-3 block text-sm text-ink-muted">Find an item</label>
                <input
                    id="dashboard-search"
                    v-model="search"
                    type="search"
                    autocomplete="off"
                    placeholder="Search your supplies…"
                    class="mt-2 min-h-12 w-full rounded-md border border-line bg-white px-4 text-base text-ink outline-none placeholder:text-ink-muted/70 focus:border-sage focus:ring-2 focus:ring-sage/20"
                >
            </div>
            <div v-if="itemQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">
                Search results could not be loaded.
                <button type="button" class="underline" @click="itemQuery.refetch()">Try again</button>
            </div>
            <div v-if="locationsQuery.isError.value" class="px-5 pb-4 text-sm text-rose-700" role="alert">
                Location paths could not be loaded.
                <button type="button" class="underline" @click="locationsQuery.refetch()">Try again</button>
            </div>
            <div v-else-if="debouncedSearch.length === 0" class="p-5 text-sm text-ink-muted">Type to search. Your complete inventory stays in Inventory.</div>
            <div v-else-if="itemQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Searching…</div>
            <div v-else-if="!itemQuery.data.value?.data.length" class="p-5 text-sm text-ink-muted">No items match “{{ debouncedSearch }}”.</div>
            <ul v-else class="divide-y divide-line" :aria-busy="itemQuery.isFetching.value">
                <li v-for="item in itemQuery.data.value.data" :key="item.id" class="grid gap-3 p-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <RouterLink
                            :to="{ name: 'inventory-item', params: { item: item.id } }"
                            class="font-semibold text-ink hover:text-sage-dark hover:underline"
                        >
                            {{ item.name }}
                        </RouterLink>
                        <p class="mt-1 text-sm text-ink-muted">
                            {{ totalFor(item) }} {{ totalFor(item) === 1 ? item.counting_unit : item.counting_unit_plural ?? item.counting_unit }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-ink-muted">
                            {{ item.inventory_levels?.filter((level) => level.quantity > 0).map((level) => `${buildLocationPath(level.location, locationsQuery.data.value ?? [])} ${level.quantity}`).join(' · ') || 'No stock recorded' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="pending || !(item.inventory_levels ?? []).some((level) => level.quantity > 0)"
                            class="min-h-10 rounded-md border border-line px-3 text-sm font-medium disabled:opacity-50"
                            @click="openAction(item, 'consume')"
                        >
                            Consume 1
                        </button>
                        <button
                            type="button"
                            :disabled="pending"
                            class="min-h-10 rounded-md border border-line px-3 text-sm font-medium"
                            @click="openAction(item, 'restock')"
                        >
                            Restock
                        </button>
                        <button
                            v-if="!itemAlertQuery.data.value?.has(item.id)"
                            type="button"
                            :disabled="pending || itemAlertQuery.isPending.value || itemAlertQuery.isError.value"
                            class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-sage-dark disabled:opacity-50"
                            @click="toggleBuySoon(item)"
                        >
                            Buy soon
                        </button>
                        <span v-else class="inline-flex min-h-10 items-center rounded-md bg-sage-soft px-3 text-sm font-medium text-sage-dark">
                            Buy soon · active
                        </span>
                        <button
                            v-if="itemAlertQuery.data.value?.has(item.id)"
                            type="button"
                            :disabled="pending"
                            class="min-h-10 rounded-md border border-line px-3 text-sm font-medium disabled:opacity-50"
                            @click="toggleBuySoon(item)"
                        >
                            Resolve
                        </button>
                        <RouterLink
                            :to="{ name: 'inventory-item', params: { item: item.id } }"
                            class="inline-flex min-h-10 items-center rounded-md px-3 text-sm font-medium text-sage-dark hover:bg-sage-soft"
                        >
                            View
                        </RouterLink>
                    </div>
                    <p v-if="itemAlertQuery.isError.value" class="text-sm text-rose-700 sm:col-span-2" role="alert">
                        Buy soon status could not be checked.
                        <button type="button" class="underline" @click="itemAlertQuery.refetch()">Retry status</button>
                    </p>
                </li>
            </ul>
            <div v-if="itemQuery.data.value && itemQuery.data.value.meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3 text-sm">
                <button class="min-h-10 px-2 text-sage-dark disabled:opacity-40" :disabled="resultsPage <= 1" @click="changePage('results', -1)">Previous</button>
                <span>Page {{ resultsPage }} of {{ itemQuery.data.value.meta.last_page }}</span>
                <button class="min-h-10 px-2 text-sage-dark disabled:opacity-40" :disabled="resultsPage >= itemQuery.data.value.meta.last_page" @click="changePage('results', 1)">Next</button>
            </div>
        </section>

        <section v-if="selectedItem && action" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-6" aria-labelledby="quick-action-title">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ selectedItem.name }}</p>
                    <h2 id="quick-action-title" class="mt-1 text-lg font-semibold text-ink">
                        {{ action === 'consume' ? 'Consume stock' : 'Restock' }}
                    </h2>
                </div>
                <button type="button" :disabled="pending" class="text-sm text-ink-muted underline" @click="cancelAction">
                    Cancel
                </button>
            </div>
            <p v-if="actionError" class="mt-4 text-sm text-rose-700" role="alert">{{ actionError }}</p>
            <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submitMovement">
                <div class="grid gap-2">
                    <label for="dashboard-location" class="text-sm font-medium text-ink">Location</label>
                    <select id="dashboard-location" v-model="selectedLocationId" required class="min-h-11 rounded-md border border-line bg-white px-3 text-ink">
                        <option value="" disabled>Choose a location</option>
                        <option v-for="option in actionLocationOptions" :key="option.location.id" :value="option.location.id">
                            {{ option.path }}{{ option.quantity !== null ? ` (${option.quantity} available)` : '' }}
                        </option>
                    </select>
                </div>
                <div v-if="action === 'restock'" class="grid gap-2">
                    <label for="dashboard-quantity" class="text-sm font-medium text-ink">
                        Quantity ({{ selectedItem.counting_unit_plural ?? selectedItem.counting_unit }})
                    </label>
                    <input id="dashboard-quantity" v-model="quantity" type="number" min="1" step="1" required class="min-h-11 rounded-md border border-line px-3">
                </div>
                <div class="space-y-3 sm:col-span-2">
                    <p v-if="selectedLocationId" class="text-sm text-ink-muted" role="status">
                        {{ action === 'consume' ? `Consume 1 ${selectedItem.counting_unit} from ${chosenLocation()}.` : `Restock ${quantity} ${selectedItem.counting_unit_plural ?? selectedItem.counting_unit} at ${chosenLocation()}.` }}
                    </p>
                    <label class="flex items-start gap-3 text-sm text-ink">
                        <input v-model="confirmation" type="checkbox" class="mt-1 size-4 accent-[var(--color-sage)]">
                        <span>Confirm this stock change at the selected location.</span>
                    </label>
                    <button type="submit" :disabled="pending || !selectedLocationId || !confirmation" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-50">{{ pending ? 'Saving…' : 'Save stock change' }}</button>
                </div>
            </form>
            <p v-if="locationsQuery.isError.value" class="mt-3 text-sm text-rose-700" role="alert">
                Locations could not be loaded.
                <button type="button" class="underline" @click="locationsQuery.refetch()">Try again</button>
            </p>
        </section>

        <div class="mt-8 grid gap-5 xl:grid-cols-3">
            <section class="rounded-panel border border-line bg-white shadow-card" aria-labelledby="empty-heading">
                <div class="border-b border-line px-5 py-4"><h2 id="empty-heading" class="font-semibold text-ink">Out of stock</h2><p class="mt-1 text-sm text-ink-muted">Monitored locations with no stock</p></div>
                <div v-if="emptyQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">Out of stock items could not be loaded. <button class="underline" @click="emptyQuery.refetch()">Try again</button></div>
                <div v-else-if="emptyQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading…</div>
                <p v-else-if="!emptyLevels.length" class="p-5 text-sm text-ink-muted">Nothing is out of stock.</p>
                <ul v-else class="divide-y divide-line"><li v-for="level in emptyLevels" :key="level.id" class="p-4"><RouterLink :to="{ name: 'inventory-item', params: { item: level.item.id } }" class="font-medium text-ink hover:underline">{{ level.item.name }}</RouterLink><p class="mt-1 text-sm text-ink-muted">{{ buildLocationPath(level.location, locationsQuery.data.value ?? []) }} · 0 {{ level.item.counting_unit }} · threshold {{ level.alert_threshold }}</p><RouterLink :to="{ name: 'inventory-item', params: { item: level.item.id } }" class="mt-2 inline-block text-sm font-medium text-sage-dark">Restock or transfer</RouterLink></li></ul>
                <div v-if="emptyQuery.data.value && emptyQuery.data.value.meta.last_page > 1" class="flex justify-between border-t border-line px-4 py-2 text-sm"><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="emptyPage <= 1" @click="changePage('empty', -1)">Previous</button><span class="self-center">{{ emptyPage }} / {{ emptyQuery.data.value.meta.last_page }}</span><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="emptyPage >= emptyQuery.data.value.meta.last_page" @click="changePage('empty', 1)">Next</button></div>
            </section>
            <section class="rounded-panel border border-line bg-white shadow-card" aria-labelledby="low-heading">
                <div class="border-b border-line px-5 py-4"><h2 id="low-heading" class="font-semibold text-ink">Low stock</h2><p class="mt-1 text-sm text-ink-muted">At or below the location threshold</p></div>
                <div v-if="lowQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">Low stock items could not be loaded. <button class="underline" @click="lowQuery.refetch()">Try again</button></div>
                <div v-else-if="lowQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading…</div>
                <p v-else-if="!lowLevels.length" class="p-5 text-sm text-ink-muted">No low stock alerts.</p>
                <ul v-else class="divide-y divide-line"><li v-for="level in lowLevels" :key="level.id" class="p-4"><RouterLink :to="{ name: 'inventory-item', params: { item: level.item.id } }" class="font-medium text-ink hover:underline">{{ level.item.name }}</RouterLink><p class="mt-1 text-sm text-ink-muted">{{ buildLocationPath(level.location, locationsQuery.data.value ?? []) }} · {{ level.quantity }} {{ level.item.counting_unit }} · threshold {{ level.alert_threshold }}</p><RouterLink :to="{ name: 'inventory-item', params: { item: level.item.id } }" class="mt-2 inline-block text-sm font-medium text-sage-dark">Restock or transfer</RouterLink></li></ul>
                <div v-if="lowQuery.data.value && lowQuery.data.value.meta.last_page > 1" class="flex justify-between border-t border-line px-4 py-2 text-sm"><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="lowPage <= 1" @click="changePage('low', -1)">Previous</button><span class="self-center">{{ lowPage }} / {{ lowQuery.data.value.meta.last_page }}</span><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="lowPage >= lowQuery.data.value.meta.last_page" @click="changePage('low', 1)">Next</button></div>
            </section>
            <section class="rounded-panel border border-line bg-white shadow-card" aria-labelledby="buysoon-heading">
                <div class="border-b border-line px-5 py-4"><h2 id="buysoon-heading" class="font-semibold text-ink">Buy soon</h2><p class="mt-1 text-sm text-ink-muted">Items you marked for your next shop</p></div>
                <div v-if="activeAlertQuery.isError.value" class="p-5 text-sm text-rose-700" role="alert">Buy soon alerts could not be loaded. <button class="underline" @click="activeAlertQuery.refetch()">Try again</button></div>
                <div v-else-if="activeAlertQuery.isPending.value" class="p-5 text-sm text-ink-muted" role="status">Loading…</div>
                <p v-else-if="!activeAlertQuery.data.value?.data.length" class="p-5 text-sm text-ink-muted">Nothing marked to buy soon.</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="alert in activeAlertQuery.data.value.data" :key="alert.id" class="flex items-center justify-between gap-3 p-4">
                        <RouterLink :to="{ name: 'inventory-item', params: { item: alert.item.id } }" class="min-w-0 font-medium text-ink hover:underline">{{ alert.item.name }}</RouterLink>
                        <button type="button" :disabled="pending" class="min-h-10 shrink-0 rounded-md border border-line px-3 text-sm disabled:opacity-50" @click="resolveBuySoon(alert.id)">Resolve</button>
                    </li>
                </ul>
                <div v-if="activeAlertQuery.data.value && activeAlertQuery.data.value.meta.last_page > 1" class="flex justify-between border-t border-line px-4 py-2 text-sm"><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="buySoonPage <= 1" @click="changePage('buySoon', -1)">Previous</button><span class="self-center">{{ buySoonPage }} / {{ activeAlertQuery.data.value.meta.last_page }}</span><button class="min-h-10 text-sage-dark disabled:opacity-40" :disabled="buySoonPage >= activeAlertQuery.data.value.meta.last_page" @click="changePage('buySoon', 1)">Next</button></div>
            </section>
        </div>
        <button v-if="itemQuery.isError.value || emptyQuery.isError.value || lowQuery.isError.value || activeAlertQuery.isError.value" type="button" class="mt-4 text-sm font-medium text-sage-dark underline" @click="retryQueries">Retry dashboard</button>
    </section>
</template>
