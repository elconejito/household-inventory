import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import ActivityPage from '../pages/ActivityPage.vue';
import DashboardPage from '../pages/DashboardPage.vue';
import InventoryPage from '../pages/InventoryPage.vue';
import ItemDetailPage from '../pages/ItemDetailPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import RegisterPage from '../pages/RegisterPage.vue';
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
            {
                path: 'activity',
                name: 'activity',
                component: ActivityPage,
            },
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
        if (!to.meta.requiresAuth && !to.meta.guestOnly) {
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

        if (to.meta.guestOnly && user) {
            return { name: 'dashboard' };
        }

        return true;
    });
}

installSessionGuards(router);
