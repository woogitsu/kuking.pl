import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Świadomie BEZ webfontu. Stos systemowy renderuje się natychmiast,
            // nie powoduje przeskoku tekstu (FOUT) i ma komplet polskich znaków
            // na każdej platformie (docs/design/DESIGN_SYSTEM.md).
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
