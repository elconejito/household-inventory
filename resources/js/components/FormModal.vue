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
    <dialog ref="dialog" :aria-label="label" :aria-busy="busy" class="fixed inset-x-0 bottom-0 top-auto m-0 max-h-[89dvh] w-full max-w-none overflow-y-auto overscroll-contain rounded-t-xl pb-[env(safe-area-inset-bottom)] sm:inset-0 sm:m-auto sm:max-h-[calc(100dvh-2.5rem)] sm:w-[calc(100%-2rem)] sm:max-w-[30rem] sm:rounded-xl sm:pb-0 border border-line bg-white p-0 text-ink shadow-card backdrop:bg-ink/40" @cancel.prevent="!busy && emit('close')">
        <span aria-hidden="true" class="mx-auto mt-2 block h-1 w-8 rounded-full bg-line sm:hidden" />
        <slot />
    </dialog>
</template>
