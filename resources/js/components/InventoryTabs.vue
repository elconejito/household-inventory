<script setup lang="ts">
import { useRoute } from 'vue-router';

const route = useRoute();
const tabs = [
    { label: 'Items', to: { name: 'inventory' }, activeOn: ['inventory', 'inventory-item'] },
    { label: 'Locations', to: { name: 'catalog-locations' }, activeOn: ['catalog-locations', 'location-detail'] },
    { label: 'Categories', to: { name: 'catalog-categories' }, activeOn: ['catalog-categories', 'category-detail'] },
];
</script>

<template>
    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-line" aria-label="Inventory browsing">
        <RouterLink
            v-for="tab in tabs"
            :key="tab.label"
            :to="tab.to"
            :aria-current="tab.activeOn.includes(String(route.name)) ? 'page' : undefined"
            class="min-h-11 shrink-0 border-b-2 px-4 py-2.5 text-sm font-semibold transition"
            :class="tab.activeOn.includes(String(route.name)) ? 'border-sage text-sage-dark' : 'border-transparent text-ink-muted hover:text-ink'"
        >
            {{ tab.label }}
        </RouterLink>
    </nav>
</template>
