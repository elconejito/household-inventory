<script setup lang="ts">
import type { StockIndicatorTone } from '../lib/stock-indicators';

withDefaults(defineProps<{
    label: string;
    tone?: StockIndicatorTone;
}>(), { tone: 'neutral' });

const toneClasses: Record<StockIndicatorTone, string> = {
    neutral: 'border-line bg-surface-soft text-ink-muted',
    low: 'border-amber-200 bg-amber-50 text-amber-800',
    empty: 'border-rose-200 bg-rose-50 text-rose-800',
    reminder: 'border-sage/20 bg-sage-soft text-sage-dark',
};
</script>

<template>
    <span class="inline-flex min-w-0 max-w-full items-center break-words rounded-md border px-2 py-0.5 text-xs font-medium" :class="toneClasses[tone]">{{ label }}</span>
</template>
