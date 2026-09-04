import * as Tooltip from '@radix-ui/react-tooltip'
import type { Upload } from '@/types'
import { formatWhen } from '@/lib/format'

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

/** The detail behind the badge: enough for someone to tell "slow" from "stuck". */
function detail(upload: Upload): string {
    if (upload.status === 'completed' && upload.completed_at) {
        return `Completed ${formatWhen(upload.completed_at)} after ${upload.attempts} attempt${upload.attempts === 1 ? '' : 's'}`
    }

    if (upload.status === 'failed' && upload.failed_at) {
        return `Failed ${formatWhen(upload.failed_at)} after ${upload.attempts} of ${upload.max_attempts} attempts`
    }

    if (upload.status === 'processing') {
        return `A worker is reading this document. Attempt ${upload.attempts} of ${upload.max_attempts}.`
    }

    return upload.attempts > 0
        ? `Waiting to be retried. ${upload.attempts} of ${upload.max_attempts} attempts used.`
        : 'Waiting for a worker to pick this up.'
}

export function StatusBadge({ upload }: { upload: Upload }) {
    // A queued upload on its second attempt is not the same thing as one that has never been
    // tried. Saying so is the difference between "stuck" and "working on it" to whoever is waiting.
    const label =
        upload.status === 'queued' && upload.attempts > 0
            ? `Queued, retrying (${upload.attempts} of ${upload.max_attempts})`
            : LABELS[upload.status]

    return (
        // Radix rather than a title attribute or a hand-rolled div: it handles keyboard focus,
        // escape-to-dismiss, screen-reader announcement and collision-aware positioning, all of
        // which are fiddly to get right and easy to get subtly wrong.
        <Tooltip.Root>
            <Tooltip.Trigger asChild>
                <span
                    tabIndex={0}
                    className={`inline-flex cursor-default items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${STYLES[upload.status]}`}
                >
                    {upload.status === 'processing' && (
                        <span className="size-1.5 animate-pulse rounded-full bg-sky-500" aria-hidden />
                    )}
                    {label}
                </span>
            </Tooltip.Trigger>

            <Tooltip.Portal>
                <Tooltip.Content
                    sideOffset={6}
                    className="z-50 max-w-xs rounded-md bg-slate-900 px-2.5 py-1.5 text-xs text-white shadow-lg"
                >
                    {detail(upload)}
                    <Tooltip.Arrow className="fill-slate-900" />
                </Tooltip.Content>
            </Tooltip.Portal>
        </Tooltip.Root>
    )
}
