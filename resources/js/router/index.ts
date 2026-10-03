import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import ActivityPage from '../pages/ActivityPage.vue';
import DashboardPage from '../pages/DashboardPage.vue';
import InventoryPage from '../pages/InventoryPage.vue';
import ItemDetailPage from '../pages/ItemDetailPage.vue';
import ContextDetailPage from '../pages/ContextDetailPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import RegisterPage from '../pages/RegisterPage.vue';
import SettingsPage from '../pages/SettingsPage.vue';
import ArchivesPage from '../pages/ArchivesPage.vue';
import InvitationAcceptPage from '../pages/InvitationAcceptPage.vue';
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
                path: 'inventory',
                name: 'inventory',
                component: InventoryPage,
            },
            {
                path: 'inventory/:item',
                name: 'inventory-item',
                component: ItemDetailPage,
            },
            { path: 'categories/:category', name: 'category-detail', component: ContextDetailPage },
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
});

export function installSessionGuards(targetRouter: ReturnType<typeof createRouter>): void {
    targetRouter.beforeEach(async (to) => {
        if (!to.meta.requiresAuth && !to.meta.guestOnly && to.name !== 'invitation-accept') {
            return true;
        }

        const session = useSessionStore();
        const user = await session.ensureLoaded();

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
