import { Head, Link, useForm } from '@inertiajs/react'
import { AuthCard, Field } from '@/Components/AuthCard'

export default function Login({ errors }: { errors: Record<string, string> }) {
    const form = useForm({ email: '', password: '', remember: false as boolean })

    return (
        <AuthCard title="Sign in" footer={<>No account? <Link href="/register" className="font-medium text-sky-700">Create one</Link></>}>
            <Head title="Sign in" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    form.post('/login')
                }}
                className="space-y-4"
            >
                <Field label="Email" error={errors.email}>
                    <input
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        autoComplete="username"
                        required
                        autoFocus
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-sky-500 focus:ring-1 focus:ring-sky-500 focus:outline-none"
                    />
                </Field>

                <Field label="Password" error={errors.password}>
                    <input
                        type="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        autoComplete="current-password"
                        required
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-sky-500 focus:ring-1 focus:ring-sky-500 focus:outline-none"
                    />
                </Field>

                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        checked={form.data.remember}
                        onChange={(event) => form.setData('remember', event.target.checked)}
                        className="rounded border-slate-300"
                    />
                    Remember me
                </label>

                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </button>
            </form>
        </AuthCard>
    )
}
