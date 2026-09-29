import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Inter Variable JEST webfontem, ładowanym przez resources/css/fonts.css
            // (dwa małe podzbiory WOFF2, #1000). Stos systemowy jest fallbackiem
            // podczas `font-display: swap` i dla znaków spoza podzbioru.
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
