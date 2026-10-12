import { FormEvent, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Avatar, Badge, Button, Card, Field, Gate, Input, PageHeader, Select, StatCard, Textarea } from '../components/ui'
import { api } from '../lib/api'
import { fmtDate, money } from '../lib/format'
import { label, tone } from '../lib/labels'
import { useApi } from '../lib/useApi'
import { useApp } from '../store'

const periods = [['today', 'Сегодня'], ['week', 'Неделя'], ['month', 'Месяц'], ['quarter', 'Квартал'], ['year', 'Год']] as const

export function FinancePage() {
  const [period, setPeriod] = useState('month')
  const { data, loading, error } = useApi<Record<string, unknown>>('/finance?period=' + period)
  const stats = data as {
    revenue?: number; refunds?: number; discounts?: number; avg_check?: number; mrr?: number; ltv?: number; retention?: number; churn?: number
    by_program?: Array<{ name: string; revenue: number }>
    by_manager?: Array<{ full_name: string; revenue: number }>
    discount_stats?: { count: number; sum: number; renewed: number; by_manager: Array<{ full_name: string; given: number; amount: number; renewed: number }> }
  } | null
  return (
    <div>
      <PageHeader title="Финансы">
        {periods.map(([id, name]) => <Button key={id} tone={period === id ? 'primary' : 'ghost'} onClick={() => setPeriod(id)}>{name}</Button>)}
      </PageHeader>
      <Gate loading={loading} error={error}>
        {stats && <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {([
              ['Выручка', money(stats.revenue), 'lime'],
              ['Возвраты', money(stats.refunds), 'danger'],
              ['Скидки', money(stats.discounts), 'orange'],
              ['Средний чек', money(stats.avg_check), 'lime'],
              ['MRR', money(stats.mrr), 'info'],
              ['LTV', money(stats.ltv), 'info'],
              ['Retention', stats.retention + '%', 'lime'],
              ['Churn', stats.churn + '%', 'orange'],
            ] as const).map(([k, v, tone]) => <StatCard key={k} label={k} value={v} tone={tone} />)}
          </div>
          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            <Card><h2 className="font-semibold">По программам</h2>{stats.by_program?.map((row) => <Bar key={row.name} label={row.name} value={Number(row.revenue)} max={Math.max(...(stats.by_program || []).map((r) => Number(r.revenue)), 1)} />)}</Card>
            <Card><h2 className="font-semibold">По менеджерам</h2>{stats.by_manager?.map((row) => <div key={row.full_name} className="flex justify-between py-2 text-sm"><span>{row.full_name}</span><b>{money(Number(row.revenue))}</b></div>)}</Card>
          </div>
          <Card className="mt-4"><h2 className="font-semibold">Скидки</h2><p className="text-sm text-muted">Выдано {stats.discount_stats?.count} на {money(stats.discount_stats?.sum)}. Продлились после скидки: {stats.discount_stats?.renewed}.</p>{stats.discount_stats?.by_manager.map((row) => <div key={row.full_name} className="py-1 text-sm">{row.full_name || 'Система'}: {row.given} · {money(Number(row.amount))} · продлили {row.renewed}</div>)}</Card>
        </>}
      </Gate>
    </div>
  )
}

function Bar({ label: name, value, max }: { label: string; value: number; max: number }) {
  return (
    <div className="mt-4">
      <div className="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
        <span className="text-muted">{name}</span>
        <span className="num font-semibold">{money(value)}</span>
      </div>
      <div className="h-1.5 rounded-full bg-paper">
        <div className="h-1.5 rounded-full bg-lime" style={{ width: `${Math.max(4, value / max * 100)}%` }} />
      </div>
    </div>
  )
}

const metricLabel: Record<string, string> = {
  leads: 'Лиды', won: 'Продажи', conversion: 'Конверсия', revenue: 'Выручка', avg_check: 'Средний чек', renewals: 'Продления',
  new: 'Новые клиенты', active: 'Активные', lost: 'Потерянные', renewal: 'На продление', retention: 'Retention', churn: 'Churn',
}

