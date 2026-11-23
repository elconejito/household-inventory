<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ShelfSession from '../components/ShelfSession.vue';

const route = useRoute();
const locationId = computed(() => String(route.params.location));
const mode = computed(() => route.name === 'location-count' ? 'count' : 'entry');
</script>

<template>
    <ShelfSession :key="mode + locationId" :location-id="locationId" :mode="mode" />
</template>
