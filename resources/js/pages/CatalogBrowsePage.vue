<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { buildLocationPath, type Location as StockLocation } from '../api/stock';
import { parseApiErrors, type FormErrors } from '../lib/api-errors';
import { useCategoriesCatalogQuery, useCreateCatalogLocationMutation, useCreateCategoryMutation, useLocationsCatalogQuery } from '../queries/catalog';
import { useLocationsQuery } from '../queries/stock';
import InventoryTabs from '../components/InventoryTabs.vue';

const pageSizes = [10, 25, 50, 100];
const route = useRoute();
const router = useRouter();
const isCategoriesPage = computed(() => route.name === 'catalog-categories');
const search = ref('');
const debouncedSearch = ref('');
const page = ref(1);
const pageSize = ref(10);
const showCreateForm = ref(false);
const categoryName = ref('');
const locationName = ref('');
const locationDescription = ref('');
const createParentId = ref('');
const errors = ref<FormErrors>({ fields: {}, form: '' });
const success = ref('');
const locationOptions = useLocationsQuery(computed(() => !isCategoriesPage.value));
let searchTimeout: ReturnType<typeof setTimeout> | undefined;

const parentId = computed(() => {
    const value = route.query.parent;
    return typeof value === 'string' && value.length > 0 ? value : 'root';
});
const params = computed(() => ({ search: debouncedSearch.value, page: page.value, perPage: pageSize.value }));
const categoryQuery = useCategoriesCatalogQuery(params, isCategoriesPage);
const locationParams = computed(() => ({ ...params.value, parentId: parentId.value }));
const locationQuery = useLocationsCatalogQuery(locationParams, computed(() => !isCategoriesPage.value));
const createCategoryMutation = useCreateCategoryMutation();
const createLocationMutation = useCreateCatalogLocationMutation();
const activeQuery = computed(() => isCategoriesPage.value ? categoryQuery : locationQuery);
const currentParent = computed(() => (locationOptions.data.value ?? []).find((location) => location.id === parentId.value));
const currentParentPath = computed(() => currentParent.value
    ? buildLocationPath(currentParent.value, locationOptions.data.value ?? [])
    : parentId.value === 'root'
        ? 'Top-level locations'
        : locationOptions.isError.value
            ? 'Location hierarchy unavailable'
            : 'Loading location hierarchy…');
const locationRows = computed(() => (locationQuery.data.value?.data ?? []).map((location) => ({
    location,
    path: buildLocationPath(location, locationOptions.data.value ?? []),
})));
const eligibleParents = computed(() => locationOptions.data.value ?? []);
const isPending = computed(() => createCategoryMutation.isPending.value || createLocationMutation.isPending.value);

watch(search, (value) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        page.value = 1;
        debouncedSearch.value = value.trim();
    }, 300);
});

watch(() => [route.name, route.query.parent, route.query.create], () => {
    page.value = 1;
    pageSize.value = 10;
    search.value = '';
    debouncedSearch.value = '';
    showCreateForm.value = route.query.create === '1';
    categoryName.value = '';
    locationName.value = '';
    locationDescription.value = '';
    createParentId.value = parentId.value ?? '';
    errors.value = { fields: {}, form: '' };
    success.value = '';
}, { immediate: true });

watch(parentId, (value) => {
    createParentId.value = value === 'root' ? '' : value;
});

onUnmounted(() => {
    if (searchTimeout) clearTimeout(searchTimeout);
});

function changePage(nextPage: number): void {
    if (nextPage >= 1 && nextPage <= (activeQuery.value.data.value?.meta.last_page ?? 1)) page.value = nextPage;
}

function changePageSize(value: string): void {
    const parsed = Number(value);
    if (pageSizes.includes(parsed)) {
        pageSize.value = parsed;
        page.value = 1;
    }
}

function toggleCreateForm(): void {
    showCreateForm.value = !showCreateForm.value;
    categoryName.value = '';
    locationName.value = '';
    locationDescription.value = '';
    createParentId.value = parentId.value ?? '';
    errors.value = { fields: {}, form: '' };
    success.value = '';
}

