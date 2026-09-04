import { Link } from '@inertiajs/react'
import type { Upload } from '@/types'
import { StatusBadge } from '@/Components/StatusBadge'
import { formatBytes, formatWhen } from '@/lib/format'

export function UploadList({ uploads }: { uploads: Upload[] }) {
    return (
        <ul className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 bg-white">
            {uploads.map((upload) => (
                <li key={upload.id}>
                    <Link
                        href={`/uploads/${upload.id}`}
                        className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3.5 hover:bg-slate-50"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-900">
                                {upload.original_name}
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {upload.kind.toUpperCase()} · {formatBytes(upload.size_bytes)}
                                {upload.page_count !== null && ` · ${upload.page_count} pages`} ·{' '}
                                {formatWhen(upload.created_at)}
                            </p>
                            {/* The mapped sentence for the failure code, never the exception. */}
                            {upload.message && (
                                <p className="mt-1 text-xs text-rose-700">{upload.message}</p>
                            )}
                        </div>

                        <StatusBadge upload={upload} />
                    </Link>
                </li>
            ))}
        </ul>
    )
}
