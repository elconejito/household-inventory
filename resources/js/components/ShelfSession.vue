<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import { getLocation } from '../api/note-contexts';
import type { CatalogInventoryLevel } from '../api/catalog';
import type { InventoryItem } from '../api/items';
import { buildLocationPath, getLocationTrail } from '../api/stock';
import { parseApiErrors } from '../lib/api-errors';
import { useDirectLocationLevelsQuery } from '../queries/catalog';
import { useCreateItemMutation } from '../queries/items';
import { useAllItemsQuery, useCountShelfItemMutation, useLocationsQuery, useStockItemQuery } from '../queries/stock';
import FormModal from './FormModal.vue';
import PaginatedList from './PaginatedList.vue';
import StockImpact from './StockImpact.vue';
import UiIcon from './UiIcon.vue';

type SessionRecord = { item: InventoryItem; quantity: number; detail: string; skipped: boolean };
const props = defineProps<{ locationId: string; mode: 'entry' | 'count' }>();
const router = useRouter();
const formId = useId();
const counting = computed(() => props.mode === 'count');
const locationId = computed(() => props.locationId);
const place = useQuery({ queryKey: computed(() => ['locations', 'detail', props.locationId]), queryFn: () => getLocation(props.locationId) });
const locations = useLocationsQuery();
const shelf = useDirectLocationLevelsQuery(computed(() => counting.value ? locationId.value : ''));
const catalog = useAllItemsQuery(computed(() => !counting.value));
const countMutation = useCountShelfItemMutation();
const createMutation = useCreateItemMutation();
const busy = ref(false);
const queue = ref<CatalogInventoryLevel[]>([]);
const position = ref(0);
const countUnitInput = ref<HTMLInputElement>();
const search = ref('');
const selected = ref<InventoryItem | null>(null);
const newName = ref('');
const countingUnit = ref('');
const input = ref('');
const records = ref<SessionRecord[]>([]);
const notice = ref('');
const error = ref('');
const finished = ref(false);
const quantityInput = ref<HTMLInputElement>();
const searchInput = ref<HTMLInputElement>();

const title = computed(() => counting.value ? 'Count this shelf' : 'Add items here');
const shelfLink = computed(() => ({ name: 'location-detail', params: { location: props.locationId } }));
const trail = computed(() => place.data.value ? getLocationTrail(place.data.value, locations.data.value ?? []) : []);
const path = computed(() => place.data.value ? buildLocationPath(place.data.value, locations.data.value ?? []) : '');
const current = computed(() => queue.value[position.value]);
const activeItem = computed(() => counting.value ? current.value?.item : selected.value);
const detail = useStockItemQuery(computed(() => activeItem.value?.id ?? ''));
const matches = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();
    return term ? (catalog.data.value ?? []).filter((item) => item.name.toLocaleLowerCase().includes(term)) : [];
});
const canCreate = computed(() => search.value.trim() && !matches.value.some((item) => item.name.toLocaleLowerCase() === search.value.trim().toLocaleLowerCase()));
const activeLevel = computed(() => detail.data.value?.inventory_levels?.find((level) => level.location.id === props.locationId));
const otherLevels = computed(() => (detail.data.value?.inventory_levels ?? []).filter((level) => level.location.id !== props.locationId && level.quantity > 0));
const otherStock = computed(() => {
    const first = otherLevels.value[0];
    if (!first) return '';
    if (otherLevels.value.length === 1) return `${first.location.name} keeps its ${first.quantity} ${unit(activeItem.value, first.quantity)}.`;
    const total = otherLevels.value.reduce((sum, level) => sum + level.quantity, 0);
    return `Other locations keep their ${total} ${unit(activeItem.value, total)}.`;
});
const amount = computed(() => Number(input.value));
const hasAmount = computed(() => String(input.value).trim() !== '');
const validAmount = computed(() => hasAmount.value && Number.isInteger(amount.value) && amount.value >= 0 && amount.value <= 4294967295);
const ready = computed(() => !busy.value && validAmount.value && (newName.value ? Boolean(countingUnit.value.trim()) : Boolean(activeItem.value && detail.isSuccess.value)));
const quantityLabel = computed(() => newName.value || (!counting.value && detail.isSuccess.value && !activeLevel.value)
    ? 'Opening quantity on this shelf'
    : 'Counted on this shelf');
