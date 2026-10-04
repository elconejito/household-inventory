<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { getMovements } from '../api/stock';
import type { Location } from '../api/stock';
import { stockQueryKeys } from '../queries/stock';
import MovementTimeline from './MovementTimeline.vue';

const props = defineProps<{ itemId: string; locations?: Location[] }>();
const filters = computed(() => ({ page: 1, perPage: 10, itemId: props.itemId, locationId: '', locationMode: 'either' as const, movementType: '' }));
const movementsQuery = useQuery({
    queryKey: computed(() => stockQueryKeys.movementList(filters.value)),
    queryFn: () => getMovements(filters.value),
    enabled: computed(() => Boolean(props.itemId)),
});
const recentMovements = computed(() => movementsQuery.data.value?.data.slice(0, 5) ?? []);
</script>

<template>
    <section class="mt-6 rounded-panel border border-line bg-white shadow-card" aria-labelledby="recent-activity-title" :aria-busy="movementsQuery.isFetching.value">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6"><h2 id="recent-activity-title" class="text-base font-semibold text-ink">Recent activity</h2><RouterLink :to="{ name: 'activity', query: { item_id: itemId } }" class="text-sm font-semibold text-sage-dark hover:underline">View all activity</RouterLink></div>
        <div v-if="movementsQuery.isPending.value" class="grid min-h-28 place-items-center p-6 text-sm text-ink-muted" role="status">Loading recent activity…</div>
        <div v-else-if="movementsQuery.isError.value" class="p-6" role="alert"><p class="text-sm text-rose-700">Recent activity could not be loaded.</p><button type="button" class="mt-3 rounded-md border border-line px-4 py-2 text-sm" @click="movementsQuery.refetch()">Try again</button></div>
        <div v-else-if="!recentMovements.length" class="p-6 text-sm text-ink-muted">No activity recorded for this item yet.</div>
        <MovementTimeline v-else :movements="recentMovements" :locations="locations" :show-item-name="false" />
    </section>
</template>
