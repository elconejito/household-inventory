<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';

const props = withDefaults(defineProps<{
    meta: { current_page: number; last_page: number; total: number };
    page: number;
    busy?: boolean;
    singular?: string;
    plural?: string;
    label?: string;
    inputId?: string;
}>(), { busy: false, singular: 'item', plural: 'items', label: 'Pagination' });
const emit = defineEmits<{ change: [page: number] }>();
const generatedId = useId();
const inputId = computed(() => props.inputId ?? generatedId);
const pageInput = ref<number | string>(props.page);
watch(() => props.page, (value) => { pageInput.value = value; });

const paginationPages = computed(() => {
    const lastPage = props.meta.last_page;
    const candidates = new Set([1, 2, lastPage - 1, lastPage, props.page - 1, props.page, props.page + 1]);
    const pages = [...candidates].filter((value) => value >= 1 && value <= lastPage).sort((a, b) => a - b);
    const result: (number | string)[] = [];

    pages.forEach((value, index) => {
        const previous = pages[index - 1];
        if (previous !== undefined && value - previous > 1) {
            result.push(value - previous === 2 ? previous + 1 : `gap-${value}`);
        }
        result.push(value);
    });

    return result;
});

function changePage(nextPage: number): void {
    if (!props.busy && Number.isInteger(nextPage) && nextPage >= 1 && nextPage <= props.meta.last_page) {
        emit('change', nextPage);
    }
}

function jumpToPage(): void {
    changePage(Number(pageInput.value));
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-line px-5 py-4 sm:px-6">
        <p class="text-sm text-ink-muted" aria-live="polite">{{ meta.total }} {{ meta.total === 1 ? singular : plural }} · Page {{ meta.current_page }} of {{ meta.last_page }}</p>
        <nav :aria-label="label" class="flex flex-wrap items-center gap-2">
            <button type="button" :disabled="page <= 1 || busy" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-not-allowed disabled:opacity-50" @click="changePage(page - 1)">Previous</button>
            <template v-for="entry in paginationPages" :key="entry">
                <button v-if="typeof entry === 'number'" type="button" :aria-label="`Page ${entry}`" :aria-current="entry === page ? 'page' : undefined" :disabled="busy" class="min-h-10 min-w-10 rounded-md border px-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:opacity-50" :class="entry === page ? 'border-sage bg-sage text-white' : 'border-line text-ink hover:bg-surface-soft'" @click="changePage(entry)">{{ entry }}</button>
                <span v-else class="text-ink-muted" aria-hidden="true">…</span>
            </template>
            <button type="button" :disabled="page >= meta.last_page || busy" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink transition hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:cursor-not-allowed disabled:opacity-50" @click="changePage(page + 1)">Next</button>
            <form class="flex items-center gap-2" @submit.prevent="jumpToPage">
                <label :for="inputId" class="text-sm text-ink-muted">Go to page</label>
                <input :id="inputId" v-model="pageInput" type="number" min="1" :max="meta.last_page" step="1" required class="min-h-10 w-20 rounded-md border border-line bg-white px-2 text-sm text-ink focus:border-sage focus:outline-sage">
                <button type="submit" :disabled="busy" class="min-h-10 rounded-md border border-line px-3 text-sm font-medium text-ink hover:bg-surface-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:opacity-50">Go</button>
            </form>
        </nav>
    </div>
</template>
