import { Link, router } from '@inertiajs/react'
import type { ReactNode } from 'react'

export function AppLayout({ title, children }: { title?: ReactNode; children: ReactNode }) {
    return (
        <div className="min-h-full">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center gap-4 px-6 py-4">
                    <Link href="/uploads" className="text-sm font-semibold tracking-tight text-slate-900">
                        Label Extraction
                    </Link>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="ml-auto text-sm text-slate-500 hover:text-slate-900"
                    >
                        Sign out
                    </button>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-6 py-8">
                {title && <div className="mb-6">{title}</div>}
                {children}
            </main>
        </div>
    )
}