function metricValue(key: string, value: number | null) {
  if (value === null || value === undefined) return '—'
  if (key === 'revenue' || key === 'avg_check') return money(value)
  if (key === 'conversion' || key === 'retention' || key === 'churn') return `${value}%`
  return value
}

function metricTone(key: string): 'lime' | 'info' | 'orange' | 'danger' | 'neutral' {
  if (key === 'lost' || key === 'churn') return 'danger'
  if (key === 'renewal' || key === 'renewals') return 'orange'
  if (key === 'revenue' || key === 'avg_check' || key === 'won') return 'lime'
  return 'info'
}

export function AnalyticsPage() {
  const [period, setPeriod] = useState('month')
  const { data, loading, error } = useApi<{ sales: Record<string, number | null>; customers: Record<string, number>; managers: Array<Record<string, string | number>>; programs: Array<Record<string, string | number>> }>('/analytics?period=' + period)
  return (
    <div>
      <PageHeader title="Аналитика">{periods.map(([id, name]) => <Button key={id} tone={period === id ? 'primary' : 'ghost'} onClick={() => setPeriod(id)}>{name}</Button>)}</PageHeader>
      <Gate loading={loading} error={error}>
        {data && <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {Object.entries({ ...data.sales, ...data.customers }).map(([k, v]) => <StatCard key={k} label={metricLabel[k] || k} value={metricValue(k, v)} tone={metricTone(k)} />)}
          </div>
          <div className="mt-4 grid gap-4 lg:grid-cols-2">
            <Card><h2 className="text-base font-semibold">Менеджеры</h2>{data.managers.map((row) => <div key={String(row.full_name)} className="flex items-center justify-between gap-3 border-b border-line py-2.5 text-sm last:border-0"><span className="font-medium">{row.full_name}</span><span className="text-right text-muted">лиды {row.leads} · продажи {row.sales} · <b className="num text-ink">{money(Number(row.revenue))}</b></span></div>)}</Card>
            <Card><h2 className="text-base font-semibold">Программы</h2>{data.programs.map((row) => <Bar key={String(row.name)} label={String(row.name)} value={Number(row.revenue)} max={Math.max(...data.programs.map((item) => Number(item.revenue)), 1)} />)}</Card>
          </div>
        </>}
      </Gate>
    </div>
  )
}

