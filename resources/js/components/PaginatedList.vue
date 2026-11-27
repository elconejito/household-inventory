<script setup lang="ts" generic="T">
import { computed, ref, watch } from 'vue';
import ListPagination from './ListPagination.vue';

const props = defineProps<{ items: T[]; label: string; singular?: string; plural?: string; currentIndex?: number; resetKey?: string }>();
const page = ref(1);
const perPage = ref(10);
const lastPage = computed(() => Math.max(1, Math.ceil(props.items.length / perPage.value)));
const rows = computed(() => props.items.slice((page.value - 1) * perPage.value, page.value * perPage.value));
const meta = computed(() => ({ current_page: page.value, last_page: lastPage.value, total: props.items.length }));

watch(() => props.resetKey, () => { page.value = 1; });
watch(lastPage, (last) => { page.value = Math.min(page.value, last); });
watch([() => props.currentIndex, perPage], ([index]) => {
    if (index !== undefined) page.value = Math.min(lastPage.value, Math.floor(index / perPage.value) + 1);
}, { immediate: true });
</script>

<template>
    <slot :rows="rows" />
    <ListPagination v-model:per-page="perPage" :label="label" :singular="singular" :plural="plural" :meta="meta" :page="page" @change="page = $event" />
</template>
