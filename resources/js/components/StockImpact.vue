<script setup lang="ts">
defineProps<{ label: string; before: number; after: number; unit: string }>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-sage-soft px-3 py-2.5 text-sm">
        <span>{{ label }}</span>
        <strong class="tabular-nums">{{ before }} <span aria-label="becomes" class="px-1 font-normal text-ink-muted">→</span> {{ after }} <span class="font-normal text-ink-muted">{{ unit }}</span></strong>
    </div>
</template>
