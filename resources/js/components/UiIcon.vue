<script setup lang="ts">
export type UiIconName = 'home' | 'box' | 'pin' | 'tag' | 'layers' | 'clock' | 'search' | 'more' | 'user' | 'close' | 'plus' | 'chevron' | 'check';

withDefaults(defineProps<{ name: UiIconName; size?: number }>(), { size: 18 });

const paths: Record<UiIconName, string> = {
    home: 'M3 10.5 12 3l9 7.5M5.5 9v11h13V9M10 20v-6h4v6',
    box: 'm3 7 9-4.5L21 7l-9 4.5L3 7Zm0 0v10l9 4.5 9-4.5V7m-9 4.5v10m-4.5-17 9 4.5',
    pin: 'M19.5 9.5c0 5.5-7.5 12-7.5 12s-7.5-6.5-7.5-12a7.5 7.5 0 0 1 15 0Zm-5 0a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0Z',
    tag: 'M3 4h9l9 9-8 8-10-10V4Zm5 4h.1',
    layers: 'm12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5m-18 5 9 5 9-5',
    clock: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 6v6l4 2',
    search: 'M16 16l5 5m-3-11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
    more: 'M4 12h1m6.5 0h1m6.5 0h1',
    user: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    close: 'm6 6 12 12m0-12L6 18',
    plus: 'M12 5v14M5 12h14',
    chevron: 'm9 5 7 7-7 7',
    check: 'm4 12 5 5L20 6',
};
</script>

<template>
    <svg class="ui-icon" :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name]" />
    </svg>
</template>

<style scoped>
.ui-icon {
    display: inline-block;
    flex: none;
    vertical-align: middle;
}
</style>
