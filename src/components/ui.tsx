import { useEffect, type ReactNode } from 'react'
import { AlertCircle, Inbox, X } from 'lucide-react'
import { clsx } from '../lib/format'

const buttonBase = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-control px-3.5 py-2 text-sm font-semibold transition duration-150 active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50'

export function Button({
  children, onClick, type = 'button', tone = 'primary', disabled, className,
}: {
  children: ReactNode
  onClick?: () => void
  type?: 'button' | 'submit'
  tone?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'soft'
  disabled?: boolean
  className?: string
}) {
  const tones = {
    primary: 'bg-lime text-graphite shadow-card hover:brightness-95',
    secondary: 'border border-line bg-card text-ink hover:bg-paper',
    ghost: 'bg-transparent text-ink hover:bg-soft',
    danger: 'bg-danger text-white hover:brightness-95',
    soft: 'border border-line bg-card text-ink hover:bg-paper',
  }
  return (
    <button type={type} disabled={disabled} onClick={onClick} className={clsx(buttonBase, tones[tone], className)}>
      {children}
    </button>
  )
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="grid gap-1.5 text-sm">
      <span className="text-xs font-medium text-muted">{label}</span>
      {children}
    </label>
  )
}

const control = 'w-full rounded-control border border-line bg-card px-3 py-2.5 text-sm outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/25 disabled:opacity-50'

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={clsx(control, props.className)} />
}

export function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={clsx(control, 'min-h-24', props.className)} />
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={clsx(control, props.className)} />
}

const badgeTones: Record<string, string> = {
  neutral: 'bg-paper text-muted',
  lime: 'bg-soft text-lime-deep',
  orange: 'bg-orange/10 text-orange',
  danger: 'bg-danger/10 text-danger',
  info: 'bg-info-soft text-info',
  ai: 'bg-ai-soft text-ai',
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: string }) {
  return <span className={clsx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold', badgeTones[tone] || badgeTones.neutral)}>{children}</span>
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <section className={clsx('rounded-card border border-line bg-card p-5 shadow-card', className)}>{children}</section>
}

const wells: Record<string, string> = {
  lime: 'bg-soft text-lime-deep',
  info: 'bg-info-soft text-info',
  orange: 'bg-orange/10 text-orange',
  danger: 'bg-danger/10 text-danger',
  ai: 'bg-ai-soft text-ai',
  neutral: 'bg-paper text-muted',
}

export function StatCard({
  label, value, tone = 'neutral', icon, className, onClick,
}: {
  label: string
  value: ReactNode
  tone?: 'lime' | 'info' | 'orange' | 'danger' | 'ai' | 'neutral'
  icon?: ReactNode
  className?: string
  onClick?: () => void
}) {
  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="text-xs font-medium text-muted">{label}</div>
        {icon && <span className={clsx('grid h-8 w-8 shrink-0 place-items-center rounded-control', wells[tone])}>{icon}</span>}
      </div>
      <div className="num mt-3 text-2xl font-semibold tracking-tight">{value}</div>
    </>
  )
  const classes = clsx('rounded-card border border-line bg-card p-4 text-left shadow-card transition hover:border-lime/40', className)
  if (onClick) return <button type="button" onClick={onClick} className={clsx(classes, 'w-full')}>{body}</button>
  return <section className={classes}>{body}</section>
}

export function Avatar({ name, className }: { name: string; className?: string }) {
  const letter = name.trim().slice(0, 1).toUpperCase() || '?'
  return <div className={clsx('grid h-10 w-10 shrink-0 place-items-center rounded-panel bg-soft text-sm font-semibold text-lime-deep', className)}>{letter}</div>
}

export function PageHeader({ title, text, children }: { title: string; text?: string; children?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight md:text-[1.75rem]">{title}</h1>
        {text && <p className="mt-1 max-w-2xl text-sm text-muted">{text}</p>}
      </div>
      {children && <div className="flex flex-wrap gap-2">{children}</div>}
    </div>
  )
}

export function Empty({ title, text, tone = 'empty' }: { title: string; text?: string; tone?: 'empty' | 'error' }) {
  const Icon = tone === 'error' ? AlertCircle : Inbox
  return (
    <div className="rounded-card border border-dashed border-line bg-card px-6 py-14 text-center">
      <div className={clsx('mx-auto grid h-10 w-10 place-items-center rounded-control', tone === 'error' ? 'bg-danger/10 text-danger' : 'bg-paper text-muted')}>
        <Icon size={18} />
      </div>
      <div className="mt-3 text-base font-semibold">{title}</div>
      {text && <p className="mx-auto mt-1 max-w-md text-sm text-muted">{text}</p>}
    </div>
  )
}

export function Skeleton({ className = 'h-24' }: { className?: string }) {
  return <div className={clsx('animate-pulse rounded-card bg-line/60', className)} />
}

export function Gate({ loading, error, empty, children }: { loading?: boolean; error?: string; empty?: boolean; children: ReactNode }) {
  if (loading) return <div className="grid gap-3 sm:grid-cols-2">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} />)}</div>
  if (error) return <Empty tone="error" title="Не удалось загрузить" text={error} />
  if (empty) return <Empty title="Пока пусто" text="Когда появятся записи, они будут здесь." />
  return <>{children}</>
}

export function Modal({
  open, title, description, onClose, children, footer,
}: {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}) {
  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])
  if (!open) return null
  return (
    <div className="modal-backdrop fixed inset-0 z-50 grid place-items-end bg-graphite/40 p-4 sm:place-items-center" onMouseDown={onClose}>
      <div className="modal-panel max-h-[90vh] w-full max-w-lg overflow-auto rounded-card border border-line bg-card p-5 shadow-card sm:p-6" onMouseDown={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
            {description && <p className="mt-1 text-sm text-muted">{description}</p>}
          </div>
          <button className="grid h-9 w-9 shrink-0 place-items-center rounded-control text-muted hover:bg-paper" onClick={onClose} aria-label="Закрыть">
            <X size={16} />
          </button>
        </div>
        {children}
        {footer && <div className="mt-5 flex flex-wrap justify-end gap-2 border-t border-line pt-4">{footer}</div>}
      </div>
    </div>
  )
}

export function Tabs({ tabs, value, onChange }: { tabs: Array<[string, string]>; value: string; onChange: (value: string) => void }) {
  return (
    <div className="flex gap-1 overflow-auto border-b border-line">
      {tabs.map(([id, name]) => (
        <button key={id} onClick={() => onChange(id)} className={clsx('shrink-0 border-b-2 px-3 py-2.5 text-sm transition', value === id ? 'border-lime font-semibold text-ink' : 'border-transparent text-muted hover:text-ink')}>
          {name}
        </button>
      ))}
    </div>
  )
}

export function Table({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-auto rounded-panel border border-line bg-card shadow-card">
      <table className="data-table w-full min-w-[1100px] text-left text-sm [&_td]:whitespace-nowrap [&_th]:whitespace-nowrap">{children}</table>
    </div>
  )
}
