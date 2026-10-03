<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useSessionStore } from '../stores/session';

const router = useRouter();
const session = useSessionStore();
const retrying = ref(false);
const failed = ref(false);

async function retry(): Promise<void> {
    if (retrying.value) {
        return;
    }

    retrying.value = true;
    failed.value = false;

    try {
        await session.ensureLoaded();
        const destination = session.takePendingDestination() ?? '/';
        await router.replace(destination);
    } catch {
        failed.value = true;
    } finally {
        retrying.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-canvas px-5 py-10 sm:px-8">
        <section class="w-full max-w-md rounded-panel border border-line bg-white p-6 shadow-card sm:p-9" aria-labelledby="session-unavailable-title">
            <p class="eyebrow">Connection problem</p>
            <h1 id="session-unavailable-title" class="mt-2 text-2xl font-semibold tracking-tight text-ink">We couldn’t check your session</h1>
            <p class="mt-3 text-sm leading-6 text-ink-muted">Your page is safe. Check your connection, then try again.</p>
            <p v-if="failed" class="mt-4 text-sm text-rose-700" role="alert">We still couldn’t reach the server. Your page is still waiting here.</p>
            <button type="button" class="mt-6 min-h-11 rounded-md bg-sage px-4 py-2 text-sm font-semibold text-white hover:bg-sage-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage disabled:opacity-60" :disabled="retrying" @click="retry">
                {{ retrying ? 'Checking…' : 'Retry' }}
            </button>
        </section>
    </main>
</template>
