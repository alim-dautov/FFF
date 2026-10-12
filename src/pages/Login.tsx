import { FormEvent, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button, Field, Input } from '../components/ui'
import { useApp } from '../store'

export function LoginPage() {
  const { user, login } = useApp()
  const [email, setEmail] = useState('admin@foodforfit.kz')
  const [password, setPassword] = useState('FitDemo!2026')
  const [busy, setBusy] = useState(false)
  if (user) return <Navigate to="/" replace />

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    try {
      await login(email, password)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Не удалось войти')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid min-h-dvh place-items-center bg-paper px-4 py-10">
      <form onSubmit={submit} className="w-full max-w-md rounded-card border border-line bg-card p-8 shadow-card">
        <div className="mb-6 rounded-panel bg-graphite p-4">
          <img src="/brand/logo.png" alt="FOODFORFIT" className="mx-auto h-16 object-contain" />
        </div>
        <h1 className="text-2xl font-semibold tracking-tight">Вход в CRM</h1>
        <p className="mt-1 mb-6 text-sm text-muted">Продажи, подписки и продления в одном окне.</p>
        <div className="grid gap-4">
          <Field label="Email"><Input value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="username" /></Field>
          <Field label="Пароль"><Input value={password} onChange={(e) => setPassword(e.target.value)} type="password" autoComplete="current-password" /></Field>
          <Button type="submit" disabled={busy}>{busy ? 'Входим...' : 'Войти'}</Button>
        </div>
      </form>
    </div>
  )
}
