import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
        tailwindcss(),
    ],
    build: {
        /*
         * По умолчанию сборщик сворачивает `max-width: 767px` в диапазонный синтаксис
         * `(width <= 767px)`, который Safari понимает только с 16.4. Панель смотрят с
         * телефонов, поэтому нижняя граница опущена до Safari 15 — медиазапросы
         * выходят в классическом виде и мобильная вёрстка применяется везде.
         */
        cssTarget: ['safari15', 'chrome100', 'firefox100', 'edge100'],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