const saved = computed(() => records.value.filter((record) => !record.skipped));
const skipped = computed(() => records.value.filter((record) => record.skipped));
const shelfOrder = computed(() => {
    const byItem = new Map(records.value.map((record) => [record.item.id, record]));
    return queue.value.map((level, index) => {
        const record = byItem.get(level.item.id);
        const quantity = record?.quantity ?? level.quantity;
        const status = position.value === index ? 'Current item' : record?.detail || (index === position.value + 1 ? 'Up next' : `${index + 1} of ${queue.value.length}`);
        return { id: level.id, current: position.value === index, name: level.item.name, status, balance: `${quantity} ${unit(level.item, quantity)}` };
    });
});
const hasSelection = computed(() => Boolean(activeItem.value || newName.value));

watch([shelf.data, shelf.isFetching, shelf.isSuccess], ([levels, fetching, success]) => {
    if (!counting.value || !success || fetching || !levels || queue.value.length) return;
    queue.value = levels.filter((level) => level.location.id === props.locationId)
        .sort((first, second) => first.item.name.localeCompare(second.item.name, undefined, { numeric: true, sensitivity: 'base' }) || first.id.localeCompare(second.id));
}, { immediate: true });

onBeforeRouteLeave(() => !busy.value);
onBeforeRouteUpdate(() => !busy.value);

function unit(item: InventoryItem | null | undefined, n: number): string {
    if (!item) return countingUnit.value.trim();
    return n === 1 ? item.counting_unit : item.counting_unit_plural || item.counting_unit;
}

function select(item?: InventoryItem): void {
    selected.value = item ?? null;
    newName.value = item ? '' : search.value.trim();
    countingUnit.value = '';
    input.value = '';
    error.value = '';
    nextTick(() => (item ? quantityInput : countUnitInput).value?.focus());
}

function resetEntry(): void {
    selected.value = null;
    newName.value = '';
    search.value = '';
    countingUnit.value = '';
    input.value = '';
    error.value = '';
    nextTick(() => searchInput.value?.focus());
}

function advance(): void {
    input.value = '';
    error.value = '';
    if (position.value + 1 >= queue.value.length) finished.value = true;
    else {
        position.value++;
        nextTick(() => quantityInput.value?.focus());
    }
}

function remember(record: SessionRecord): void {
    records.value = [...records.value.filter((previous) => previous.item.id !== record.item.id), record];
}

function skip(): void {
    if (busy.value || !current.value) return;
    const previousCount = records.value.find((record) => record.item.id === current.value.item.id && !record.skipped);
    if (previousCount) {
        notice.value = `Kept the saved count for ${current.value.item.name}.`;
    } else {
        remember({ item: current.value.item, quantity: activeLevel.value?.quantity ?? current.value.quantity, detail: 'Skipped · recorded quantity retained', skipped: true });
        notice.value = `${current.value.item.name} skipped. Recorded quantity retained.`;
    }
    advance();
}

function previous(): void {
    if (!position.value || busy.value) return;
    position.value--;
    const record = records.value.find((entry) => entry.item.id === current.value?.item.id);
    input.value = record && !record.skipped ? String(record.quantity) : '';
    error.value = '';
    notice.value = '';
}

