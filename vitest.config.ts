import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/__tests__/**/*.spec.ts'],
        passWithNoTests: true,
        // AG Grid mounts real grids in jsdom; under worker contention they need more than
        // Vitest's 5s default, which made the suite fail on most runs.
        testTimeout: 20000,
        hookTimeout: 20000,
    },
});
