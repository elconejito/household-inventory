<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { getCatalogLocations, getCategories } from '../api/catalog';
import { getItems } from '../api/items';
import FormModal from './FormModal.vue';
import ListPagination from './ListPagination.vue';
import UiIcon from './UiIcon.vue';

const emit = defineEmits<{ open: [] }>();
const shortcutLabel = navigator.platform.toLowerCase().includes('mac') ? '⌘ K' : 'Ctrl K';
const isOpen = ref(false);
const search = ref('');
const debouncedSearch = ref('');
const selectedIndex = ref(-1);
const enabled = computed(() => Boolean(debouncedSearch.value));
const groups = [
    { label: 'Items', singular: 'item', plural: 'items', list: 'inventory', detail: 'inventory-item', param: 'item', page: ref(1), perPage: ref<10 | 25 | 50 | 100>(10), fetch: (params: { search: string; page: number; perPage: number }) => getItems(params, '') },
    { label: 'Locations', singular: 'location', plural: 'locations', list: 'catalog-locations', detail: 'location-detail', param: 'location', page: ref(1), perPage: ref<10 | 25 | 50 | 100>(10), fetch: (params: { search: string; page: number; perPage: number }) => getCatalogLocations(params) },
    { label: 'Categories', singular: 'category', plural: 'categories', list: 'catalog-categories', detail: 'category-detail', param: 'category', page: ref(1), perPage: ref<10 | 25 | 50 | 100>(10), fetch: (params: { search: string; page: number; perPage: number }) => getCategories(params) },
].map(({ fetch, ...group }) => {
    const params = computed(() => ({ search: debouncedSearch.value, page: group.page.value, perPage: group.perPage.value }));

    return {
        ...group,
        query: useQuery({
            queryKey: computed(() => ['global-search', group.list, params.value]),
            queryFn: () => fetch(params.value),
            enabled,
        }),
    };
});

const term = computed(() => search.value.trim());
const waiting = computed(() => term.value !== debouncedSearch.value || groups.some(({ query }) => query.isPending.value));
const hasErrors = computed(() => groups.some(({ query }) => query.isError.value));
const hasResults = computed(() => groups.some(({ query }) => query.data.value?.data.length));
const resultIds = computed(() => groups.flatMap(({ list, query }) => (query.data.value?.data ?? []).map((result) => `${list}:${result.id}`)).join('|'));

watch(resultIds, () => { selectedIndex.value = -1; });

watch(term, (value, _, onCleanup) => {
    const timer = setTimeout(() => {
        groups.forEach((group) => { group.page.value = 1; });
        debouncedSearch.value = value;
    }, 250);
    onCleanup(() => clearTimeout(timer));
});

function openSearch(): void {
    if (isOpen.value || document.querySelector('dialog[open]')) return;
    emit('open');
    isOpen.value = true;
}

function closeSearch(): void {
    isOpen.value = false;
    search.value = '';
    debouncedSearch.value = '';
    groups.forEach((group) => { group.page.value = 1; });
}

function handleShortcut(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.isComposing || event.altKey || event.shiftKey
        || !(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') return;

    if (!isOpen.value && document.querySelector('dialog[open]')) return;
    event.preventDefault();
    openSearch();
}

function resultPosition(groupIndex: number, resultIndex: number): number {
    return groups.slice(0, groupIndex).reduce((total, group) => total + (group.query.data.value?.data.length ?? 0), 0) + resultIndex;
}

function handleResultKeys(event: KeyboardEvent): void {
    if (!isOpen.value || waiting.value) return;

    const resultCount = groups.reduce((total, group) => total + (group.query.data.value?.data.length ?? 0), 0);
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (resultCount === 0) return;
        event.preventDefault();
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        if (selectedIndex.value < 0) {
            selectedIndex.value = direction > 0 ? 0 : resultCount - 1;
        } else {
            selectedIndex.value = (selectedIndex.value + direction + resultCount) % resultCount;
        }
        void nextTick(() => document.querySelector<HTMLElement>(`[data-search-result-index="${selectedIndex.value}"]`)?.focus({ preventScroll: true }));
        return;
    }

    if (event.key === 'Enter' && !event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey && selectedIndex.value >= 0) {
        const selectedResult = document.querySelector<HTMLElement>(`[data-search-result-index="${selectedIndex.value}"]`);
        if (!selectedResult) return;
        event.preventDefault();
        selectedResult.click();
    }
}