async function save(finish: boolean): Promise<void> {
    if (!ready.value) return;
    busy.value = true;
    error.value = '';
    const quantity = amount.value;
    try {
        let item = activeItem.value;
        let description: string;
        if (newName.value) {
            item = await createMutation.mutateAsync({
                name: newName.value, counting_unit: countingUnit.value.trim(), description: null,
                opening_stock: { location_id: props.locationId, quantity },
            });
            description = quantity ? 'Created with opening stock' : 'Created and tracked at zero';
        } else {
            if (!item) return;
            description = !activeLevel.value ? 'Started tracking here' : activeLevel.value.quantity === quantity ? 'Count confirmed' : 'Count updated';
            await countMutation.mutateAsync({ itemId: item.id, locationId: props.locationId, quantity });
        }
        remember({ item, quantity, detail: description, skipped: false });
        notice.value = `Saved ${item.name} · ${quantity} ${unit(item, quantity)} on this shelf.`;
        if (finish) finished.value = true;
        else if (counting.value) advance();
        else resetEntry();
    } catch (cause) {
        const messages = parseApiErrors(cause);
        error.value = [messages.form, ...Object.values(messages.fields)].filter(Boolean).join(' ');
    } finally {
        busy.value = false;
    }
}

function done(): void {
    router.push(shelfLink.value);
}
</script>

