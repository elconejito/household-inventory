import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query';
import { createPinia } from 'pinia';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import './styles.css';

const pinia = createPinia();

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
        },
    },
});

createApp(App)
    .use(pinia)
    .use(router)
    .use(VueQueryPlugin, { queryClient })
    .mount('#app');
