<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

withDefaults(defineProps<{ label: string; busy?: boolean }>(), { busy: false });
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

onMounted(() => {
    dialog.value?.showModal();
});

onBeforeUnmount(() => {
    dialog.value?.close();
});
</script>

<template>
    <dialog ref="dialog" :aria-label="label" :aria-busy="busy" class="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-3xl overflow-y-auto overscroll-contain rounded-panel border border-line bg-white p-0 text-ink shadow-card backdrop:bg-ink/40" @cancel.prevent="!busy && emit('close')">
        <slot />
    </dialog>
</template>