async function createCategory(): Promise<void> {
    if (createCategoryMutation.isPending.value) return;
    const context = `${String(route.name)}:${String(route.query.parent ?? '')}`;
    errors.value = { fields: {}, form: '' };
    success.value = '';
    try {
        const category = await createCategoryMutation.mutateAsync(categoryName.value.trim());
        if (context !== `${String(route.name)}:${String(route.query.parent ?? '')}`) return;
        categoryName.value = '';
        showCreateForm.value = false;
        success.value = `${category.name} was created.`;
    } catch (cause) {
        if (context === `${String(route.name)}:${String(route.query.parent ?? '')}`) errors.value = parseApiErrors(cause);
    }
}

async function createLocation(): Promise<void> {
    if (createLocationMutation.isPending.value) return;
    const context = `${String(route.name)}:${String(route.query.parent ?? '')}`;
    errors.value = { fields: {}, form: '' };
    success.value = '';
    try {
        const location = await createLocationMutation.mutateAsync({
            name: locationName.value.trim(),
            description: locationDescription.value.trim() || null,
            parent_id: createParentId.value && createParentId.value !== 'root' ? createParentId.value : null,
        });
        if (context !== `${String(route.name)}:${String(route.query.parent ?? '')}`) return;
        locationName.value = '';
        locationDescription.value = '';
        showCreateForm.value = false;
        success.value = `${location.name} was created.`;
    } catch (cause) {
        if (context === `${String(route.name)}:${String(route.query.parent ?? '')}`) errors.value = parseApiErrors(cause);
    }
}

function searchResultLocationLabel(location: StockLocation): string {
    return buildLocationPath(location, locationOptions.data.value ?? []);
}

async function browseChildren(id: string): Promise<void> {
    await router.push({ name: 'catalog-locations', query: { parent: id } });
}
</script>