export function EmployeesPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') || 'people'
  const filter = params.get('filter') || 'mine'
  const people = useApi<{ data: Array<Record<string, string | number>>; roles: Array<{ id: number; name: string }> }>(tab === 'people' ? '/employees' : null)
  const tasks = useApi<{ data: Array<Record<string, string | number | null>> }>('/tasks?filter=' + filter)
  const [open, setOpen] = useState(false)
  return (
    <div>
      <PageHeader title="Сотрудники и задачи">
        <Button tone={tab === 'people' ? 'primary' : 'ghost'} onClick={() => { params.set('tab', 'people'); setParams(params) }}>Люди</Button>
        <Button tone={tab === 'tasks' ? 'primary' : 'ghost'} onClick={() => { params.set('tab', 'tasks'); setParams(params) }}>Задачи</Button>
      </PageHeader>
      {tab === 'tasks' ? (
        <>
          <div className="mb-4 flex gap-2 overflow-auto pb-1">{[['mine', 'Мои'], ['overdue', 'Просроченные'], ['today', 'Сегодня'], ['week', 'Неделя'], ['done', 'Завершённые']].map(([id, name]) => <button key={id} className={`shrink-0 rounded-full px-3 py-1.5 text-sm transition ${filter === id ? 'bg-lime font-semibold text-graphite' : 'border border-line bg-card text-muted'}`} onClick={() => { params.set('filter', id); setParams(params) }}>{name}</button>)}</div>
          <Gate loading={tasks.loading} error={tasks.error} empty={!tasks.data?.data.length}>
            <div className="grid gap-2">
            {tasks.data?.data.map((task) => {
              const due = String(task.due_date || '')
              const overdue = task.status !== 'done' && due !== '' && due < new Date().toLocaleDateString('en-CA')
              return (
              <Card key={String(task.id)} className={`flex flex-wrap items-center justify-between gap-3 ${task.status === 'done' ? 'opacity-70' : ''} ${overdue || filter === 'overdue' ? 'border-l-2 border-l-danger' : ''}`}>
                <div>
                  <div className="font-semibold">{task.title}</div>
                  <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                    <span>{task.customer_name || 'Без клиента'}</span>
                    <span>{task.assignee_name}</span>
                    <span className={overdue ? 'font-medium text-danger' : ''}>{task.due_date}</span>
                    <Badge tone={tone(String(task.priority))}>{label(String(task.priority))}</Badge>
                  </div>
                </div>
                {task.status !== 'done' && <Button tone="ghost" onClick={() => api('/tasks/' + task.id, { method: 'PATCH', body: { ...task, status: 'done' } }).then(() => tasks.reload())}>Готово</Button>}
              </Card>
              )
            })}
            </div>
          </Gate>
        </>
      ) : (
        <Gate loading={people.loading} error={people.error}>
          <div className="grid gap-3 md:grid-cols-2">
            {people.data?.data.map((person) => (
              <Card key={String(person.id)} className="flex gap-3">
                <Avatar name={String(person.full_name)} />
                <div className="min-w-0">
                  <div className="font-semibold">{person.full_name}</div>
                  <div className="text-sm text-muted">{person.position} · {person.role_name}</div>
                  <div className="mt-2 text-sm">{person.phone} · {person.email}</div>
                  <div className="text-sm text-muted">С {fmtDate(String(person.hired_at))} · {person.department}</div>
                  {person.salary !== undefined && <div className="num mt-1 font-semibold">{money(Number(person.salary))}</div>}
                </div>
              </Card>
            ))}
          </div>
          <Button className="mt-4" onClick={() => setOpen(true)}>Новый сотрудник</Button>
          {open && (
            <form className="mt-4 grid max-w-lg gap-3" onSubmit={async (e) => {
              e.preventDefault()
              try {
                await api('/employees', { method: 'POST', body: Object.fromEntries(new FormData(e.currentTarget).entries()) })
                toast.success('Сотрудник создан')
                setOpen(false)
                people.reload()
              } catch (err) { toast.error(err instanceof Error ? err.message : 'Ошибка') }
            }}>
              <Field label="ФИО"><Input name="full_name" required /></Field>
              <Field label="Телефон"><Input name="phone" required /></Field>
              <Field label="Email"><Input name="email" type="email" required /></Field>
              <Field label="Пароль"><Input name="password" type="password" required /></Field>
              <Field label="Роль"><Select name="role_id">{people.data?.roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></Field>
              <Field label="Зарплата"><Input name="salary" type="number" defaultValue={300000} /></Field>
              <Button type="submit">Сохранить</Button>
            </form>
          )}
        </Gate>
      )}
    </div>
  )
}

