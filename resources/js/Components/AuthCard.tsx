import type { ReactNode } from 'react'

export function AuthCard({ title, footer, children }: { title: string; footer: ReactNode; children: ReactNode }) {
    return (
        <div className="flex min-h-full items-center justify-center px-6 py-16">
            <div className="w-full max-w-sm">
                <h1 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h1>
                <p className="mt-1 mb-6 text-sm text-slate-500">Label Extraction Agent</p>

                <div className="rounded-lg border border-slate-200 bg-white p-6">{children}</div>

                <p className="mt-4 text-center text-sm text-slate-500">{footer}</p>
            </div>
        </div>
    )
}

export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-sm text-rose-700">{error}</p>}
        </div>
    )
}
