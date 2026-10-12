import { FormEvent, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Badge, Button, Card, Field, Gate, Input, Modal, PageHeader, Select, Table } from '../components/ui'
import { api } from '../lib/api'
import { fmtDate, money } from '../lib/format'
import { label, tone } from '../lib/labels'
import { useApi } from '../lib/useApi'

export function SubscriptionsPage() {
  const { data, loading, error, reload } = useApi<{ data: Array<Record<string, string | number | null>>; total: number }>('/subscriptions?per_page=50')
  return (
    <div>
      <PageHeader title="Подписки" text="Срок считается от даты окончания." />
      <Gate loading={loading} error={error} empty={!data?.data.length}>
        <Table>
          <thead><tr>{['Клиент', 'Программа', 'Старт', 'Конец', 'Осталось', 'Сумма', 'Оплата', 'Статус', ''].map((h) => <th key={h} className="px-3 py-3 text-left font-medium text-muted">{h}</th>)}</tr></thead>
          <tbody>
            {data?.data.map((row) => (
              <tr key={String(row.id)} className="border-t border-line">
                <td className="px-3 py-3"><Link to={'/customers/' + row.customer_id}>{row.full_name}</Link></td>
                <td className="px-3">{row.program_name}</td>
                <td className="px-3">{fmtDate(String(row.start_date))}</td>
                <td className="px-3">{fmtDate(String(row.end_date))}</td>
                <td className="px-3">{String(row.days_left)} дн.</td>
                <td className="num px-3">{money(Number(row.total_amount))}</td>
                <td className="px-3"><Badge tone={tone(String(row.payment_status))}>{label(String(row.payment_status))}</Badge></td>
                <td className="px-3"><Badge tone={tone(String(row.status))}>{label(String(row.status))}</Badge></td>
                <td className="px-3">{row.status === 'paused' && <Button tone="ghost" onClick={() => api('/subscriptions/' + row.id + '/resume', { method: 'POST' }).then(() => reload())}>Вернуть</Button>}</td>
              </tr>
            ))}
          </tbody>
        </Table>
      </Gate>
    </div>
  )
}

export function OrdersPage() {
  const [params] = useSearchParams()
  const status = params.get('status') || ''
  const { data, loading, error } = useApi<{ data: Array<Record<string, string | number>> }>(`/orders?status=${status}`)
  return (
    <div>
      <PageHeader title="Заказы" />
      <Gate loading={loading} error={error} empty={!data?.data.length}>
        <Table>
          <thead><tr>{['Номер', 'Клиент', 'Телефон', 'Сумма', 'Статус', 'Дата'].map((h) => <th key={h} className="px-3 py-3 text-left font-medium text-muted">{h}</th>)}</tr></thead>
          <tbody>{data?.data.map((row) => <tr key={String(row.id)} className="border-t border-line"><td className="px-3 py-3">{row.number}</td><td>{row.full_name}</td><td>{row.phone}</td><td className="num">{money(Number(row.amount))}</td><td><Badge tone={tone(String(row.status))}>{label(String(row.status))}</Badge></td><td>{fmtDate(String(row.created_at))}</td></tr>)}</tbody>
        </Table>
      </Gate>
    </div>
  )
}

