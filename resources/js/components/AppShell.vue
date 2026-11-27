<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import GlobalSearch from './GlobalSearch.vue';
import UiIcon, { type UiIconName } from './UiIcon.vue';
import { useSessionStore } from '../stores/session';

const router = useRouter();
const route = useRoute();
const session = useSessionStore();
const logoutError = ref('');
const mainContent = ref<HTMLElement | null>(null);
const accountMenu = ref<HTMLDetailsElement | null>(null);
const mobileMoreMenu = ref<HTMLDetailsElement | null>(null);
const navigation: Array<{ label: string; mobileLabel: string; to: string; icon: UiIconName; activeOn: string[] }> = [
    { label: 'Dashboard', mobileLabel: 'Home', to: '/', icon: 'home', activeOn: ['dashboard'] },
    { label: 'Items', mobileLabel: 'Items', to: '/items', icon: 'box', activeOn: ['inventory', 'inventory-item'] },
    { label: 'Locations', mobileLabel: 'Locations', to: '/locations', icon: 'pin', activeOn: ['catalog-locations', 'location-detail', 'location-intake', 'location-count'] },
    { label: 'Categories', mobileLabel: 'Categories', to: '/categories', icon: 'tag', activeOn: ['catalog-categories', 'category-detail'] },
    { label: 'Activity', mobileLabel: 'Activity', to: '/activity', icon: 'clock', activeOn: ['activity'] },
];
const mobileMoreActive = computed(() => ['catalog-categories', 'category-detail', 'activity', 'settings', 'archives'].includes(String(route.name)));

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

function closeAccountMenu(): void {
    accountMenu.value?.removeAttribute('open');
}

function closeMobileMoreMenu(): void {
    mobileMoreMenu.value?.removeAttribute('open');
}

function closeNavigationMenus(): void {
    closeAccountMenu();
    closeMobileMoreMenu();
}

function dismissNavigationMenus(event: Event): void {
    if (event instanceof KeyboardEvent && event.key !== 'Escape') return;
    if (event instanceof MouseEvent && (accountMenu.value?.contains(event.target as Node) || mobileMoreMenu.value?.contains(event.target as Node))) return;
    closeNavigationMenus();
}

onMounted(() => {
    document.addEventListener('click', dismissNavigationMenus);
    document.addEventListener('keydown', dismissNavigationMenus);
});

onUnmounted(() => {
    document.removeEventListener('click', dismissNavigationMenus);
    document.removeEventListener('keydown', dismissNavigationMenus);
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
    <div class="app-layout">
        <a href="#main-content" class="sr-only z-50 rounded-md bg-white px-4 py-3 font-semibold text-sage-dark shadow-card focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage">Skip to main content</a>
        <header class="app-header">
            <div class="app-header-inner">
                <RouterLink to="/" class="brand" aria-label="Household home">
                    <span class="brand-mark"><UiIcon name="home" :size="23" /></span>
                    <span>Household</span>
                </RouterLink>

                <nav class="primary-navigation" aria-label="Main navigation">
                    <RouterLink
                        v-for="item in navigation"
                        :key="item.label"
                        :to="item.to"
                        class="nav-link"
                        :class="{ 'nav-link-active': item.activeOn.includes(String(route.name)) }"
                        :aria-current="item.activeOn.includes(String(route.name)) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </RouterLink>
                </nav>

                <div class="header-utilities">
                    <GlobalSearch @open="closeNavigationMenus" />
                    <details ref="accountMenu" class="relative">
                        <summary class="account-trigger">
                            <span class="account-avatar" aria-hidden="true">{{ initials }}</span>
                            <span>Account</span>
                        </summary>
                        <div class="account-menu">
                            <div class="border-b border-line px-3 py-2">
                                <p class="truncate text-sm font-semibold text-ink">{{ session.user?.name }}</p>
                                <p class="truncate text-xs text-ink-muted">{{ session.user?.membership?.household?.name }}</p>
                            </div>
                            <RouterLink to="/settings" class="account-menu-link" @click="closeAccountMenu">Settings</RouterLink>
                            <button type="button" class="account-menu-link" @click="signOut">Sign out</button>
                        </div>
                    </details>
                </div>
            </div>
            <p v-if="logoutError" class="logout-error" role="alert">{{ logoutError }}</p>
        </header>

        <main id="main-content" ref="mainContent" tabindex="-1" class="app-main">
            <RouterView />
        </main>

        <footer class="app-footer">
            <div class="app-footer-inner"><span class="footer-brand"><UiIcon name="home" :size="16" /><b>Household Inventory</b></span><span>{{ session.user?.membership?.household?.name }}</span></div>
        </footer>

        <nav class="mobile-navigation" aria-label="Mobile navigation">
            <RouterLink v-for="item in navigation.slice(0, 3)" :key="item.label" :to="item.to" class="mobile-link" :class="{ 'mobile-link-active': item.activeOn.includes(String(route.name)) }" :aria-current="item.activeOn.includes(String(route.name)) ? 'page' : undefined">
                <span class="mobile-icon"><UiIcon :name="item.icon" :size="18" /></span><span>{{ item.mobileLabel }}</span>
            </RouterLink>
            <details ref="mobileMoreMenu" class="mobile-more">
                <summary class="mobile-link" :class="{ 'mobile-link-active': mobileMoreActive }" :aria-current="mobileMoreActive ? 'page' : undefined"><span class="mobile-icon"><UiIcon name="more" :size="18" /></span><span>More</span></summary>
                <div class="mobile-more-panel" aria-label="More navigation">
                    <RouterLink v-for="item in navigation.slice(3)" :key="item.label" :to="item.to" class="mobile-more-link" @click="closeMobileMoreMenu">{{ item.label }}</RouterLink>
                    <RouterLink to="/settings" class="mobile-more-link" @click="closeMobileMoreMenu">Account &amp; settings</RouterLink>
                </div>
            </details>
        </nav>
    </div>
</template>
