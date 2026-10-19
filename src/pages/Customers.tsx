import { FormEvent, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AlertCircle, Filter, Upload } from 'lucide-react'
import { toast } from 'sonner'
import { Badge, Button, Field, Gate, Input, Modal, PageHeader, Select, StatCard, Table } from '../components/ui'
import { api } from '../lib/api'
import { daysLabel, money } from '../lib/format'
import { customerFilters, label, tone } from '../lib/labels'
import { TariffBadge, statusLabel } from '../lib/nutrition'
import { useApi } from '../lib/useApi'
import { useApp } from '../store'

type SalesRow = {
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

type NutritionRow = {
  id: number
  customer_id: number
  full_name: string
  line: string
  tariff_days: number | null
  pack_days?: string | null
  kcal?: string | null
  delivery_time?: string | null
  end_date?: string | null
  status: string
  needs_review?: number
  expired_active?: boolean
}

type SalesList = { data: SalesRow[]; total: number; page: number; per_page?: number; summary: { today: number; tomorrow: number; in_3_days: number; overdue: number } }
type NutritionList = {
  data: NutritionRow[]
  total: number
  page: number
  per_page?: number
  segments: {
    line: Record<string, number>
    tariff: Record<string, number>
    status: Record<string, number>
    needs_review: number
    ending_3: number
    total: number
  }
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`shrink-0 rounded-full px-3 py-1.5 text-sm transition ${active ? 'bg-lime font-semibold text-graphite' : 'border border-line bg-card text-muted hover:text-ink'}`}
    >
      {children}
    </button>
  )
}

export function CustomersPage() {
  const [params, setParams] = useSearchParams()
  const view = params.get('view') === 'sales' ? 'sales' : 'nutrition'
  const canImport = useApp((s) => s.can('customers.import'))

  return (
    <div>
      <PageHeader title="Клиенты" text={view === 'nutrition' ? 'Подписки питания, линейки и тарифы.' : 'Ни один клиент не должен потеряться на продлении.'}>
        <div className="flex flex-wrap gap-2">
          {canImport && (
            <Link to="/customers/import">
              <Button tone="secondary"><Upload size={15} strokeWidth={1.75} /> Импорт</Button>
            </Link>
          )}
          {view === 'sales' ? <SalesNewButton /> : null}
        </div>
      </PageHeader>
      <div className="mb-4 flex gap-2">
        <Chip active={view === 'nutrition'} onClick={() => { params.set('view', 'nutrition'); params.delete('filter'); params.set('page', '1'); setParams(params) }}>Питание</Chip>
        <Chip active={view === 'sales'} onClick={() => { params.set('view', 'sales'); params.set('page', '1'); setParams(params) }}>Продажи</Chip>
      </div>
      {view === 'nutrition' ? <NutritionView /> : <SalesView />}
    </div>
  )
}

function SalesNewButton() {
  const [open, setOpen] = useState(false)
  const navigate = useNavigate()
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
  return (
    <>
      <Button onClick={() => setOpen(true)}>Новый клиент</Button>
      <Modal open={open} title="Новый клиент" onClose={() => setOpen(false)}>
        <form className="grid gap-3" onSubmit={create}>
          <Field label="ФИО"><Input name="full_name" required /></Field>
          <Field label="Телефон"><Input name="phone" required placeholder="+7 777 000 00 00" /></Field>
          <Field label="Город"><Select name="city"><option>Алматы</option><option>Астана</option></Select></Field>
          <Field label="Источник"><Input name="source" defaultValue="manual" /></Field>
          <Button type="submit">Создать</Button>
        </form>
      </Modal>
    </>
  )
}

