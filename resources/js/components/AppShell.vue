<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { useSessionStore } from '../stores/session';

const router = useRouter();
const route = useRoute();
const session = useSessionStore();
const logoutError = ref('');
const mainContent = ref<HTMLElement | null>(null);
const accountMenu = ref<HTMLDetailsElement | null>(null);
const globalSearch = ref('');
const navigation = [
    { label: 'Dashboard', mobileLabel: 'Home', to: '/', icon: '⌂', activeOn: ['dashboard'] },
    { label: 'Items', mobileLabel: 'Items', to: '/items', icon: '▤', activeOn: ['inventory', 'inventory-item'] },
    { label: 'Locations', mobileLabel: 'Locations', to: '/locations', icon: '⌖', activeOn: ['catalog-locations', 'location-detail'] },
    { label: 'Categories', mobileLabel: 'Categories', to: '/categories', icon: '◇', activeOn: ['catalog-categories', 'category-detail'] },
    { label: 'Activity', mobileLabel: 'Activity', to: '/activity', icon: '↻', activeOn: ['activity'] },
];

const initials = computed(() => session.user?.name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') ?? '');

watch(() => route.path, async () => {
    await nextTick();

    const heading = mainContent.value?.querySelector('h1');
    const target = heading ?? mainContent.value;
    if (!target) return;

    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
}, { flush: 'post' });

watch(() => route.query.search, (value) => {
    globalSearch.value = typeof value === 'string' ? value : '';
}, { immediate: true });

async function submitSearch(): Promise<void> {
    const search = globalSearch.value.trim();
    accountMenu.value?.removeAttribute('open');
    await router.push({ name: 'inventory', query: search ? { search } : {} });
}

function closeAccountMenu(): void {
    accountMenu.value?.removeAttribute('open');
}

function dismissAccountMenu(event: Event): void {
    if (event instanceof KeyboardEvent && event.key !== 'Escape') return;
    if (event instanceof MouseEvent && accountMenu.value?.contains(event.target as Node)) return;
    closeAccountMenu();
}

onMounted(() => {
    document.addEventListener('click', dismissAccountMenu);
    document.addEventListener('keydown', dismissAccountMenu);
});

onUnmounted(() => {
    document.removeEventListener('click', dismissAccountMenu);
    document.removeEventListener('keydown', dismissAccountMenu);
});

async function signOut(): Promise<void> {
    logoutError.value = '';
    closeAccountMenu();

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
        <a href="#main-content" class="sr-only z-50 rounded-md bg-white px-4 py-3 font-semibold text-sage-dark shadow-card focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Skip to main content</a>
        <header class="border-b border-line bg-white/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 sm:px-8 max-[360px]:gap-1">
                <RouterLink to="/" class="flex shrink-0 items-center gap-3 rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sage" aria-label="Household home">
                    <span class="grid size-10 place-items-center rounded-[10px] bg-sage text-lg font-semibold text-white" aria-hidden="true">H</span>
                    <span class="hidden text-[15px] font-semibold tracking-tight sm:inline">Household</span>
                    <span class="text-[15px] font-semibold tracking-tight sm:hidden">Household</span>
                </RouterLink>

                <nav class="hidden items-center gap-1 md:flex" aria-label="Main navigation">
                    <RouterLink
                        v-for="item in navigation"
                        :key="item.label"
                        :to="item.to"
                        class="nav-link md:px-2 lg:px-3 xl:px-4"
                        :class="{ 'nav-link-active': item.activeOn.includes(String(route.name)) }"
                        :aria-current="item.activeOn.includes(String(route.name)) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </RouterLink>
                </nav>

                <form class="relative order-last w-full min-w-0" role="search" @submit.prevent="submitSearch">
                    <label for="global-search" class="sr-only">Search items</label>
                    <input
                        id="global-search"
                        v-model="globalSearch"
                        type="search"
                        autocomplete="off"
                        placeholder="Search items"
                        class="min-h-10 w-full rounded-md border border-line bg-canvas py-2 pl-3 pr-12 text-sm text-ink outline-none placeholder:text-ink-muted focus:border-sage focus:ring-2 focus:ring-sage/20"
                    >
                    <button type="submit" class="absolute right-1 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded text-lg text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" aria-label="Search items">⌕</button>
                </form>

                <details ref="accountMenu" class="relative ml-auto shrink-0">
                    <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-md border border-line bg-white px-3 text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">
                        <span class="grid size-7 place-items-center rounded-md bg-sage-soft text-xs font-semibold text-sage-dark" aria-hidden="true">{{ initials }}</span>
                        <span>Account</span>
                    </summary>
                    <div class="absolute right-0 z-20 mt-2 grid w-52 gap-1 rounded-md border border-line bg-white p-2 shadow-card">
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-sm font-semibold text-ink">{{ session.user?.name }}</p>
                            <p class="truncate text-xs text-ink-muted">{{ session.user?.membership?.household?.name }}</p>
                        </div>
                        <RouterLink to="/settings" class="inline-flex min-h-10 items-center rounded-md px-3 text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="closeAccountMenu">Settings</RouterLink>
                        <button type="button" class="min-h-10 rounded-md px-3 text-left text-sm font-medium text-ink-muted transition hover:bg-surface-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage" @click="signOut">Sign out</button>
                    </div>
                </details>
            </div>
            <p v-if="logoutError" class="mx-auto max-w-7xl px-5 pb-3 text-right text-sm text-rose-700 sm:px-8" role="alert">{{ logoutError }}</p>
        </header>

        <main id="main-content" ref="mainContent" tabindex="-1" class="mx-auto w-full max-w-7xl px-5 py-8 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage sm:px-8 sm:py-10">
            <RouterView />
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-10 grid grid-cols-5 border-t border-line bg-white/95 px-1 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2 backdrop-blur md:hidden" aria-label="Main navigation">
            <RouterLink
                v-for="item in navigation"
                :key="item.label"
                :to="item.to"
                class="mobile-link"
                :class="{ 'mobile-link-active': item.activeOn.includes(String(route.name)) }"
                :aria-current="item.activeOn.includes(String(route.name)) ? 'page' : undefined"
            >
                <span class="text-xl leading-none" aria-hidden="true">{{ item.icon }}</span>
                <span>{{ item.mobileLabel }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
