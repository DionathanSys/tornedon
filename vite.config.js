import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/filament/admin/theme.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        VitePWA({
            injectRegister: false,
            manifest: false,
            includeAssets: ['favicon.ico', 'pwa-192.svg', 'pwa-512.svg', 'pwa-maskable.svg'],
            workbox: {
                globPatterns: ['**/*.{js,css,ico,png,svg,webp,woff2}'],
                runtimeCaching: [
                    {
                        urlPattern: ({ request }) => ['style', 'script', 'worker', 'font'].includes(request.destination),
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'static-assets',
                        },
                    },
                    {
                        urlPattern: ({ request }) => request.destination === 'image',
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'image-assets',
                        },
                    },
                ],
            },
        }),
    ],
});
