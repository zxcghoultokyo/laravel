import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        rollupOptions: {
            input: {
                app: resolve(__dirname, 'resources/js/app.js'),
                // Widget bundle (for separate build)
                // widget: resolve(__dirname, 'resources/js/widget/index.js'),
            },
        },
    },
});
