<script setup lang="ts">
import { useId } from 'vue';

withDefaults(defineProps<{ active?: boolean; label?: string }>(), { active: false, label: 'Filters' });
const popoverId = useId();
</script>

<template>
    <div class="shrink-0">
        <button type="button" :popovertarget="popoverId" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line px-4 text-sm font-semibold text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">
            {{ label }}
            <span v-if="active" class="size-2 rounded-full bg-sage" role="img" aria-label="Filters active"></span>
        </button>
        <div :id="popoverId" popover :aria-labelledby="`${popoverId}-heading`" class="fixed inset-0 m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-panel border border-line bg-white p-5 text-ink shadow-card backdrop:bg-ink/20 sm:p-6">
            <div class="mb-5 flex items-center justify-between gap-4">
                <h2 :id="`${popoverId}-heading`" class="text-base font-semibold">Filters and display</h2>
                <button type="button" :popovertarget="popoverId" popovertargetaction="hide" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Done</button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2"><slot /></div>
        </div>
    </div>
</template>