function followResult(event: MouseEvent): void {
    if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) closeSearch();
}

onMounted(() => {
    document.addEventListener('keydown', handleShortcut);
    document.addEventListener('keydown', handleResultKeys);
});
onUnmounted(() => {
    document.removeEventListener('keydown', handleShortcut);
    document.removeEventListener('keydown', handleResultKeys);
});
</script>

<template>
    <button
        type="button"
        aria-label="Search all"
        aria-haspopup="dialog"
        aria-keyshortcuts="Control+K Meta+K"
        :title="`Search all (${shortcutLabel})`"
        class="search-trigger"
        @click="openSearch"
    >
        <UiIcon name="search" :size="20" />
        <span class="search-label">Search</span><kbd>{{ shortcutLabel }}</kbd>
    </button>

    <FormModal v-if="isOpen" label="Search all" class="max-w-xl search-dialog" @close="closeSearch">
        <div class="grid gap-4 p-4 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold tracking-tight">Search all</h2>
                <button type="button" aria-label="Close search" class="grid size-11 place-items-center rounded-md text-xl text-ink-muted hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-sage" @click="closeSearch">×</button>
            </div>
            <label class="sr-only" for="global-search">Search items, locations, and categories</label>
            <input
                id="global-search"
                v-model="search"
                type="search"
                autofocus
                autocomplete="off"
                placeholder="Items, locations, and categories"
                class="min-h-11 w-full rounded-md border border-line bg-canvas px-3 text-base outline-none focus:border-sage focus:ring-2 focus:ring-sage/20"
            >
            <p class="search-keyboard-hint">Use ↑ and ↓ to move, then Enter to open a result.</p>
            <div v-if="term" class="grid gap-4">
                <p v-if="waiting" role="status" class="text-sm text-ink-muted">Searching…</p>
                <template v-else>
                    <p v-if="hasErrors" role="status" class="text-sm text-ink-muted">Some results couldn’t be loaded.</p>
                    <p v-else-if="!hasResults" role="status" class="text-sm text-ink-muted">No matches found.</p>
                    <section v-for="(group, groupIndex) in groups" v-show="group.query.data.value?.data.length" :key="group.list" :aria-label="group.label">
                        <h3 class="px-2 text-sm font-semibold text-ink-muted">{{ group.label }}</h3>
                        <ul>
                            <li v-for="(result, resultIndex) in group.query.data.value?.data" :key="result.id">
                            <RouterLink
                                    :to="{ name: group.detail, params: { [group.param]: result.id } }"
                                    :id="`global-search-result-${resultPosition(groupIndex, resultIndex)}`"
                                    :data-search-result-index="resultPosition(groupIndex, resultIndex)"
                                    class="block rounded-md px-2 py-2.5 wrap-break-word hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-sage"
                                    :class="{ 'bg-surface-soft ring-2 ring-sage/30': selectedIndex === resultPosition(groupIndex, resultIndex) }"
                                    @click="followResult"
                                >{{ result.name }}</RouterLink>
                            </li>
                        </ul>
                        <ListPagination
                            v-if="group.query.data.value"
                            :meta="group.query.data.value.meta"
                            :page="group.page.value"
                            v-model:per-page="group.perPage.value"
                            :busy="group.query.isFetching.value"
                            :label="`${group.label} search results pagination`"
                            :singular="group.singular"
                            :plural="group.plural"
                            @change="group.page.value = $event"
                        />
                        <RouterLink
                            v-if="(group.query.data.value?.meta.last_page ?? 0) > 1"
                            :to="{ name: group.list, query: { search: term } }"
                            class="inline-block rounded-md px-2 py-2 text-sm font-semibold text-sage-dark hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-sage"
                            @click="followResult"
                        >View all matches in {{ group.label.toLowerCase() }}</RouterLink>
                    </section>
                </template>
            </div>
        </div>
    </FormModal>
</template>
