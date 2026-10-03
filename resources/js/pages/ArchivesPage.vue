<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { parseApiErrors } from '../lib/api-errors';
import { useSessionStore } from '../stores/session';
import { useArchivedResourcesQuery, useHouseholdMutations } from '../queries/household';
import type { ArchivedType } from '../api/household';

const session = useSessionStore();
const owner = computed(() => session.user?.membership?.role === 'owner');
const selectedType = ref<ArchivedType>('items');
const query = useArchivedResourcesQuery(computed(() => selectedType.value));
const mutations = useHouseholdMutations();
const error = ref('');
const success = ref('');
const confirmingId = ref('');
const confirmationText = ref('');
const mutationPending = computed(() => mutations.restoreArchived.isPending.value || mutations.permanentlyDelete.isPending.value);
const typeLabels: Record<ArchivedType, string> = { items: 'Items', categories: 'Categories', locations: 'Locations' };

watch(selectedType, () => {
    confirmingId.value = '';
    confirmationText.value = '';
    error.value = '';
    success.value = '';
});

async function restore(id: string, name: string): Promise<void> {
    const type = selectedType.value;
    error.value = '';
    success.value = '';
    try {
        await mutations.restoreArchived.mutateAsync({ type, id });
        if (selectedType.value === type) success.value = `${name} restored.`;
    } catch (cause) {
        if (selectedType.value === type) error.value = parseApiErrors(cause).form || 'This resource could not be restored.';
    }
}

function requestPermanentDelete(id: string): void {
    confirmingId.value = id;
    confirmationText.value = '';
    error.value = '';
}

function cancelPermanentDelete(): void {
    confirmingId.value = '';
    confirmationText.value = '';
}

async function permanentlyDelete(id: string, name: string): Promise<void> {
    if (confirmationText.value !== 'DELETE') return;
    const type = selectedType.value;
    error.value = '';
    success.value = '';
    try {
        await mutations.permanentlyDelete.mutateAsync({ type, id });
        if (selectedType.value === type) {
            success.value = `${name} permanently deleted.`;
            cancelPermanentDelete();
        }
    } catch (cause) {
        if (selectedType.value === type) error.value = parseApiErrors(cause).form || 'This resource could not be permanently deleted.';
    }
}
</script>

<template>
    <section aria-labelledby="archives-title">
        <RouterLink to="/settings" class="text-sm font-medium text-sage-dark hover:underline">← Settings</RouterLink>
        <p class="eyebrow mt-5">Household tools</p>
        <h1 id="archives-title" class="page-title mt-2">Archived inventory</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-ink-muted">Restore an archived record or permanently remove it. Permanent deletion cannot be undone.</p>

        <nav class="mt-6 flex gap-2 overflow-x-auto" aria-label="Archived resource type">
            <button v-for="(label, type) in typeLabels" :key="type" type="button" :disabled="mutationPending" :aria-pressed="selectedType === type" class="min-h-10 shrink-0 rounded-md border px-4 text-sm font-medium transition disabled:cursor-wait disabled:opacity-50" :class="selectedType === type ? 'border-sage bg-sage-soft text-sage-dark' : 'border-line bg-white text-ink-muted hover:bg-surface-soft'" @click="selectedType = type">{{ label }}</button>
        </nav>

        <p v-if="success" class="mt-4 rounded-md border border-sage/20 bg-sage-soft px-4 py-3 text-sm text-sage-dark" role="status">{{ success }}</p>
        <p v-if="error" class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ error }}</p>

        <section class="mt-4 overflow-hidden rounded-panel border border-line bg-white shadow-card" :aria-label="`Archived ${typeLabels[selectedType].toLowerCase()}`" :aria-busy="query.isFetching.value">
            <div v-if="query.isPending.value" class="grid min-h-40 place-items-center p-6 text-sm text-ink-muted" role="status">Loading archived {{ typeLabels[selectedType].toLowerCase() }}…</div>
            <div v-else-if="query.isError.value" class="p-6" role="alert"><p class="font-semibold text-ink">Archived records could not be loaded</p><p class="mt-1 text-sm text-ink-muted">{{ parseApiErrors(query.error.value).form || 'Try again in a moment.' }}</p><button type="button" class="mt-3 rounded-md border border-line px-3 py-2 text-sm" @click="query.refetch()">Try again</button></div>
            <div v-else-if="!query.data.value?.length" class="grid min-h-40 place-items-center p-6 text-center"><div><h2 class="font-semibold text-ink">No archived {{ typeLabels[selectedType].toLowerCase() }}</h2><p class="mt-1 text-sm text-ink-muted">Archived {{ typeLabels[selectedType].toLowerCase() }} will appear here.</p></div></div>
            <ul v-else class="divide-y divide-line">
                <li v-for="resource in query.data.value" :key="resource.id" class="grid gap-4 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="min-w-0"><h2 class="truncate font-semibold text-ink">{{ resource.name }}</h2><p class="mt-1 text-sm text-ink-muted">{{ resource.counting_unit ? `Counted in ${resource.counting_unit}` : typeLabels[selectedType].slice(0, -1) }} · Archived</p></div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" :disabled="mutationPending" class="min-h-10 rounded-md border border-line px-3 text-sm font-semibold text-sage-dark hover:bg-sage-soft disabled:opacity-50" @click="restore(resource.id, resource.name)">Restore</button>
                        <button v-if="owner" type="button" :disabled="mutationPending" :aria-label="`Permanently delete ${resource.name}`" class="min-h-10 rounded-md px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="requestPermanentDelete(resource.id)">Permanently delete</button>
                    </div>
                    <form v-if="confirmingId === resource.id" class="grid gap-3 rounded-md border border-rose-200 bg-rose-50 p-4 sm:col-span-2" @submit.prevent="permanentlyDelete(resource.id, resource.name)">
                        <p class="text-sm leading-5 text-rose-900"><strong>This cannot be undone.</strong> Deleting “{{ resource.name }}” permanently removes its archived record{{ selectedType === 'items' ? ', stock history, notes, and photos' : '' }}. Type DELETE to confirm.</p>
                        <div class="flex flex-wrap gap-2"><input v-model="confirmationText" autocomplete="off" aria-label="Type DELETE to confirm" class="min-h-10 min-w-0 rounded-md border border-rose-300 bg-white px-3 text-sm text-ink outline-none focus:ring-2 focus:ring-rose-200"><button type="submit" :disabled="confirmationText !== 'DELETE' || mutations.permanentlyDelete.isPending.value" class="min-h-10 rounded-md bg-rose-700 px-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">{{ mutations.permanentlyDelete.isPending.value ? 'Deleting…' : 'Confirm permanent deletion' }}</button><button type="button" class="min-h-10 rounded-md border border-line bg-white px-3 text-sm" @click="cancelPermanentDelete">Cancel</button></div>
                    </form>
                </li>
            </ul>
        </section>
        <p class="mt-4 text-sm text-ink-muted">{{ owner ? 'You can restore archived records as a household member. Only owners can permanently delete them.' : 'You can restore archived records. Permanent deletion is available to household owners.' }}</p>
    </section>
</template>