<template>
    <section class="shelf-session text-ink" :aria-label="title">
        <nav aria-label="Location path" class="mb-3 flex flex-wrap items-center gap-2 text-sm text-ink-muted">
            <RouterLink :to="{ name: 'catalog-locations' }">Locations</RouterLink>
            <template v-for="part in trail" :key="part.id"><span aria-hidden="true">›</span><RouterLink :to="{ name: 'location-detail', params: { location: part.id } }">{{ part.name }}</RouterLink></template>
        </nav>
        <h1 class="page-title">{{ title }}</h1>
        <p class="mb-5 mt-1 text-sm text-ink-muted">{{ counting ? 'Work through items in shelf order.' : 'Record what you find at one location.' }}</p>
        <p v-if="place.isPending.value" role="status">Loading location…</p>
        <p v-else-if="place.isError.value" role="alert">This location could not be loaded. <button class="quiet" @click="place.refetch()">Try again</button></p>
        <template v-else>
            <div class="panel mb-4 flex items-center gap-3">
                <span class="icon-tile"><UiIcon name="pin" :size="19" /></span>
                <div class="min-w-0 flex-1"><small>Working location</small><strong>{{ path }}</strong></div>
                <RouterLink :to="{ name: 'catalog-locations' }" class="quiet">Change</RouterLink>
            </div>
            <p v-if="notice && !finished" role="status" class="receipt">✓ {{ notice }}</p>
            <div class="grid items-start gap-4 md:grid-cols-[minmax(0,1.45fr)_minmax(13rem,.8fr)]">
                <section class="panel grid gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <h2>{{ counting ? 'Shelf count' : records.length && !hasSelection ? 'Next item' : 'Record an item' }}</h2>
                        <span class="pill">{{ counting ? (queue.length ? (position + 1) + ' of ' + queue.length : 'Shelf count') : newName ? 'New item' : 'Shelf entry' }}</span>
                    </div>
                    <template v-if="counting">
                        <p v-if="shelf.isPending.value || (shelf.isFetching.value && !queue.length)" role="status">Loading shelf items…</p>
                        <p v-else-if="shelf.isError.value" role="alert">Shelf items could not be loaded. <button type="button" class="quiet" @click="shelf.refetch()">Try again</button></p>
                        <p v-else-if="!current" class="hint">No items are tracked directly at this location.</p>
                        <template v-else><h3 class="text-xl font-semibold">{{ current.item.name }}</h3><p class="hint">Recorded here <b>{{ activeLevel?.quantity ?? current.quantity }} {{ unit(current.item, activeLevel?.quantity ?? current.quantity) }}</b></p></template>
                    </template>
                    <template v-else-if="!hasSelection">
                        <label class="field">Item name<input ref="searchInput" v-model="search" type="search" autocomplete="off" maxlength="255" placeholder="Find an item or enter a new name"></label>
                        <p v-if="catalog.isPending.value" role="status" class="hint">Loading items…</p>
                        <p v-else-if="catalog.isError.value" role="alert">Items could not be loaded. <button type="button" class="quiet" @click="catalog.refetch()">Try again</button></p>
                        <div v-else-if="search.trim()" class="divide-y divide-line rounded-md border border-line">
                            <PaginatedList v-if="matches.length" v-slot="{ rows }" :items="matches" :reset-key="search" label="Matching items pagination">
                                <button v-for="item in rows" :key="item.id" type="button" class="choice" @click="select(item)"><b>{{ item.name }}</b><small>Counted in {{ unit(item, 2) }}</small></button>
                            </PaginatedList>
                            <button v-if="canCreate" type="button" class="choice font-semibold text-sage-dark" @click="select()">Create “{{ search.trim() }}”</button>
                        </div>
                        <p v-else class="hint">The shelf stays selected for the next item.</p>
                    </template>
                    <template v-else>
                        <div class="field"><span>Item name</span><div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-line p-3"><div><b>{{ newName || activeItem?.name }}</b><small>{{ newName ? 'New item' : 'Existing item · counted in ' + unit(activeItem, 2) }}</small></div><button type="button" class="quiet" :disabled="busy" @click="resetEntry">Change item</button></div></div>
                        <label v-if="newName" class="field">Counting unit<input ref="countUnitInput" :form="formId" v-model="countingUnit" :disabled="busy" placeholder="e.g. pack" maxlength="255" required></label>
                    </template>
                    <form v-if="hasSelection" :id="formId" class="grid gap-3" @submit.prevent="save(counting && position + 1 === queue.length)">
                        <p v-if="activeItem && detail.isPending.value" role="status" class="hint">Loading item stock…</p>
                        <p v-else-if="activeItem && detail.isError.value" role="alert">Item stock could not be loaded. <button type="button" class="quiet" @click="detail.refetch()">Try again</button></p>
                        <div class="field">
                            <label :for="formId + '-quantity'">{{ quantityLabel }}</label>
                            <div class="quantity-field">
                                <input :id="formId + '-quantity'" ref="quantityInput" v-model="input" type="number" min="0" max="4294967295" step="1" inputmode="numeric" :disabled="busy" required>
                                <span>{{ unit(activeItem, amount) }}</span>
                            </div>
                        </div>
                        <StockImpact v-if="validAmount && (newName || detail.isSuccess.value)" label="Shelf quantity" :before="activeLevel?.quantity ?? 0" :after="amount" :unit="unit(activeItem, amount)" />
                        <p v-if="otherStock" class="hint">{{ otherStock }}</p>
                        <p v-else class="hint">Enter the total you see on this shelf, including zero.</p>
                    </form>
                    <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                        <div class="flex flex-wrap gap-2">
                            <RouterLink v-if="!records.length || !counting" :to="shelfLink" class="quiet">Cancel</RouterLink>
                            <button v-if="counting && current" type="button" class="quiet" :disabled="busy" @click="skip">Skip item</button>
                            <button v-if="counting && position" type="button" class="quiet" :disabled="busy" @click="previous">Review previous</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="!hasSelection || (counting && !hasAmount)" type="button" class="secondary" :disabled="busy" @click="finished = true">Finish session</button>
                            <button v-if="hasSelection && (!counting || (hasAmount && position + 1 < queue.length))" type="button" class="secondary" :disabled="!ready" @click="save(true)">Save &amp; finish</button>
                            <button v-if="hasSelection" :form="formId" type="submit" class="primary" :disabled="!ready">{{ busy ? 'Saving…' : counting ? (position + 1 === queue.length ? 'Save & finish' : 'Save & next') : 'Save & add another' }}</button>
                        </div>
                    </div>
                </section>
                <aside class="panel">
                    <h2>{{ counting ? 'Shelf order' : 'This session' }}</h2>
                    <PaginatedList v-if="counting" v-slot="{ rows }" :items="shelfOrder" :current-index="position" label="Shelf order pagination">
                        <ol class="mt-2 divide-y divide-line">
                        <li v-for="row in rows" :key="row.id" class="log-row" :aria-current="row.current ? 'step' : undefined">
                            <span><b>{{ row.name }}</b><small>{{ row.status }}</small></span>
                            <span>{{ row.balance }}</span>
                        </li>
                        </ol>
                    </PaginatedList>
                    <PaginatedList v-else-if="records.length" v-slot="{ rows }" :items="records" :current-index="records.length - 1" label="Session log pagination">
                        <ul class="divide-y divide-line">
                            <li v-for="record in rows" :key="record.item.id" class="log-row">
                                <span><b>{{ record.item.name }}</b><small>{{ record.detail }}</small></span>
                                <span>{{ record.quantity }} {{ unit(record.item, record.quantity) }}</span>
                            </li>
                        </ul>
                    </PaginatedList>
                    <div v-else class="mt-4 grid gap-2.5"><span class="icon-tile"><UiIcon name="box" /></span><p class="hint">Saved items will appear here.</p></div>
                </aside>
            </div>
        </template>
        <FormModal v-if="finished" :label="counting ? 'Shelf count finished' : 'Entry session finished'" @close="done">
            <div class="grid gap-4 p-5">
                <div><h2>{{ counting ? 'Shelf count finished' : 'Entry session finished' }}</h2><p class="hint">{{ path }}</p></div>
                <p class="receipt" role="status">{{ saved.length }} {{ saved.length === 1 ? 'item' : 'items' }} {{ counting ? 'counted' : 'recorded' }} at this shelf.{{ counting ? ` ${skipped.length} skipped. ${queue.length - records.length} remaining.` : '' }}</p>
                <PaginatedList v-if="records.length" v-slot="{ rows }" :items="records" label="Session summary pagination">
                    <ul class="divide-y divide-line">
                        <li v-for="record in rows" :key="record.item.id" class="log-row">
                            <span><b>{{ record.item.name }}</b><small>{{ record.detail }}</small></span>
                            <span>{{ record.quantity }} {{ unit(record.item, record.quantity) }}</span>
                        </li>
                    </ul>
                </PaginatedList>
                <div class="text-right"><button type="button" class="primary" @click="done">Done</button></div>
            </div>
        </FormModal>
    </section>