export function DeliveriesPage() {
  const [params, setParams] = useSearchParams()
  const date = params.get('date') || new Date().toISOString().slice(0, 10)
  const slot = params.get('slot') || ''
  const status = params.get('status') || ''
  const { data, loading, error, reload } = useApi<{ data: Array<Record<string, string | number | null>> }>(`/deliveries?date=${date}&slot=${slot}&status=${status}`)
  const month = date.slice(0, 7)
  const calendar = useApi<{ data: Array<{ delivery_date: string; total: number }> }>(`/deliveries/calendar?month=${month}`)
  return (
    <div>
      <PageHeader title="Доставки" text={fmtDate(date)}>
        <Input type="date" value={date} onChange={(e) => { params.set('date', e.target.value); setParams(params) }} />
      </PageHeader>
      <div className="mb-4 flex gap-2">{['', 'morning', 'day', 'evening'].map((item) => <button key={item} className={`rounded-full px-3 py-1 text-sm ${slot === item ? 'bg-lime' : 'bg-card border border-line'}`} onClick={() => { params.set('slot', item); setParams(params) }}>{item ? label(item) : 'Все слоты'}</button>)}</div>
      <div className="mb-4 flex gap-1 overflow-auto">
        {calendar.data?.data.map((day) => <button key={day.delivery_date} className="rounded-xl bg-card px-3 py-2 text-xs" onClick={() => { params.set('date', day.delivery_date); setParams(params) }}>{day.delivery_date.slice(8)} · {day.total}</button>)}
      </div>
      <Gate loading={loading} error={error} empty={!data?.data.length}>
        <div className="grid gap-3">
          {data?.data.map((row) => (
            <Card key={String(row.id)} className={`flex flex-wrap items-center justify-between gap-3 ${row.status === 'problem' ? 'border-l-2 border-l-danger' : row.status === 'delivered' ? 'border-l-2 border-l-lime' : ''}`}>
              <div>
                <div className="font-semibold">{row.full_name}</div>
                <div className="text-sm text-muted">{row.address} · {row.phone}</div>
                <div className="text-sm">{row.program_name} · {label(String(row.slot))} {row.comment ? `· ${row.comment}` : ''}</div>
              </div>
              <Select value={String(row.status)} onChange={(e) => api('/deliveries/' + row.id, { method: 'PATCH', body: { status: e.target.value } }).then(() => { toast.success('Статус обновлён'); reload() })}>
                {['preparing', 'ready', 'handed', 'on_the_way', 'delivered', 'problem', 'cancelled'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
              </Select>
            </Card>
          ))}
        </div>
      </Gate>
    </div>
  )
}

export function ProgramsPage() {
  const { data, loading, error, reload } = useApi<{ data: Array<Record<string, string | number>> }>('/programs')
  const [open, setOpen] = useState(false)
  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = Object.fromEntries(new FormData(event.currentTarget).entries())
    await api('/programs', { method: 'POST', body: form })
    setOpen(false)
    reload()
  }
  return (
    <div>
      <PageHeader title="Программы питания"><Button onClick={() => setOpen(true)}>Новая программа</Button></PageHeader>
      <Gate loading={loading} error={error}>
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {data?.data.map((p) => (
            <Card key={String(p.id)}>
              <div className="text-xs text-muted">{p.calories} ккал · {p.meals_per_day} приёмов · {p.days} дней</div>
              <h2 className="mt-1 text-lg font-semibold tracking-tight">{p.name}</h2>
              <p className="mt-2 text-sm text-muted">{p.description}</p>
              <div className="num mt-4 text-xl font-semibold">{money(Number(p.price))}</div>
            </Card>
          ))}
        </div>
      </Gate>
      <Modal open={open} title="Новая программа" onClose={() => setOpen(false)}>
        <form className="grid gap-3" onSubmit={create}>
          <Field label="Название"><Input name="name" required /></Field>
          <Field label="Описание"><Input name="description" /></Field>
          <Field label="Ккал"><Input name="calories" type="number" defaultValue={1600} /></Field>
          <Field label="Цена"><Input name="price" type="number" defaultValue={99000} /></Field>
          <Field label="Дней"><Input name="days" type="number" defaultValue={28} /></Field>
          <Field label="Приёмов"><Input name="meals_per_day" type="number" defaultValue={5} /></Field>
          <Button type="submit">Сохранить</Button>
        </form>
      </Modal>
    </div>
  )
}

export function InventoryPage() {
  const [params] = useSearchParams()
  const low = params.get('low') || ''
  const { data, loading, error, reload } = useApi<{ data: Array<Record<string, string | number | null>>; categories: Array<{ id: number; name: string }> }>(`/inventory?low=${low}&per_page=50`)
  const [open, setOpen] = useState(false)
  async function move(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = Object.fromEntries(new FormData(event.currentTarget).entries())
    try {
      await api('/inventory/move', { method: 'POST', body: form })
      toast.success('Операция проведена')
      setOpen(false)
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }
  return (
    <div>
      <PageHeader title="Склад" text="Остаток ниже минимума поднимает уведомление."><Button onClick={() => setOpen(true)}>Операция</Button></PageHeader>
      <Gate loading={loading} error={error} empty={!data?.data.length}>
        <Table>
          <thead><tr>{['Товар', 'SKU', 'Категория', 'Остаток', 'Минимум', 'Цена', 'Партия', 'Срок'].map((h) => <th key={h} className="px-3 py-3 text-left font-medium text-muted">{h}</th>)}</tr></thead>
          <tbody>{data?.data.map((row) => (
            <tr key={String(row.id)} className="border-t border-line">
              <td className="px-3 py-3 font-medium">{row.name}</td><td>{row.sku}</td><td>{row.category_name}</td>
              <td className={Number(row.quantity) < Number(row.min_stock) ? 'text-danger font-semibold' : ''}>{row.quantity} {row.unit}</td>
              <td>{row.min_stock}</td><td>{money(Number(row.avg_price))}</td><td>{row.batch}</td><td>{row.expires_on}</td>
            </tr>
          ))}</tbody>
        </Table>
      </Gate>
      <Modal open={open} title="Складская операция" onClose={() => setOpen(false)}>
        <form className="grid gap-3" onSubmit={move}>
          <Field label="Товар"><Select name="product_id">{data?.data.map((p) => <option key={String(p.id)} value={String(p.id)}>{p.name}</option>)}</Select></Field>
          <Field label="Тип"><Select name="type"><option value="in">Приход</option><option value="out">Расход</option><option value="writeoff">Списание</option><option value="adjust">Корректировка</option><option value="transfer">Перемещение</option></Select></Field>
          <Field label="Количество"><Input name="quantity" type="number" required /></Field>
          <Field label="Куда, если перемещение"><Input name="warehouse_to" placeholder="Выдача" /></Field>
          <Field label="Комментарий"><Input name="note" /></Field>
          <Button type="submit">Провести</Button>
        </form>
      </Modal>
    </div>
  )
}
