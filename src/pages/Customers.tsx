import { FormEvent, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Badge, Button, Field, Gate, Input, Modal, PageHeader, Select, StatCard, Table } from '../components/ui'
import { api } from '../lib/api'
import { daysLabel, money } from '../lib/format'
import { customerFilters, label, tone } from '../lib/labels'
import { useApi } from '../lib/useApi'

type Row = {
  id: number
  full_name: string
  phone: string
  program_name?: string
  start_date?: string
  end_date?: string
  days_left?: number | null
  total_amount?: number | null
  payment_status?: string
  manager_name?: string
  last_contact_at?: string
  next_action?: string
  status: string
  vip: number
}
type List = { data: Row[]; total: number; page: number; per_page?: number; summary: { today: number; tomorrow: number; in_3_days: number; overdue: number } }

export function CustomersPage() {
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') || 'all'
  const q = params.get('q') || ''
  const page = params.get('page') || '1'
  const { data, loading, error, reload } = useApi<List>(`/customers?filter=${filter}&q=${encodeURIComponent(q)}&page=${page}`)
  const [picked, setPicked] = useState<number[]>([])
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()

  function setFilter(next: string) {
    params.set('filter', next)
    params.set('page', '1')
    setParams(params)
  }

  async function bulk() {
    try {
      const result = await api<{ results: Array<{ status: string }> }>('/customers/bulk', { method: 'POST', body: { ids: picked, action: 'renewal_message' } })
      const draft = result.results.filter((item) => item.status === 'draft').length
      toast.success(draft ? `Сообщения сохранены как черновики: ${draft}. Канал WhatsApp не подключён.` : 'Сообщения поставлены в очередь')
      setPicked([])
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    try {
      const card = await api<{ customer: { id: number } }>('/customers', { method: 'POST', body: Object.fromEntries(form.entries()) })
      navigate('/customers/' + card.customer.id)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  const summary = [
    ['Сегодня', data?.summary.today, 'ending_today'],
    ['Завтра', data?.summary.tomorrow, 'ending_tomorrow'],
    ['Через 3 дня', data?.summary.in_3_days, 'ending_3'],
    ['Просрочено', data?.summary.overdue, 'overdue'],
  ] as const

  return (
    <div>
      <PageHeader title="Клиенты" text="Ни один клиент не должен потеряться на продлении.">
        <Button onClick={() => setOpen(true)}>Новый клиент</Button>
      </PageHeader>
      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {summary.map(([title, value, key]) => (
          <StatCard key={key} label={title} value={value ?? '—'} tone={key === 'overdue' ? 'danger' : key === 'ending_today' ? 'orange' : 'info'} onClick={() => setFilter(key)} />
        ))}
      </div>
      <div className="mb-4 flex gap-2 overflow-auto pb-1">
        {customerFilters.map(([id, name]) => (
          <button key={id} onClick={() => setFilter(id)} className={`shrink-0 rounded-full px-3 py-1.5 text-sm transition ${filter === id ? 'bg-lime font-semibold text-graphite' : 'border border-line bg-card text-muted hover:text-ink'}`}>{name}</button>
        ))}
      </div>
      <Input className="mb-4 max-w-sm" placeholder="Поиск по имени или телефону" defaultValue={q} onChange={(e) => { params.set('q', e.target.value); params.set('page', '1'); setParams(params) }} />
      {picked.length > 0 && <div className="mb-3"><Button onClick={bulk}>Отправить сообщение о продлении ({picked.length})</Button></div>}
      <Gate loading={loading} error={error} empty={!loading && data?.data.length === 0}>
        <Table>
          <thead className="text-muted"><tr>{['', 'Клиент', 'Телефон', 'Программа', 'Старт', 'Окончание', 'Осталось', 'Стоимость', 'Оплата', 'Менеджер', 'Контакт', 'Действие'].map((h) => <th key={h} className="px-3 py-3 font-medium">{h}</th>)}</tr></thead>
          <tbody>
            {data?.data.map((row) => {
              const critical = (row.days_left ?? 99) <= 1
              const warn = (row.days_left ?? 99) <= 3
              return (
                <tr key={row.id} className="border-t border-line hover:bg-soft/60">
                  <td className="px-3"><input type="checkbox" checked={picked.includes(row.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, row.id] : picked.filter((id) => id !== row.id))} /></td>
                  <td className="px-3 py-3"><button className="font-semibold" onClick={() => navigate('/customers/' + row.id)}>{row.full_name}</button>{row.vip ? <Badge tone="orange">VIP</Badge> : null}<div className="text-xs text-muted">{label(row.status)}</div></td>
                  <td className="px-3">{row.phone}</td>
                  <td className="px-3">{row.program_name || '—'}</td>
                  <td className="px-3">{row.start_date || '—'}</td>
                  <td className="px-3">{row.end_date || '—'}</td>
                  <td className="px-3">{warn ? <Badge tone={critical || (row.days_left ?? 0) < 0 ? 'danger' : 'orange'}>{daysLabel(row.days_left)}</Badge> : daysLabel(row.days_left)}</td>
                  <td className="num px-3">{money(row.total_amount)}</td>
                  <td className="px-3"><Badge tone={tone(row.payment_status)}>{label(row.payment_status)}</Badge></td>
                  <td className="px-3">{row.manager_name}</td>
                  <td className="px-3">{row.last_contact_at || '—'}</td>
                  <td className="px-3">{row.next_action || '—'}</td>
                </tr>
              )
            })}
          </tbody>
        </Table>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
          <span>Всего {data?.total}. Страница {data?.page}.</span>
          {data && data.total > (data.per_page || 20) && (
            <div className="flex gap-2">
              <Button tone="secondary" disabled={Number(page) <= 1} onClick={() => { params.set('page', String(Number(page) - 1)); setParams(params) }}>Назад</Button>
              <Button tone="secondary" disabled={Number(page) * (data.per_page || 20) >= data.total} onClick={() => { params.set('page', String(Number(page) + 1)); setParams(params) }}>Дальше</Button>
            </div>
          )}
        </div>
      </Gate>
      <Modal open={open} title="Новый клиент" onClose={() => setOpen(false)}>
        <form className="grid gap-3" onSubmit={create}>
          <Field label="ФИО"><Input name="full_name" required /></Field>
          <Field label="Телефон"><Input name="phone" required placeholder="+7 777 000 00 00" /></Field>
          <Field label="Город"><Select name="city"><option>Алматы</option><option>Астана</option></Select></Field>
          <Field label="Источник"><Input name="source" defaultValue="manual" /></Field>
          <Button type="submit">Создать</Button>
        </form>
      </Modal>
    </div>
  )
}