</template>

<style scoped>
@reference "../styles.css";
.pill { @apply rounded-full bg-surface-soft px-2.5 py-1 text-xs font-semibold text-ink-muted; }
.panel { @apply min-w-0 rounded-panel border border-line bg-white p-4 sm:p-5; }
h2 { @apply text-lg font-semibold tracking-tight; }
small,.hint { @apply text-sm font-normal text-ink-muted; }
small,strong { @apply block; }
.field { @apply grid gap-1.5 text-sm font-medium; }
.field > input,.quantity-field { @apply min-h-11 w-full rounded-md border border-line bg-white px-3; }
.quantity-field { @apply flex items-center gap-2; }
.quantity-field input { @apply min-h-11 w-0 flex-1 text-base font-semibold; }
.quantity-field > span { @apply text-ink-muted; }
.quiet,.primary,.secondary { @apply inline-flex min-h-10 items-center justify-center rounded-md px-3 text-sm font-semibold; }
.quiet { @apply text-sage-dark hover:bg-sage-soft; }
.primary { @apply bg-sage-dark text-white hover:brightness-95; }
.secondary { @apply border border-line bg-white hover:bg-surface-soft; }
button:disabled { @apply cursor-not-allowed opacity-50; }
.choice { @apply block w-full p-3 text-left text-sm hover:bg-sage-soft; }
.icon-tile { @apply grid size-9 shrink-0 place-items-center rounded-md bg-sage-soft text-sage-dark; }
.icon-tile svg { @apply size-5; }
.receipt { @apply mb-3 rounded-md border border-line bg-sage-soft px-3 py-2.5 text-sm font-medium text-sage-dark; }
.log-row { @apply flex items-center justify-between gap-3 py-3 text-sm; }
.log-row > :last-child { @apply shrink-0 font-medium tabular-nums; }
</style>
