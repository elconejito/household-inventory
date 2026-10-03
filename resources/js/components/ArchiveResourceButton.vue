<script setup lang="ts">
import { ref, watch } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { useRoute, useRouter } from 'vue-router';
import { http } from '../lib/http';
import { parseApiErrors } from '../lib/api-errors';

const props = defineProps<{ type: 'items' | 'categories' | 'locations'; id: string; label: string }>();
const queryClient = useQueryClient();
const router = useRouter();
const route = useRoute();
const error = ref('');
const pending = ref(false);

watch(() => [props.type, props.id], () => { error.value = ''; });

async function archive(): Promise<void> {
    if (pending.value || !window.confirm(`Archive ${props.label}? You can restore it later from Archived inventory.`)) return;
    const archivedType = props.type;
    const archivedId = props.id;
    pending.value = true;
    error.value = '';
    try {
        await http.delete(`/${archivedType}/${archivedId}`);
        const stillShowingArchivedResource = (archivedType === 'items' && route.name === 'inventory-item' && String(route.params.item) === archivedId)
            || (archivedType === 'categories' && route.name === 'category-detail' && String(route.params.category) === archivedId)
            || (archivedType === 'locations' && route.name === 'location-detail' && String(route.params.location) === archivedId);
        if (stillShowingArchivedResource) await router.push({ name: 'inventory' });
        await queryClient.invalidateQueries({ queryKey: [archivedType] });
    } catch (cause) {
        error.value = parseApiErrors(cause).form || 'This record could not be archived. Resolve any dependent records and try again.';
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <div class="grid justify-items-start gap-2">
        <button type="button" :disabled="pending" class="min-h-10 rounded-md border border-rose-200 px-3 text-sm font-medium text-rose-800 hover:bg-rose-50 disabled:opacity-50" @click="archive">{{ pending ? 'Archiving…' : `Archive ${label.toLowerCase()}` }}</button>
        <p v-if="error" class="max-w-xl text-sm text-rose-700" role="alert">{{ error }}</p>
    </div>
</template>
