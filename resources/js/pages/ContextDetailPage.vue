<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { useRoute } from 'vue-router';
import NotesPanel from '../components/NotesPanel.vue';
import { getCategory, getLocation } from '../api/note-contexts';
import { buildLocationPath } from '../api/stock';
import type { ItemLocation } from '../api/items';

const route = useRoute();
const contextType = computed(() => route.name === 'category-detail' ? 'categories' as const : 'locations' as const);
const contextId = computed(() => String(contextType.value === 'categories' ? route.params.category : route.params.location));
const contextQuery = useQuery({
    queryKey: computed(() => [contextType.value, 'detail', contextId.value]),
    queryFn: () => contextType.value === 'categories' ? getCategory(contextId.value) : getLocation(contextId.value),
});
const title = computed(() => contextType.value === 'categories' ? 'Category' : 'Location');
const locationPath = computed(() => {
    const location = contextQuery.data.value as ItemLocation | undefined;
    if (contextType.value !== 'locations' || !location) return '';
    return buildLocationPath(location, [location, ...(location.parent ? [location.parent] : [])]);
});
</script>

<template>
    <section aria-labelledby="context-title">
        <RouterLink :to="{ name: 'inventory' }" class="text-sm font-medium text-sage-dark hover:underline">← Inventory</RouterLink>
        <div v-if="contextQuery.isPending.value" class="mt-5 grid min-h-40 place-items-center text-sm text-ink-muted" role="status">Loading {{ title.toLowerCase() }}…</div>
        <div v-else-if="contextQuery.isError.value" class="mt-5 rounded-panel border border-line bg-white p-6" role="alert"><h1 id="context-title" class="text-xl font-semibold text-ink">We couldn’t load this {{ title.toLowerCase() }}</h1><button type="button" class="mt-3 text-sm text-sage-dark underline" @click="contextQuery.refetch()">Try again</button></div>
        <template v-else-if="contextQuery.data.value">
            <p class="eyebrow mt-5">{{ title }}</p>
            <h1 id="context-title" class="page-title mt-2">{{ contextQuery.data.value.name }}</h1>
            <p v-if="contextType === 'locations' && locationPath" class="mt-2 text-sm text-ink-muted">{{ locationPath }}</p>
            <p v-if="'description' in contextQuery.data.value && contextQuery.data.value.description" class="mt-2 text-sm text-ink-muted">{{ contextQuery.data.value.description }}</p>
            <NotesPanel :key="`${contextType}:${contextId}`" class="mt-6" :type="contextType" :context-id="contextId" />
        </template>
    </section>
</template>
