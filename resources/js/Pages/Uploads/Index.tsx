import { Head } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import type { Upload, UploadLimits } from '@/types'
import { AppLayout } from '@/Layouts/AppLayout'
import { UploadDropzone } from '@/Components/UploadDropzone'
import { UploadList } from '@/Components/UploadList'
import { EmptyState, ErrorState } from '@/Components/States'
import { useUploadStatuses } from '@/lib/useUploadStatuses'

interface Props {
    uploads: Upload[]
    limits: UploadLimits
}

export default function Index({ uploads: fromServer, limits }: Props) {
    // Files accepted in this tab since the page was rendered. Everything else is derived, so
    // there is no effect synchronising two copies of the same list and no window where the two
    // disagree.
    const [justAdded, setJustAdded] = useState<Upload[]>([])

    const known = useMemo(() => {
        const byId = new Map<string, Upload>()

        // Newest first: this tab's uploads, then the server's list. The server wins on a
        // duplicate, because after a reload its copy is the fresher one.
        for (const upload of [...justAdded, ...fromServer]) {
            byId.set(upload.id, byId.get(upload.id) ?? upload)
        }

        return [...byId.values()]
    }, [justAdded, fromServer])

    const { data: statuses, error } = useUploadStatuses(known)

    const uploads = useMemo(
        () => known.map((upload) => statuses?.find((fresh) => fresh.id === upload.id) ?? upload),
        [known, statuses],
    )

    return (
        <AppLayout
            title={
                <div>
                    <h1 className="text-xl font-semibold tracking-tight text-slate-900">Uploads</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Product labels and specification sheets, turned into structured data.
                    </p>
                </div>
            }
        >
            <Head title="Uploads" />

            <div className="space-y-6">
                <UploadDropzone
                    limits={limits}
                    onAccepted={(accepted) => setJustAdded((current) => [...accepted, ...current])}
                />

                {/* A failing poll must not blank the list. The rows on screen are still true, they
                    have just stopped updating, and saying so beats hiding them. */}
                {error && <ErrorState message={error.message} />}

                {uploads.length === 0 ? <EmptyState /> : <UploadList uploads={uploads} />}
            </div>
        </AppLayout>
    )
}
