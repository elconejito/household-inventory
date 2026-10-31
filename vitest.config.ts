import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        setupFiles: ['resources/js/test-setup.ts'],
        include: ['resources/js/**/*.spec.ts'],
        clearMocks: true,
    },
});
