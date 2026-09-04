import { createInertiaApp } from '@inertiajs/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ComponentType } from 'react'
import { createRoot } from 'react-dom/client'
import '../css/app.css'

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            // Polling drives freshness here, so a refetch on every window focus would only add
            // requests without adding information.
            refetchOnWindowFocus: false,
            retry: 1,
        },
    },
})

void createInertiaApp({
    title: (title) => (title ? `${title} — Label Extraction` : 'Label Extraction'),
    resolve: (name) => {
        // Eagerly globbed so every page is in the bundle: the app is four screens, and
        // code-splitting them would trade a smaller first load for a spinner on every navigation.
        const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx', { eager: true })
        const page = pages[`./Pages/${name}.tsx`]

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`)
        }

        return page
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <QueryClientProvider client={queryClient}>
                <App {...props} />
            </QueryClientProvider>,
        )
    },
})
