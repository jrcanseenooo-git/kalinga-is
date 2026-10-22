import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import inertiaHistoryEncryption from './build/inertia-history.mjs';

export default defineConfig({
    // Keep development imports on the same reviewed transform as production.
    // Excluded packages are served as-is, so their CommonJS dependencies (qs) must
    // be pre-bundled explicitly or the dev server fails with "require is not defined".
    optimizeDeps: {
        exclude: ['@inertiajs/core', '@inertiajs/vue3'],
        include: ['@inertiajs/core > qs', '@inertiajs/core > lodash-es', '@inertiajs/vue3 > lodash-es'],
    },
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        hmr: {
            host: '127.0.0.1',
            port: 5173,
        },
    },
    plugins: [
        inertiaHistoryEncryption(),
        tailwindcss(),
        laravel({
            input: 'resources/js/app.ts',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
