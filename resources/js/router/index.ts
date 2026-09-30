import { createRouter, createWebHistory } from 'vue-router';
import ActivityPage from '../pages/ActivityPage.vue';
import DashboardPage from '../pages/DashboardPage.vue';
import InventoryPage from '../pages/InventoryPage.vue';

export const routes = [
    {
        path: '/',
        name: 'dashboard',
        component: DashboardPage,
    },
    {
        path: '/inventory',
        name: 'inventory',
        component: InventoryPage,
    },
    {
        path: '/activity',
        name: 'activity',
        component: ActivityPage,
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
