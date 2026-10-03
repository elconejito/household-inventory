import { defineConfig, devices } from '@playwright/test';

const realApiMode = process.env.PLAYWRIGHT_REAL_API === '1';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    reporter: 'list',
    use: {
        baseURL: realApiMode ? 'http://127.0.0.1:8000' : process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000',
        reuseExistingServer: realApiMode ? false : !process.env.CI,
        ...(realApiMode ? {
            env: {
                ...process.env,
                APP_ENV: 'testing',
                APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                DB_CONNECTION: 'mysql',
                DB_DATABASE: 'household_inventory_test',
                DB_HOST: '127.0.0.1',
                DB_URL: '',
                CACHE_STORE: 'array',
                SESSION_DRIVER: 'file',
            },
        } : {}),
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