export function WhatsAppPage() {
  const [params, setParams] = useSearchParams()
  const selected = params.get('conversation') || ''
  const [q, setQ] = useState('')
  const list = useApi<{ data: Array<Record<string, string | number | null>> }>('/whatsapp/conversations?q=' + encodeURIComponent(q))
  const thread = useApi<{ conversation: Record<string, string | number | null>; messages: Array<Record<string, string>>; templates: Array<{ body: string; name: string }>; channel_ready: boolean } | null>(selected ? '/whatsapp/conversations/' + selected : null)
  const [text, setText] = useState('')
  async function send(event: FormEvent) {
    event.preventDefault()
    const result = await api<{ status: string }>('/whatsapp/conversations/' + selected + '/messages', { method: 'POST', body: { body: text } })
    toast.success(result.status === 'draft' ? 'Черновик сохранён. Канал WhatsApp не подключён.' : 'Сообщение обработано')
    setText('')
    thread.reload()
    list.reload()
  }
  return (
    <div>
      <PageHeader title="WhatsApp" text={thread.data?.channel_ready ? 'Канал подключён' : 'Канал не подключён: исходящие остаются черновиками'} />
      <div className="grid min-h-[60vh] overflow-hidden rounded-card border border-line bg-card shadow-card lg:grid-cols-[280px_1fr]">
        <div className="border-r border-line">
          <Input className="m-3 w-[calc(100%-1.5rem)]" placeholder="Поиск" value={q} onChange={(e) => setQ(e.target.value)} />
          <Gate loading={list.loading} error={list.error}>
            {list.data?.data.map((row) => (
              <button key={String(row.id)} className={`block w-full px-4 py-3 text-left ${String(row.id) === selected ? 'bg-soft' : ''}`} onClick={() => { params.set('conversation', String(row.id)); setParams(params) }}>
                <div className="font-medium">{row.full_name || row.phone}</div>
                <div className="text-xs text-muted">{row.phone}</div>
              </button>
            ))}
          </Gate>
        </div>
        <div className="flex flex-col">
          <div className="flex-1 space-y-2 overflow-auto p-4">
            {thread.data?.messages.map((m) => <div key={m.id} className={`max-w-md rounded-panel px-3 py-2 text-sm ${m.direction === 'in' ? 'bg-paper' : 'ml-auto bg-soft'}`}>{m.body}<div className="mt-1 text-xs text-muted">{label(m.status)}</div></div>)}
          </div>
          {selected && (
            <form className="border-t border-line p-3" onSubmit={send}>
              <div className="mb-2 flex gap-2 overflow-auto">{thread.data?.templates.map((t) => <button type="button" key={t.name} className="shrink-0 rounded-full bg-paper px-2 py-1 text-xs" onClick={() => setText(t.body)}>{t.name}</button>)}</div>
              <div className="flex gap-2"><Textarea value={text} onChange={(e) => setText(e.target.value)} placeholder="Сообщение" /><Button type="submit">Отправить</Button></div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}

export function NotificationsPage() {
  const { data, loading, error, reload } = useApi<{ data: Array<Record<string, string | null>> }>('/notifications')
  return (
    <div>
      <PageHeader title="Уведомления"><Button tone="ghost" onClick={() => api('/notifications/read', { method: 'POST', body: { all: true } }).then(() => reload())}>Прочитать все</Button></PageHeader>
      <Gate loading={loading} error={error} empty={!data?.data.length}>
        {data?.data.map((item) => (
          <a key={String(item.id)} href={item.link || '#'} className={`mb-2 block rounded-card border bg-card px-4 py-3 shadow-card ${item.read_at ? 'border-line' : 'border-orange/40'}`}>
            <div className="flex items-center gap-2"><Badge tone={tone(item.level)}>{label(item.level)}</Badge><b className="font-semibold">{item.title}</b>{!item.read_at && <span className="ml-auto text-xs font-medium text-orange">Не прочитано</span>}</div>
            <div className="mt-1 text-sm text-muted">{item.body}</div>
          </a>
        ))}
      </Gate>
    </div>
  )
}

export function SettingsPage() {
  const { data, reload } = useApi<Record<string, string | boolean>>('/settings')
  const templates = useApi<{ data: Array<{ id: number; name: string; body: string }> }>(useApp.getState().can('settings.manage') ? '/templates' : null)
  const rules = useApi<{ data: Array<{ id: number; name: string; enabled: number; trigger_code: string }> }>(useApp.getState().can('settings.manage') ? '/automations' : null)
  const { theme, setTheme } = useApp()
  const [prompt, setPrompt] = useState('')
  return (
    <div>
      <PageHeader title="Настройки" />
      <Card>
        <div className="flex items-center justify-between"><div><b>Тема</b><div className="text-sm text-muted">Светлая или тёмная</div></div><Button tone="ghost" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? 'Светлая' : 'Тёмная'}</Button></div>
        <div className="mt-4 text-sm text-muted">Оплата: {String(data?.payment_provider)} {data?.kaspi_ready ? 'Kaspi готов' : 'Kaspi не настроен, счета ручные'}. WhatsApp {data?.whatsapp_ready ? 'готов' : 'не подключён'}. Grok {data?.grok_ready ? 'подключён' : 'без ключа'}.</div>
        {useApp.getState().can('settings.manage') && <Button className="mt-3" onClick={() => api('/settings', { method: 'PUT', body: { whatsapp_enabled: !data?.whatsapp_enabled } }).then(() => reload())}>{data?.whatsapp_enabled ? 'Выключить флаг WhatsApp' : 'Включить флаг WhatsApp'}</Button>}
        <Button className="mt-3 ml-2" tone="ghost" onClick={async () => {
          const cfg = await api<{ enabled: boolean; publicKey: string }>('/push/config')
          if (!cfg.enabled || !('Notification' in window) || !('serviceWorker' in navigator)) {
            toast.message('Web Push заработает после VAPID-ключей и установки PWA')
            return
          }
          const permission = await Notification.requestPermission()
          if (permission !== 'granted') return
          const reg = await navigator.serviceWorker.ready
          const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: cfg.publicKey })
          await api('/push/subscribe', { method: 'POST', body: sub.toJSON() })
          toast.success('Уведомления браузера включены')
        }}>Разрешить push</Button>
      </Card>
      {templates.data && <Card className="mt-4"><h2 className="font-semibold">Шаблоны</h2><p className="text-sm text-muted">{'{name} {phone} {subscription_end} {program} {price} {manager} {discount} {payment_link}'}</p>{templates.data.data.map((tpl) => (
        <form key={tpl.id} className="mt-3 grid gap-2" onSubmit={async (e) => { e.preventDefault(); const body = new FormData(e.currentTarget).get('body'); await api('/templates/' + tpl.id, { method: 'PUT', body: { name: tpl.name, body } }); toast.success('Шаблон сохранён') }}>
          <div className="text-sm font-medium">{tpl.name}</div>
          <Textarea name="body" defaultValue={tpl.body} />
          <Button type="submit">Сохранить</Button>
        </form>
      ))}</Card>}
      {rules.data && <Card className="mt-4"><h2 className="font-semibold">Автоматизации</h2>{rules.data.data.map((rule) => <div key={rule.id} className="flex items-center justify-between py-2 text-sm"><span>{rule.name} · {rule.trigger_code}</span><Button tone="ghost" onClick={() => api('/automations/' + rule.id, { method: 'PATCH', body: { enabled: !rule.enabled } }).then(() => rules.reload())}>{rule.enabled ? 'Выключить' : 'Включить'}</Button></div>)}
        {useApp.getState().can('system.manage') && <form className="mt-4 grid gap-2" onSubmit={async (e) => { e.preventDefault(); await api('/settings', { method: 'PUT', body: { ai_system_prompt: prompt || undefined } }); toast.success('Промпт сохранён') }}><Textarea value={prompt || String(data?.ai_system_prompt || '')} onChange={(e) => setPrompt(e.target.value)} /><Button type="submit">Сохранить системный промпт</Button></form>}
      </Card>}
    </div>
  )
}

