import { useQuery } from '@tanstack/react-query'
import type { Upload } from '@/types'

const TERMINAL = ['completed', 'failed']

export function isTerminal(upload: Upload): boolean {
    return TERMINAL.includes(upload.status)
}

/**
 * Polls the status of the given uploads while any of them is still moving.
 *
 * Polling rather than websockets: the brief's scale question is about upload throughput, and a
 * persistent connection per open tab is a second piece of infrastructure to run and reason about
 * for a page most people leave within a minute. The trade-off is written up in DECISIONS.md.
 */
export function useUploadStatuses(uploads: Upload[]) {
    // Only ask about rows that can still change. Once everything is terminal the id list is empty,
    // the query is disabled, and the tab stops talking to the server entirely.
    const pendingIds = uploads.filter((upload) => !isTerminal(upload)).map((upload) => upload.id)

    return useQuery({
        queryKey: ['upload-statuses', pendingIds],
        enabled: pendingIds.length > 0,
        refetchInterval: 2000,
        queryFn: async ({ signal }): Promise<Upload[]> => {
            const response = await fetch(`/api/uploads?ids=${pendingIds.join(',')}`, {
                headers: { Accept: 'application/json' },
                signal,
            })

            if (response.status === 401) {
                throw new Error('Your session has expired. Please sign in again.')
            }

            if (!response.ok) {
                throw new Error('Could not refresh upload status.')
            }

            const body = (await response.json()) as { uploads: Upload[] }

            return body.uploads
        },
    })
}
