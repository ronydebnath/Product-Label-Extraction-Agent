import type { ReactNode } from 'react'
import type { Extraction } from '@/types'
import { formatDuration } from '@/lib/format'

/** Absent means the document did not say, and saying so is more useful than an empty cell. */
function Absent() {
    return <span className="text-slate-400 italic">Not found on document</span>
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-1 gap-1 py-3 sm:grid-cols-3 sm:gap-4">
            <dt className="text-sm font-medium text-slate-500">{label}</dt>
            <dd className="text-sm text-slate-900 sm:col-span-2">{children}</dd>
        </div>
    )
}

function Chips({ items, tone }: { items: string[]; tone: 'rose' | 'amber' }) {
    if (items.length === 0) return <span className="text-slate-500">None declared</span>

    const styles = tone === 'rose' ? 'bg-rose-50 text-rose-800 ring-rose-200' : 'bg-amber-50 text-amber-800 ring-amber-200'

    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => (
                <span key={item} className={`rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${styles}`}>
                    {item}
                </span>
            ))}
        </div>
    )
}

export function ExtractionView({ extraction }: { extraction: Extraction }) {
    const { data } = extraction

    return (
        <div className="space-y-6">
            {data.warnings.length > 0 && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <h2 className="text-sm font-semibold text-amber-900">Worth checking</h2>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                        {data.warnings.map((warning) => (
                            <li key={warning}>{warning}</li>
                        ))}
                    </ul>
                </div>
            )}

            <dl className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white px-4">
                <Field label="Document type">{data.document_type.replace(/_/g, ' ')}</Field>
                <Field label="Product name">{data.product_name ?? <Absent />}</Field>
                <Field label="Brand">{data.brand ?? <Absent />}</Field>

                <Field label="Net weight">
                    {data.net_weight ? (
                        <>
                            {data.net_weight.value} {data.net_weight.unit}
                            <span className="ml-2 text-slate-500">as printed: “{data.net_weight.raw}”</span>
                        </>
                    ) : (
                        <Absent />
                    )}
                </Field>

                <Field label="Contains">
                    {data.allergens ? <Chips items={data.allergens.contains} tone="rose" /> : <Absent />}
                </Field>

                <Field label="May contain">
                    {data.allergens ? <Chips items={data.allergens.may_contain} tone="amber" /> : <Absent />}
                </Field>

                <Field label="Ingredients">
                    {data.ingredients ? (
                        <ol className="list-decimal space-y-0.5 pl-5">
                            {data.ingredients.map((ingredient, index) => (
                                <li key={`${index}-${ingredient}`}>{ingredient}</li>
                            ))}
                        </ol>
                    ) : (
                        <Absent />
                    )}
                </Field>
            </dl>

            <p className="text-xs text-slate-500">
                Extracted by {extraction.model} (prompt v{extraction.prompt_version}) in{' '}
                {formatDuration(extraction.duration_ms)}
                {extraction.duration_ms === 0 && ' — reused from an identical file already processed'}
                {extraction.input_tokens !== null &&
                    extraction.duration_ms !== 0 &&
                    ` · ${extraction.input_tokens} in / ${extraction.output_tokens} out tokens`}
            </p>
        </div>
    )
}
