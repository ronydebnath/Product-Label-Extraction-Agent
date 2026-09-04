import { useRef, useState } from 'react'
import type { DragEvent } from 'react'
import type { RejectedFile, Upload, UploadLimits } from '@/types'
import { formatBytes } from '@/lib/format'

interface Props {
    limits: UploadLimits
    onAccepted: (uploads: Upload[]) => void
}

interface UploadResponse {
    accepted: Upload[]
    rejected: RejectedFile[]
    code?: string
    message?: string
}

export function UploadDropzone({ limits, onAccepted }: Props) {
    const input = useRef<HTMLInputElement>(null)
    const [dragging, setDragging] = useState(false)
    const [busy, setBusy] = useState(false)
    const [rejected, setRejected] = useState<RejectedFile[]>([])
    const [error, setError] = useState<string | null>(null)

    async function send(files: FileList | null) {
        if (!files || files.length === 0) return

        setBusy(true)
        setError(null)
        setRejected([])

        const body = new FormData()
        Array.from(files).forEach((file) => body.append('files[]', file))

        try {
            const response = await fetch('/uploads', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': readXsrfToken() },
                body,
            })

            if (response.status === 401) {
                setError('Your session has expired. Please sign in again.')

                return
            }

            const payload = (await response.json()) as UploadResponse

            // A whole-request refusal (too many files) has no per-file list to show.
            if (payload.message && payload.accepted.length === 0 && payload.rejected.length === 0) {
                setError(payload.message)

                return
            }

            setRejected(payload.rejected ?? [])

            if (payload.accepted.length > 0) {
                onAccepted(payload.accepted)
            }
        } catch {
            // Network-level failure: the request never got an answer at all.
            setError('We could not reach the server. Check your connection and try again.')
        } finally {
            setBusy(false)
            if (input.current) input.current.value = ''
        }
    }

    function onDrop(event: DragEvent<HTMLDivElement>) {
        event.preventDefault()
        setDragging(false)
        void send(event.dataTransfer.files)
    }

    return (
        <div className="space-y-3">
            <div
                onDragOver={(event) => {
                    event.preventDefault()
                    setDragging(true)
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={`rounded-lg border-2 border-dashed px-6 py-10 text-center transition-colors ${
                    dragging ? 'border-sky-400 bg-sky-50' : 'border-slate-300 bg-white'
                }`}
            >
                <p className="text-sm text-slate-700">
                    Drop label images or PDFs here, or{' '}
                    <button
                        type="button"
                        onClick={() => input.current?.click()}
                        disabled={busy}
                        className="font-medium text-sky-700 underline underline-offset-2 disabled:opacity-50"
                    >
                        choose files
                    </button>
                </p>
                <p className="mt-2 text-xs text-slate-500">
                    JPEG, PNG, WebP or PDF · up to {formatBytes(limits.max_file_bytes)} each ·{' '}
                    {limits.max_files} files at a time · {limits.max_pdf_pages} pages per PDF
                </p>

                {busy && <p className="mt-4 text-sm text-slate-500">Uploading…</p>}

                <input
                    ref={input}
                    type="file"
                    multiple
                    accept={limits.accepted_mime_types.join(',')}
                    className="hidden"
                    onChange={(event) => void send(event.target.files)}
                />
            </div>

            {error && (
                <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    {error}
                </div>
            )}

            {rejected.length > 0 && (
                <ul className="divide-y divide-amber-100 rounded-lg border border-amber-200 bg-amber-50">
                    {rejected.map((file) => (
                        <li key={file.original_name} className="px-4 py-2.5 text-sm">
                            <span className="font-medium text-amber-900">{file.original_name}</span>
                            <span className="text-amber-800"> — {file.message}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}

/** Laravel's CSRF cookie, which the browser sets on the first page load. */
function readXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    return match?.[1] ? decodeURIComponent(match[1]) : ''
}