export function PayPage() {
  const token = window.location.pathname.split('/').pop() || ''
  const { data, loading, error } = useApi<{ customer: string; amount: number; status: string; number: string }>('/public/pay/' + token)
  return <div className="grid min-h-dvh place-items-center bg-paper p-6"><Card className="max-w-md"><Gate loading={loading} error={error}>{data && <><div className="text-sm text-muted">{data.number}</div><h1 className="text-2xl font-semibold">{data.customer}</h1><div className="num mt-2 text-3xl">{money(data.amount)}</div><Badge tone={tone(data.status)}>{label(data.status)}</Badge><p className="mt-3 text-sm text-muted">Это счёт FOODFORFIT. Если Kaspi не подключён, статус обновляет менеджер в CRM.</p></>}</Gate></Card></div>
}

export function AuditPage() {
  const { data, loading, error } = useApi<{ data: Array<Record<string, string | null>> }>('/audit')
  return <div><PageHeader title="Аудит" /><Gate loading={loading} error={error}><Card className="divide-y divide-line p-0">{data?.data.map((row) => <div key={String(row.id)} className="px-4 py-3 text-sm"><div className="font-medium">{row.entity_type} #{row.entity_id} · {row.action}</div><div className="mt-0.5 text-muted">{row.created_at} · {row.email || 'система'} · {row.field}: {row.old_value} → {row.new_value}</div></div>)}</Card></Gate></div>
}