<template>
    <section aria-labelledby="catalog-title">
        <p class="eyebrow">Browse your supplies</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 id="catalog-title" class="page-title">{{ isCategoriesPage ? 'Categories' : 'Locations' }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">
                    {{ isCategoriesPage ? 'Group related household items so they’re easier to find.' : 'Browse the rooms, shelves, and storage areas where supplies live.' }}
                </p>
            </div>
            <button type="button" :aria-expanded="showCreateForm" aria-controls="catalog-create-panel" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-sage px-4 text-sm font-semibold text-white transition hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="toggleCreateForm">
                <span aria-hidden="true">＋</span>
                {{ showCreateForm ? 'Cancel' : (isCategoriesPage ? 'Add category' : 'Add location') }}
            </button>
        </div>

        <InventoryTabs />

        <div v-if="!isCategoriesPage && locationOptions.isError.value" class="mt-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
            <p>Location hierarchy could not be loaded. Location names may not show their full paths.</p>
            <button type="button" class="mt-2 font-semibold underline" @click="locationOptions.refetch()">Retry location hierarchy</button>
        </div>

        <p v-if="success" class="mt-5 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm font-medium text-sage-dark" role="status">{{ success }}</p>

        <section v-if="showCreateForm" id="catalog-create-panel" class="mt-6 rounded-panel border border-line bg-white p-5 shadow-card sm:p-7" aria-labelledby="catalog-create-title">
            <p class="eyebrow">New {{ isCategoriesPage ? 'group' : 'storage place' }}</p>
            <h2 id="catalog-create-title" class="mt-1 text-xl font-semibold tracking-tight text-ink">{{ isCategoriesPage ? 'Add a category' : 'Add a location' }}</h2>
            <p v-if="errors.form" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ errors.form }}</p>
            <form v-if="isCategoriesPage" class="mt-5 grid max-w-xl gap-4" @submit.prevent="createCategory">
                <div class="grid gap-2">
                    <label for="category-name" class="text-sm font-medium text-ink">Category name <span aria-hidden="true">*</span></label>
                    <input id="category-name" v-model="categoryName" required maxlength="255" :aria-invalid="Boolean(errors.fields.name)" aria-describedby="category-name-error" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20">
                    <p v-if="errors.fields.name" id="category-name-error" class="text-sm text-rose-700">{{ errors.fields.name }}</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" :disabled="isPending || !categoryName.trim()" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-60">{{ createCategoryMutation.isPending.value ? 'Saving…' : 'Save category' }}</button>
                    <button type="button" class="min-h-11 rounded-md border border-line px-4 text-sm font-medium" @click="toggleCreateForm">Cancel</button>
                </div>
            </form>
            <form v-else class="mt-5 grid gap-4 sm:grid-cols-2" @submit.prevent="createLocation">
                <div class="grid gap-2">
                    <label for="location-name" class="text-sm font-medium text-ink">Location name <span aria-hidden="true">*</span></label>
                    <input id="location-name" v-model="locationName" required maxlength="255" :aria-invalid="Boolean(errors.fields.name)" aria-describedby="location-name-error" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20">
                    <p v-if="errors.fields.name" id="location-name-error" class="text-sm text-rose-700">{{ errors.fields.name }}</p>
                </div>
                <div class="grid gap-2">
                    <label for="location-parent" class="text-sm font-medium text-ink">Parent location <span class="font-normal text-ink-muted">(optional)</span></label>
                    <p v-if="locationOptions.isPending.value" class="text-sm text-ink-muted" role="status">Loading location options…</p>
                    <p v-else-if="locationOptions.isError.value" class="text-sm text-rose-700" role="alert">Location options could not be loaded. <button type="button" class="font-semibold underline" @click="locationOptions.refetch()">Try again</button></p>
                    <select id="location-parent" v-model="createParentId" class="min-h-12 rounded-md border border-line bg-white px-3.5 text-base text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20">
                        <option value="">No parent</option>
                        <option v-for="location in eligibleParents" :key="location.id" :value="location.id">{{ searchResultLocationLabel(location) }}</option>
                    </select>
                    <p v-if="errors.fields.parent_id" class="text-sm text-rose-700">{{ errors.fields.parent_id }}</p>
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <label for="location-description" class="text-sm font-medium text-ink">Description <span class="font-normal text-ink-muted">(optional)</span></label>
                    <textarea id="location-description" v-model="locationDescription" rows="3" class="min-h-24 resize-y rounded-md border border-line bg-white px-3.5 py-3 text-base leading-6 text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20" />
                </div>
                <div class="flex gap-3 sm:col-span-2">
                    <button type="submit" :disabled="isPending || !locationName.trim() || locationOptions.isPending.value || locationOptions.isError.value" class="min-h-11 rounded-md bg-sage px-4 text-sm font-semibold text-white disabled:opacity-60">{{ createLocationMutation.isPending.value ? 'Saving…' : 'Save location' }}</button>
                    <button type="button" class="min-h-11 rounded-md border border-line px-4 text-sm font-medium" @click="toggleCreateForm">Cancel</button>
                </div>
            </form>
        </section>

        <section class="mt-7 overflow-hidden rounded-panel border border-line bg-white shadow-card" :aria-busy="activeQuery.isFetching.value" :aria-labelledby="isCategoriesPage ? 'categories-heading' : 'locations-heading'">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div>
                    <h2 :id="isCategoriesPage ? 'categories-heading' : 'locations-heading'" class="text-base font-semibold text-ink">{{ isCategoriesPage ? 'Your categories' : currentParentPath }}</h2>
                    <RouterLink v-if="!isCategoriesPage && parentId" :to="{ name: 'catalog-locations', query: currentParent?.parent?.id ? { parent: currentParent.parent.id } : { parent: 'root' } }" class="mt-1 inline-block text-sm font-medium text-sage-dark hover:underline">← {{ currentParent?.parent?.name ?? 'All locations' }}</RouterLink>
                    <p v-if="activeQuery.data.value" class="mt-1 text-sm text-ink-muted" aria-live="polite">{{ activeQuery.data.value.meta.total }} {{ activeQuery.data.value.meta.total === 1 ? (isCategoriesPage ? 'category' : 'location') : (isCategoriesPage ? 'categories' : 'locations') }}</p>
                </div>
                <div class="flex w-full flex-wrap items-center justify-end gap-3 sm:w-auto">
                    <label class="sr-only" for="catalog-page-size">Rows per page</label>
                    <select id="catalog-page-size" :value="pageSize" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm text-ink" @change="changePageSize(($event.target as HTMLSelectElement).value)">
                        <option v-for="size in pageSizes" :key="size" :value="size">{{ size }} per page</option>
                    </select>
                    <div class="relative w-full sm:w-64">
                        <label for="catalog-search" class="sr-only">Search {{ isCategoriesPage ? 'categories' : 'locations' }}</label>
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted" aria-hidden="true">⌕</span>
                        <input id="catalog-search" v-model="search" type="search" :placeholder="`Search ${isCategoriesPage ? 'categories' : 'locations'}`" class="min-h-10 w-full rounded-md border border-line bg-canvas pl-9 pr-3 text-sm text-ink outline-none focus:border-sage focus:ring-2 focus:ring-sage/20">
                    </div>
                </div>
            </div>

            <div v-if="activeQuery.isPending.value" class="grid min-h-44 place-items-center p-6 text-sm text-ink-muted" role="status">Loading {{ isCategoriesPage ? 'categories' : 'locations' }}…</div>
            <div v-else-if="activeQuery.isError.value" class="grid min-h-44 place-items-center px-5 py-8 text-center" role="alert">
                <div><h3 class="font-semibold text-ink">{{ isCategoriesPage ? 'Categories' : 'Locations' }} could not be loaded</h3><p class="mt-2 text-sm text-ink-muted">{{ parseApiErrors(activeQuery.error.value).form || 'Try again in a moment.' }}</p><button type="button" class="mt-3 min-h-10 rounded-md border border-line px-3 text-sm font-medium" @click="activeQuery.refetch()">Try again</button></div>
            </div>
            <template v-else-if="isCategoriesPage && categoryQuery.data.value">
                <div v-if="categoryQuery.data.value.data.length === 0" class="grid min-h-44 place-items-center p-6 text-center"><div><h3 class="font-semibold text-ink">{{ debouncedSearch ? 'No categories match that search' : 'No categories yet' }}</h3><p class="mt-2 text-sm text-ink-muted">{{ debouncedSearch ? 'Try another name or clear your search.' : 'Create a category to group related items.' }}</p><button v-if="!debouncedSearch" type="button" class="mt-4 min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white" @click="toggleCreateForm">Add category</button></div></div>
                <ul v-else class="divide-y divide-line" aria-label="Categories">
                    <li v-for="category in categoryQuery.data.value.data" :key="category.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                        <RouterLink :to="{ name: 'category-detail', params: { category: category.id } }" class="min-h-10 rounded-sm text-base font-semibold text-ink hover:text-sage-dark hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">{{ category.name }}</RouterLink>
                        <span class="text-sm text-ink-muted">Browse category items →</span>
                    </li>
                </ul>
            </template>
            <template v-else-if="!isCategoriesPage && locationQuery.data.value">
                <div v-if="locationQuery.data.value.data.length === 0" class="grid min-h-44 place-items-center p-6 text-center"><div><h3 class="font-semibold text-ink">{{ debouncedSearch ? 'No locations match that search' : (parentId ? 'No child locations yet' : 'No locations yet') }}</h3><p class="mt-2 text-sm text-ink-muted">{{ debouncedSearch ? 'Try another name or clear your search.' : 'Create a location to map where household supplies are stored.' }}</p><button v-if="!debouncedSearch" type="button" class="mt-4 min-h-10 rounded-md bg-sage px-4 text-sm font-semibold text-white" @click="toggleCreateForm">Add location</button></div></div>
                <ul v-else class="divide-y divide-line" aria-label="Locations">
                    <li v-for="row in locationRows" :key="row.location.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <div class="min-w-0">
                            <RouterLink :to="{ name: 'location-detail', params: { location: row.location.id } }" class="break-words text-base font-semibold text-ink hover:text-sage-dark hover:underline">{{ row.location.name }}</RouterLink>
                            <p class="mt-1 whitespace-pre-line text-sm text-ink-muted">{{ row.path }}<span v-if="row.location.description"> · {{ row.location.description }}</span></p>
                        </div>
                        <button type="button" class="min-h-10 justify-self-start rounded-md border border-line px-3 text-sm font-semibold text-sage-dark hover:bg-sage-soft sm:justify-self-end" @click="browseChildren(row.location.id)">Browse children</button>
                    </li>
                </ul>
            </template>
            <div v-if="activeQuery.data.value && activeQuery.data.value.meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4 sm:px-6">
                <p class="text-sm text-ink-muted">Page {{ activeQuery.data.value.meta.current_page }} of {{ activeQuery.data.value.meta.last_page }}</p>
                <div class="flex gap-2"><button type="button" :disabled="page <= 1 || activeQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium disabled:opacity-50" @click="changePage(page - 1)">Previous</button><button type="button" :disabled="page >= activeQuery.data.value.meta.last_page || activeQuery.isFetching.value" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium disabled:opacity-50" @click="changePage(page + 1)">Next</button></div>
            </div>
        </section>
    </section>
</template>
