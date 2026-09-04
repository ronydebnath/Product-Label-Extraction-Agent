import type { ReactNode } from 'react'

export function EmptyState({ action }: { action?: ReactNode }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <h2 className="text-sm font-medium text-slate-900">No uploads yet</h2>
            <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                Add a product label or specification sheet and the agent will pull out the product
                name, brand, ingredients, allergens and net weight.
            </p>
            {action && <div className="mt-5">{action}</div>}
        </div>
    )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
    return (
        <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3">
            <p className="text-sm text-rose-800">{message}</p>
            {onRetry && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="mt-2 text-sm font-medium text-rose-900 underline underline-offset-2"
                >
                    Try again
                </button>
            )}
        </div>
    )
}

export function LoadingRows({ count = 3 }: { count?: number }) {
    return (
        <div className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
            {Array.from({ length: count }, (_, i) => (
                <div key={i} className="flex items-center gap-4 px-4 py-4">
                    <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                    <div className="ml-auto h-5 w-20 animate-pulse rounded-full bg-slate-100" />
                </div>
            ))}
        </div>
    )
}
