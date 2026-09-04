import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import laravel from 'laravel-vite-plugin'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.tsx'], refresh: true }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        // Mirrors the "@/*" paths entry in tsconfig.json. Both are needed: TypeScript resolves
        // types with one, Vite resolves the actual import with the other.
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        // The dev server runs in its own container, so it has to listen on all interfaces and
        // tell the browser to reach HMR through the published port rather than the container host.
        host: '0.0.0.0',
        port: 5173,
        hmr: { host: 'localhost' },
        watch: { usePolling: true },
    },
})
