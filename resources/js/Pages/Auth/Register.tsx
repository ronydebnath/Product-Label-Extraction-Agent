import { Head, Link, useForm } from '@inertiajs/react'
import { AuthCard, Field } from '@/Components/AuthCard'

export default function Register({ errors }: { errors: Record<string, string> }) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' })

    const input =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-sky-500 focus:ring-1 focus:ring-sky-500 focus:outline-none'

    return (
        <AuthCard title="Create an account" footer={<>Already registered? <Link href="/login" className="font-medium text-sky-700">Sign in</Link></>}>
            <Head title="Create an account" />

            <form
                onSubmit={(event) => {
                    event.preventDefault()
                    form.post('/register')
                }}
                className="space-y-4"
            >
                <Field label="Name" error={errors.name}>
                    <input
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        required
                        autoFocus
                        className={input}
                    />
                </Field>

                <Field label="Email" error={errors.email}>
                    <input
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        autoComplete="username"
                        required
                        className={input}
                    />
                </Field>

                <Field label="Password" error={errors.password}>
                    <input
                        type="password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        autoComplete="new-password"
                        required
                        className={input}
                    />
                </Field>

                <Field label="Confirm password">
                    <input
                        type="password"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        autoComplete="new-password"
                        required
                        className={input}
                    />
                </Field>

                <button
                    type="submit"
                    disabled={form.processing}
                    className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                >
                    {form.processing ? 'Creating…' : 'Create account'}
                </button>
            </form>
        </AuthCard>
    )
}
