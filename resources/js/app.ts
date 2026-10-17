import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import './styles.css';
import { http, requestCsrfCookie } from './lib/http';
import { installSessionRecovery } from './lib/session-recovery';
import { useSessionStore } from './stores/session';

const pinia = createPinia();

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
        },
    },
});

const session = useSessionStore(pinia);

installSessionRecovery(http, async () => {
    await requestCsrfCookie();
    return Boolean(await session.refresh());
}, async () => {
    session.clearSession();
    const currentRoute = router.currentRoute.value;
    if (currentRoute.meta.requiresAuth || currentRoute.name === 'invitation-accept') {
        await router.replace({ name: 'login', query: { redirect: currentRoute.fullPath } });
    }
});

createApp(App)
    .use(pinia)
    .use(router)
    .use(VueQueryPlugin, { queryClient })
    .mount('#app');
