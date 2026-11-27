import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import ActivityPage from '../pages/ActivityPage.vue';
import DashboardPage from '../pages/DashboardPage.vue';
import InventoryPage from '../pages/InventoryPage.vue';
import ItemDetailPage from '../pages/ItemDetailPage.vue';
import ShelfSessionPage from '../pages/ShelfSessionPage.vue';
import ContextDetailPage from '../pages/ContextDetailPage.vue';
import CatalogBrowsePage from '../pages/CatalogBrowsePage.vue';
import LoginPage from '../pages/LoginPage.vue';
import RegisterPage from '../pages/RegisterPage.vue';
import SettingsPage from '../pages/SettingsPage.vue';
import ArchivesPage from '../pages/ArchivesPage.vue';
import InvitationAcceptPage from '../pages/InvitationAcceptPage.vue';
import SessionUnavailablePage from '../pages/SessionUnavailablePage.vue';
import { useSessionStore } from '../stores/session';

declare module 'vue-router' {
    interface RouteMeta {
        guestOnly?: boolean;
        requiresAuth?: boolean;
    }
}

export const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: LoginPage,
        meta: { guestOnly: true },
    },
    {
        path: '/register',
        name: 'register',
        component: RegisterPage,
        meta: { guestOnly: true },
    },
    { path: '/invitations/accept', name: 'invitation-accept', component: InvitationAcceptPage },
    { path: '/session-unavailable', name: 'session-unavailable', component: SessionUnavailablePage },
    {
        path: '/',
        component: AppShell,
        meta: { requiresAuth: true },
        children: [
            {
                path: '',
                name: 'dashboard',
                component: DashboardPage,
            },
            {
                path: 'items',
                name: 'inventory',
                component: InventoryPage,
            },
            { path: 'locations', name: 'catalog-locations', component: CatalogBrowsePage },
            { path: 'categories', name: 'catalog-categories', component: CatalogBrowsePage },
            {
                path: 'items/:item',
                name: 'inventory-item',
                component: ItemDetailPage,
            },
            { path: 'categories/:category', name: 'category-detail', component: ContextDetailPage },
            { path: 'locations/:location/intake', name: 'location-intake', component: ShelfSessionPage },
            { path: 'locations/:location/count', name: 'location-count', component: ShelfSessionPage },
            { path: 'locations/:location', name: 'location-detail', component: ContextDetailPage },
            {
                path: 'activity',
                name: 'activity',
                component: ActivityPage,
            },
            { path: 'settings', name: 'settings', component: SettingsPage },
            { path: 'settings/archives', name: 'archives', component: ArchivesPage },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: { name: 'dashboard' },
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: async (to, from, savedPosition) => {
        if (savedPosition) {
            if (to.name === 'inventory' && from.name === 'inventory-item') {
                if (!hasInventoryBrowseMetadata(from.query)) {
                    return { left: 0, top: 0 };
                }

                return waitForInventoryRow(String(from.params.item ?? ''), savedPosition);
            }

            return savedPosition;
        }

        if (to.name === 'inventory-item' && from.name === 'inventory') {
            return { left: 0, top: 0 };
        }

        if (to.name === 'inventory' && from.name === 'inventory-item' && hasInventoryBrowseMetadata(from.query)) {
            return waitForInventoryRow(String(from.params.item ?? ''), { left: 0, top: lastInventoryScrollPosition });
        }

        return to.name === from.name ? false : { left: 0, top: 0 };
    },
});

let lastInventoryScrollPosition = 0;
let scrollNavigationVersion = 0;

function hasInventoryBrowseMetadata(query: Record<string, unknown>): boolean {
    return typeof query.resultIds === 'string' && query.resultIds.length > 0;
}

function waitForInventoryRow(itemId: string, position: { left: number; top: number }): Promise<{ left: number; top: number } | false> {
    if (!itemId) {
        return Promise.resolve(position);
    }

    const navigationVersion = scrollNavigationVersion;
    return new Promise((resolve) => {
        const deadline = performance.now() + 3000;
        const checkForRow = (): void => {
            if (navigationVersion !== scrollNavigationVersion) {
                resolve(false);
                return;
            }

            if (document.getElementById(`inventory-item-${itemId}`) || performance.now() >= deadline) {
                resolve(position);
                return;
            }

            window.requestAnimationFrame(checkForRow);
        };

        checkForRow();
    });
}

router.beforeEach((_to, from) => {
    scrollNavigationVersion += 1;
    if (from.name === 'inventory') {
        lastInventoryScrollPosition = window.scrollY;
    }
});

export function installSessionGuards(targetRouter: ReturnType<typeof createRouter>): void {
    targetRouter.beforeEach(async (to) => {
        if (!to.meta.requiresAuth && !to.meta.guestOnly && to.name !== 'invitation-accept') {
            return true;
        }

        const session = useSessionStore();
        let user: Awaited<ReturnType<typeof session.ensureLoaded>>;
        try {
            user = await session.ensureLoaded();
        } catch {
            session.rememberPendingDestination(to.fullPath);

            return { name: 'session-unavailable' };
        }

        if (to.meta.requiresAuth && !user) {
            return {
                name: 'login',
                query: { redirect: to.fullPath },
            };
        }

        const hasHousehold = Boolean(user?.membership?.household?.id);

        if (to.meta.requiresAuth && user && !hasHousehold && to.name !== 'invitation-accept') {
            return { name: 'invitation-accept' };
        }

        if (to.meta.guestOnly && user) {
            return hasHousehold ? { name: 'dashboard' } : { name: 'invitation-accept' };
        }

        return true;
    });
}

installSessionGuards(router);