function SalesView() {
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') || 'all'
  const q = params.get('q') || ''
  const page = params.get('page') || '1'
  const { data, loading, error } = useApi<SalesList>(`/customers?view=sales&filter=${filter}&q=${encodeURIComponent(q)}&page=${page}`)
  const [picked, setPicked] = useState<number[]>([])
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

  const summary = [
    ['Сегодня', data?.summary.today, 'ending_today'],
    ['Завтра', data?.summary.tomorrow, 'ending_tomorrow'],
    ['Через 3 дня', data?.summary.in_3_days, 'ending_3'],
    ['Просрочено', data?.summary.overdue, 'overdue'],
  ] as const

  return (
    <>
      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {summary.map(([title, value, key]) => (
          <StatCard key={key} label={title} value={value ?? '—'} tone={key === 'overdue' ? 'danger' : key === 'ending_today' ? 'orange' : 'info'} onClick={() => setFilter(key)} />
        ))}
      </div>
      <div className="mb-4 flex gap-2 overflow-auto pb-1">
        {customerFilters.map(([id, name]) => (
          <Chip key={id} active={filter === id} onClick={() => setFilter(id)}>{name}</Chip>
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
        <Pager data={data} page={page} params={params} setParams={setParams} />
      </Gate>
    </>
  )
}

function NutritionView() {
  const [params, setParams] = useSearchParams()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const navigate = useNavigate()
  const q = params.get('q') || ''
  const page = params.get('page') || '1'
  const query = useMemo(() => {
    const keys = ['line', 'tariff', 'status', 'delivery_time', 'diet', 'kcal', 'ending_3', 'needs_review', 'q', 'page']
    const search = new URLSearchParams()
    search.set('view', 'nutrition')
    for (const key of keys) {
      const v = params.get(key)
      if (v) search.set(key, v)
    }
    return search.toString()
  }, [params])
  const { data, loading, error } = useApi<NutritionList>(`/customers?${query}`)

  function setParam(key: string, value: string | null) {
    if (!value) params.delete(key)
    else params.set(key, value)
    params.set('page', '1')
    setParams(params)
  }

  function toggle(key: string, value: string) {
    setParam(key, params.get(key) === value ? null : value)
  }

  const segments = data?.segments
  const filtersPanel = (
    <div className="space-y-4">
      <div>
        <div className="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Линейка</div>
        <div className="flex flex-wrap gap-2">
          <Chip active={params.get('line') === 'Премиум'} onClick={() => toggle('line', 'Премиум')}>Премиум {segments?.line?.['Премиум'] ?? 0}</Chip>
          <Chip active={params.get('line') === 'Стандарт'} onClick={() => toggle('line', 'Стандарт')}>Стандарт {segments?.line?.['Стандарт'] ?? 0}</Chip>
        </div>
      </div>
      <div>
        <div className="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Тариф</div>
        <div className="flex flex-wrap gap-2">
          {[7, 6, 5, 4].map((d) => (
            <Chip key={d} active={params.get('tariff') === String(d)} onClick={() => toggle('tariff', String(d))}>{d} дн. {segments?.tariff?.[String(d)] ?? 0}</Chip>
          ))}
          <Chip active={params.get('tariff') === 'none'} onClick={() => toggle('tariff', 'none')}>без тарифа {segments?.tariff?.none ?? 0}</Chip>
        </div>
      </div>
      <div>
        <div className="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Статус</div>
        <div className="flex flex-wrap gap-2">
          <Chip active={params.get('status') === 'active'} onClick={() => toggle('status', 'active')}>Активный {segments?.status?.active ?? 0}</Chip>
          <Chip active={params.get('status') === 'ended'} onClick={() => toggle('status', 'ended')}>Закончилась {segments?.status?.ended ?? 0}</Chip>
          <Chip active={params.get('status') === 'paused'} onClick={() => toggle('status', 'paused')}>Пауза {segments?.status?.paused ?? 0}</Chip>
          <Chip active={params.get('status') === 'undefined'} onClick={() => toggle('status', 'undefined')}>не определён {segments?.status?.undefined_tariff ?? 0}</Chip>
        </div>
      </div>
      <div>
        <div className="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Доставка</div>
        <div className="flex flex-wrap gap-2">
          <Chip active={params.get('delivery_time') === 'утро'} onClick={() => toggle('delivery_time', 'утро')}>Утро</Chip>
          <Chip active={params.get('delivery_time') === 'вечер'} onClick={() => toggle('delivery_time', 'вечер')}>Вечер</Chip>
        </div>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <Field label="Диета"><Input value={params.get('diet') || ''} onChange={(e) => setParam('diet', e.target.value || null)} placeholder="веган, детокс…" /></Field>
        <Field label="Ккал"><Input value={params.get('kcal') || ''} onChange={(e) => setParam('kcal', e.target.value || null)} placeholder="1500" /></Field>
      </div>
      <div className="flex flex-wrap gap-2">
        <Chip active={params.get('ending_3') === '1'} onClick={() => toggle('ending_3', '1')}>Окончание ≤ 3 дня {segments?.ending_3 ?? 0}</Chip>
        <Chip active={params.get('needs_review') === '1'} onClick={() => toggle('needs_review', '1')}>Требуют проверки {segments?.needs_review ?? 0}</Chip>
      </div>
    </div>
  )

  return (
    <>
      <div className="mb-4 hidden gap-2 overflow-auto pb-1 md:flex md:flex-wrap">
        <Chip active={params.get('line') === 'Премиум'} onClick={() => toggle('line', 'Премиум')}>Премиум {segments?.line?.['Премиум'] ?? '—'}</Chip>
        <Chip active={params.get('line') === 'Стандарт'} onClick={() => toggle('line', 'Стандарт')}>Стандарт {segments?.line?.['Стандарт'] ?? '—'}</Chip>
        {[7, 6, 5, 4].map((d) => (
          <Chip key={d} active={params.get('tariff') === String(d)} onClick={() => toggle('tariff', String(d))}>{d} дн.</Chip>
        ))}
        <Chip active={params.get('status') === 'active'} onClick={() => toggle('status', 'active')}>Активный</Chip>
        <Chip active={params.get('status') === 'ended'} onClick={() => toggle('status', 'ended')}>Закончилась</Chip>
        <Chip active={params.get('status') === 'paused'} onClick={() => toggle('status', 'paused')}>Пауза</Chip>
        <Chip active={params.get('ending_3') === '1'} onClick={() => toggle('ending_3', '1')}>≤ 3 дня</Chip>
        <Chip active={params.get('needs_review') === '1'} onClick={() => toggle('needs_review', '1')}>Проверить</Chip>
      </div>
      <div className="mb-4 flex gap-2">
        <Input className="max-w-sm flex-1" placeholder="Поиск по ФИО" defaultValue={q} onChange={(e) => setParam('q', e.target.value || null)} />
        <Button tone="secondary" className="md:hidden" onClick={() => setFiltersOpen(true)}><Filter size={15} /> Фильтры</Button>
      </div>
      <div className="mb-4 hidden md:block">{filtersPanel}</div>
      {filtersOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <button type="button" className="absolute inset-0 bg-graphite/40" onClick={() => setFiltersOpen(false)} aria-label="Закрыть" />
          <div className="absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-auto rounded-t-card border border-line bg-card p-4 shadow-card">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="font-semibold">Фильтры</h2>
              <Button tone="ghost" onClick={() => setFiltersOpen(false)}>Готово</Button>
            </div>
            {filtersPanel}
          </div>
        </div>
      )}
      <Gate loading={loading} error={error} empty={!loading && data?.data.length === 0}>
        <div className="hidden md:block">
          <Table>
            <thead className="text-muted">
              <tr>{['ФИО', 'Линейка', 'Тариф', 'Пакет', 'Ккал', 'Доставка', 'Окончание', 'Статус'].map((h) => <th key={h} className="px-3 py-3 font-medium">{h}</th>)}</tr>
            </thead>
            <tbody>
              {data?.data.map((row) => (
                <tr key={row.id} className="border-t border-line hover:bg-soft/60">
                  <td className="px-3 py-3">
                    <button className="font-semibold" onClick={() => navigate('/customers/' + row.customer_id)}>{row.full_name}</button>
                    {row.needs_review ? <span className="ml-2 inline-flex items-center gap-1 text-xs text-orange"><AlertCircle size={12} /> проверить</span> : null}
                  </td>
                  <td className="px-3">{row.line}</td>
                  <td className="px-3"><TariffBadge row={row} /></td>
                  <td className="px-3">{row.pack_days || '—'}</td>
                  <td className="px-3">{row.kcal || '—'}</td>
                  <td className="px-3">{row.delivery_time || '—'}</td>
                  <td className="px-3">{row.end_date || '—'}</td>
                  <td className="px-3">{statusLabel(row.status)}</td>
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
        <div className="grid gap-3 md:hidden">
          {data?.data.map((row) => (
            <button key={row.id} type="button" onClick={() => navigate('/customers/' + row.customer_id)} className="rounded-panel border border-line bg-card p-4 text-left shadow-card">
              <div className="flex items-start justify-between gap-2">
                <div className="font-semibold">{row.full_name}</div>
                <TariffBadge row={row} />
              </div>
              <div className="mt-2 flex flex-wrap gap-2 text-sm text-muted">
                <span>{row.line}</span>
                <span>·</span>
                <span>{row.pack_days || 'пакет —'}</span>
                <span>·</span>
                <span>{row.kcal || 'ккал —'}</span>
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span>{row.delivery_time || 'время —'}</span>
                <span className="text-muted">до {row.end_date || '—'}</span>
                <span className="text-muted">{statusLabel(row.status)}</span>
                {row.needs_review ? <span className="inline-flex items-center gap-1 text-orange"><AlertCircle size={12} /> проверить</span> : null}
              </div>
            </button>
          ))}
        </div>
        <Pager data={data} page={page} params={params} setParams={setParams} />
      </Gate>
    </>
  )
}

function Pager({ data, page, params, setParams }: { data?: { total: number; page: number; per_page?: number } | null; page: string; params: URLSearchParams; setParams: (p: URLSearchParams) => void }) {
  if (!data) return null
  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm text-muted">
      <span>Всего {data.total}. Страница {data.page}.</span>
      {data.total > (data.per_page || 20) && (
        <div className="flex gap-2">
          <Button tone="secondary" disabled={Number(page) <= 1} onClick={() => { params.set('page', String(Number(page) - 1)); setParams(params) }}>Назад</Button>
          <Button tone="secondary" disabled={Number(page) * (data.per_page || 20) >= data.total} onClick={() => { params.set('page', String(Number(page) + 1)); setParams(params) }}>Дальше</Button>
        </div>
      )}
    </div>
  )
}
