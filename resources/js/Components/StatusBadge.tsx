import type { Upload } from '@/types'

const STYLES: Record<Upload['status'], string> = {
    queued: 'bg-slate-100 text-slate-700 ring-slate-200',
    processing: 'bg-sky-50 text-sky-700 ring-sky-200',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
}

const LABELS: Record<Upload['status'], string> = {
    queued: 'Queued',
    processing: 'Processing',
    completed: 'Completed',
    failed: 'Failed',
}

export function StatusBadge({ upload }: { upload: Upload }) {
    // A queued upload on its second attempt is not the same thing as one that has never been
    // tried. Saying so is the difference between "stuck" and "working on it" to whoever is waiting.
    const label =
        upload.status === 'queued' && upload.attempts > 0
            ? `Queued, retrying (${upload.attempts} of ${upload.max_attempts})`
            : LABELS[upload.status]

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${STYLES[upload.status]}`}
        >
            {upload.status === 'processing' && (
                <span className="size-1.5 animate-pulse rounded-full bg-sky-500" aria-hidden />
            )}
            {label}
        </span>
    )
}
