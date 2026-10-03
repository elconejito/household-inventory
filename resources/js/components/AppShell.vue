<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useSessionStore } from '../stores/session';

const router = useRouter();
const session = useSessionStore();
const logoutError = ref('');
const navigation = [
    { label: 'Dashboard', to: '/', icon: '⌂' },
    { label: 'Inventory', to: '/inventory', icon: '▤' },
    { label: 'Activity', to: '/activity', icon: '↻' },
];

const initials = computed(() => session.user?.name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') ?? '');

async function signOut(): Promise<void> {
    logoutError.value = '';

    try {
        await session.logout();
        await router.push({ name: 'login' });
    } catch {
        logoutError.value = 'We could not sign you out. Please try again.';
    }
}
</script>

<template>
    <div class="min-h-screen pb-24 md:pb-0">
        <header class="border-b border-line bg-white/90">
            <div class="mx-auto flex min-h-[72px] max-w-7xl items-center justify-between gap-3 px-4 sm:px-8 max-[360px]:gap-1">
                <RouterLink to="/" class="flex shrink-0 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sage" aria-label="Household Inventory home">
                    <span class="grid size-10 place-items-center rounded-[10px] bg-sage text-lg font-semibold text-white" aria-hidden="true">H</span>
                    <span class="hidden text-[15px] font-semibold tracking-tight sm:inline">Household Inventory</span>
                    <span class="text-[15px] font-semibold tracking-tight max-[360px]:hidden sm:hidden">Home inventory</span>
                </RouterLink>

                <nav class="hidden items-center gap-1 md:flex" aria-label="Main navigation">
                    <RouterLink
                        v-for="item in navigation"
                        :key="item.label"
                        :to="item.to"
                        exact-active-class="nav-link-active"
                        class="nav-link"
                    >
                        {{ item.label }}
                    </RouterLink>
                </nav>

                <div class="flex shrink-0 items-center gap-3 max-[360px]:gap-1">
                    <div class="hidden text-right sm:block">
                        <p class="max-w-40 truncate text-sm font-semibold text-ink">{{ session.user?.name }}</p>
                        <p class="max-w-40 truncate text-xs text-ink-muted">{{ session.user?.membership?.household?.name }}</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-md border border-line bg-sage-soft text-sm font-semibold text-sage-dark max-[360px]:hidden" aria-hidden="true">{{ initials }}</span>
                    <RouterLink to="/settings" class="inline-flex min-h-10 items-center rounded-md px-2 text-xs font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage sm:px-3 sm:text-sm">Settings</RouterLink>
                    <button
                        type="button"
                        class="min-h-10 rounded-md border border-line bg-white px-3 text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage"
                        @click="signOut"
                    >
                        <span class="hidden sm:inline">Sign out</span>
                        <span class="sm:hidden">Exit</span>
                    </button>
                </div>
            </div>
            <p v-if="logoutError" class="mx-auto max-w-7xl px-5 pb-3 text-right text-sm text-rose-700 sm:px-8" role="alert">{{ logoutError }}</p>
        </header>

        <main class="mx-auto w-full max-w-7xl px-5 py-8 sm:px-8 sm:py-10">
            <RouterView />
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-10 grid grid-cols-3 border-t border-line bg-white/95 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2 backdrop-blur md:hidden" aria-label="Main navigation">
            <RouterLink
                v-for="item in navigation"
                :key="item.label"
                :to="item.to"
                exact-active-class="mobile-link-active"
                class="mobile-link"
            >
                <span class="text-xl leading-none" aria-hidden="true">{{ item.icon }}</span>
                <span>{{ item.label }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
