import { Head, Link } from '@inertiajs/react'
import type { Extraction, Upload } from '@/types'
import { AppLayout } from '@/Layouts/AppLayout'
import { ExtractionView } from '@/Components/ExtractionView'
import { StatusBadge } from '@/Components/StatusBadge'
import { formatBytes, formatWhen } from '@/lib/format'

interface Props {
    upload: Upload
    extraction: Extraction | null
}

export default function Show({ upload, extraction }: Props) {
    return (
        <AppLayout
            title={
                <div>
                    <Link href="/uploads" className="text-sm text-slate-500 hover:text-slate-900">
                        ← All uploads
                    </Link>
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight break-all text-slate-900">
                            {upload.original_name}
                        </h1>
                        <StatusBadge upload={upload} />
                    </div>
                    <p className="mt-1 text-sm text-slate-500">
                        {upload.kind.toUpperCase()} · {formatBytes(upload.size_bytes)}
                        {upload.page_count !== null && ` · ${upload.page_count} pages`} · uploaded{' '}
                        {formatWhen(upload.created_at)}
                    </p>
                </div>
            }
        >
            <Head title={upload.original_name} />

            {upload.status === 'failed' && (
                <div role="alert" className="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4">
                    <h2 className="text-sm font-semibold text-rose-900">This file could not be processed</h2>
                    <p className="mt-1 text-sm text-rose-800">{upload.message}</p>
                    <p className="mt-2 text-xs text-rose-700">
                        Attempted {upload.attempts} {upload.attempts === 1 ? 'time' : 'times'}. Upload the
                        file again if you think this was temporary.
                    </p>
                </div>
            )}

            {extraction ? (
                <ExtractionView extraction={extraction} />
            ) : upload.status !== 'failed' ? (
                <div className="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center">
                    <p className="text-sm font-medium text-slate-900">
                        {upload.status === 'processing' ? 'Reading the document…' : 'Waiting for a worker…'}
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        This page does not refresh itself. Return to the list to watch progress.
                    </p>
                </div>
            ) : null}
        </AppLayout>
    )
}
