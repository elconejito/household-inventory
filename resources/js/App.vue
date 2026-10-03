<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query';
import { watch } from 'vue';
import { RouterView } from 'vue-router';
import { useSessionStore } from './stores/session';

const session = useSessionStore();
const queryClient = useQueryClient();

watch(
    () => [session.user?.id, session.user?.membership?.household?.id, session.user?.membership?.role],
    () => queryClient.clear(),
    { flush: 'sync' },
);
</script>

<template>
    <RouterView />
</template>
