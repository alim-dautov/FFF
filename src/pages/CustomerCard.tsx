import { FormEvent, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { Avatar, Badge, Button, Card, Field, Gate, Input, Modal, Select, Tabs } from '../components/ui'
import { api } from '../lib/api'
import { daysLabel, fmtDate, money } from '../lib/format'
import { label, tone } from '../lib/labels'
import { useApi } from '../lib/useApi'
import { useApp } from '../store'

type CardData = {
  customer: Record<string, string | number | null>
  manager: { full_name: string } | null
  address: Record<string, string | null> | null
  schedule: Array<{ weekday: number; slot: string; time_from?: string; time_to?: string }>
  subscriptions: Array<Record<string, string | number | null>>
  current: Record<string, string | number | null> | null
  payments: Array<Record<string, string | number | null>>
  deliveries: Array<Record<string, string | number | null>>
  tasks: Array<Record<string, string | number | null>>
  messages: Array<{ direction: string; body: string; created_at: string }>
  documents: Array<{ id: number; name: string; created_at: string }>
  discounts: Array<Record<string, string | number | null>>
  stats: { orders: number; ltv: number; avg_check: number; lifetime_days: number }
  timeline: Array<{ at: string; text: string }>
  conversation_id: number | null
}

const days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

export function CustomerCardPage() {
  const { id = '' } = useParams()
  const { data, loading, error, reload } = useApi<CardData>('/customers/' + id)
  const [tab, setTab] = useState('overview')
  const [modal, setModal] = useState<'pay' | 'discount' | 'task' | 'address' | ''>('')
  const navigate = useNavigate()
  const openGrok = useApp((s) => s.openGrok)
  const customer = data?.customer
  const current = data?.current
  const daysLeft = current ? Number(current.days_left) : null

  async function run(path: string, body?: unknown, ok = 'Сохранено') {
    try {
      await api(path, { method: 'POST', body })
      toast.success(ok)
      setModal('')
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  async function saveCustomer(body: unknown) {
    try {
      await api('/customers/' + id, { method: 'PATCH', body })
      toast.success('Карточка обновлена')
      setModal('')
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  return (
    <Gate loading={loading} error={error}>
      {data && customer && (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div className="space-y-4">
            <Card>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex gap-4">
                  <Avatar name={String(customer.full_name)} className="h-14 w-14 rounded-panel text-lg" />
                  <div>
                    <h1 className="text-2xl font-semibold tracking-tight">{customer.full_name}</h1>
                    <div className="mt-1 text-sm text-muted">{customer.phone}</div>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                      <Badge tone={tone(String(customer.status))}>{label(String(customer.status))}</Badge>
                      {Number(customer.vip) === 1 && <Badge tone="orange">VIP</Badge>}
                      {data.manager?.full_name && <span className="text-sm text-muted">{data.manager.full_name}</span>}
                    </div>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <a href={`tel:${customer.phone}`}><Button tone="ghost">Позвонить</Button></a>
                  <Button tone="ghost" onClick={async () => { const conv = await api<{ id: number }>('/whatsapp/conversations', { method: 'POST', body: { customer_id: Number(id) } }); navigate('/whatsapp?conversation=' + conv.id) }}>WhatsApp</Button>
                  <Button tone="secondary" onClick={() => setModal('pay')}>Продлить</Button>
                  <Button onClick={() => setModal('pay')}>Создать оплату</Button>
                </div>
              </div>
              <div className="mt-5 grid gap-3 sm:grid-cols-3">
                <div className="rounded-panel bg-paper p-4"><div className="text-xs text-muted">Программа</div><div className="mt-1 font-semibold">{String(current?.program_name || '—')}</div></div>
                <div className={`rounded-panel p-4 ${daysLeft !== null && daysLeft <= 3 ? 'bg-danger/10' : 'bg-paper'}`}><div className="text-xs text-muted">Осталось</div><div className={`mt-1 font-semibold ${daysLeft !== null && daysLeft <= 3 ? 'text-danger' : ''}`}>{daysLabel(daysLeft)} · до {fmtDate(String(current?.end_date || ''))}</div></div>
                <div className="rounded-panel bg-soft p-4"><div className="text-xs text-muted">Стоимость</div><div className="num mt-1 text-lg font-semibold">{money(Number(current?.total_amount || 0))}</div></div>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                <Button tone="ghost" onClick={() => setModal('task')}>Задача</Button>
                <Button tone="ghost" onClick={() => setModal('address')}>Адрес</Button>
                <Button tone="ghost" onClick={() => current && run('/subscriptions/' + current.id + '/pause', undefined, 'Подписка на паузе')}>Приостановить</Button>
                <Button tone="ghost" onClick={() => setModal('discount')}>Скидка</Button>
                <Button tone="soft" onClick={() => openGrok('Что ты думаешь по этому клиенту?')}>Ассистент</Button>
              </div>
            </Card>
            <Card>
              <div className="text-sm text-muted">Доставка</div>
              <div className="mt-2 font-semibold">{data.schedule.map((d) => days[d.weekday - 1]).join(' ') || 'Дни не выбраны'} · {label(data.schedule[0]?.slot)}</div>
              <div className="text-sm text-muted">{data.address ? `${data.address.city}, ${data.address.street} ${data.address.building}` : 'Адрес не заполнен'}</div>
            </Card>
            <Tabs tabs={[['overview', 'Обзор'], ['subs', 'Подписки'], ['pays', 'Оплаты'], ['delivery', 'Доставки'], ['food', 'Питание'], ['wa', 'WhatsApp'], ['history', 'История'], ['tasks', 'Задачи'], ['docs', 'Документы']]} value={tab} onChange={setTab} />
            {tab === 'overview' && (
              <Card>
                <dl className="grid gap-3 sm:grid-cols-2 text-sm">
                  {[
                    ['Телефон', customer.phone], ['WhatsApp', customer.whatsapp], ['Город', customer.city], ['Район', customer.district],
                    ['Источник', customer.source], ['Первое обращение', fmtDate(String(customer.first_contact_at || ''))],
                    ['Первый платёж', fmtDate(String(customer.first_payment_at || ''))], ['Последний платёж', fmtDate(String(customer.last_payment_at || ''))],
                    ['Заказов', data.stats.orders], ['LTV', money(data.stats.ltv)], ['Средний чек', money(data.stats.avg_check)], ['Lifetime', data.stats.lifetime_days + ' дн.'],
                  ].map(([k, v]) => <div key={String(k)}><dt className="text-muted">{k}</dt><dd className="font-medium">{String(v || '—')}</dd></div>)}
                </dl>
                {customer.comment && <p className="mt-4 text-sm">{customer.comment}</p>}
              </Card>
            )}
            {tab === 'subs' && data.subscriptions.map((sub) => (
              <Card key={String(sub.id)}><div className="flex justify-between"><b>{sub.program_name}</b><Badge tone={tone(String(sub.status))}>{label(String(sub.status))}</Badge></div><div className="mt-2 text-sm text-muted">{fmtDate(String(sub.start_date))} — {fmtDate(String(sub.end_date))} · {daysLabel(Number(sub.days_left))} · {money(Number(sub.total_amount))}</div></Card>
            ))}
            {tab === 'pays' && <Card>{data.payments.map((p) => (
              <div key={String(p.id)} className="flex flex-wrap items-center justify-between gap-2 border-b border-line py-2 text-sm">
                <span>{fmtDate(String(p.paid_at || p.created_at))} · {p.number}</span>
                <span className="flex items-center gap-2">{money(Number(p.amount))} · {label(String(p.status))}
                  {p.status === 'pending' && <Button tone="ghost" onClick={() => api('/payments/' + p.id + '/mark-paid', { method: 'POST' }).then(() => { toast.success('Оплата отмечена'); reload() }).catch((err) => toast.error(err instanceof Error ? err.message : 'Ошибка'))}>Отметить оплаченным</Button>}
                </span>
              </div>
            ))}</Card>}
            {tab === 'delivery' && <Card>{data.deliveries.map((d) => <div key={String(d.id)} className="border-b border-line py-2 text-sm">{d.delivery_date} · {label(String(d.slot))} · {d.address} · {label(String(d.status))}</div>)}</Card>}
            {tab === 'food' && <Card><div className="text-lg font-semibold">{String(current?.program_name || 'Нет программы')}</div><p className="mt-2 text-sm text-muted">{current?.description}</p><div className="mt-2 text-sm">{current?.calories} ккал · {current?.meals_per_day} приёмов</div></Card>}
            {tab === 'wa' && <Card>{data.messages.map((m, i) => <div key={i} className="py-1 text-sm"><b>{m.direction === 'in' ? 'Клиент' : 'Мы'}:</b> {m.body}</div>)}<Link className="text-sm text-lime-deep" to={'/whatsapp?conversation=' + (data.conversation_id || '')}>Открыть диалог</Link></Card>}
            {tab === 'history' && <Card>{data.timeline.map((e, i) => <div key={i} className="border-l-2 border-lime py-2 pl-3 text-sm"><div className="text-muted">{fmtDate(e.at)}</div>{e.text}</div>)}</Card>}
            {tab === 'tasks' && <Card className="divide-y divide-line">{data.tasks.map((t) => {
              const overdue = t.status !== 'done' && t.due_date && String(t.due_date) < new Date().toLocaleDateString('en-CA')
              return <div key={String(t.id)} className={`flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm ${overdue ? 'border-l-2 border-danger pl-3' : ''}`}><span className="font-medium">{t.title}</span><span className="flex items-center gap-2 text-muted"><Badge tone={tone(String(t.status))}>{label(String(t.status))}</Badge>{t.due_date}</span></div>
            })}</Card>}
            {tab === 'docs' && (
              <Card>
                {data.documents.map((d) => <a key={d.id} className="block py-1 text-sm" href={'/api/documents/' + d.id}>{d.name}</a>)}
                <form className="mt-3" onSubmit={async (e) => {
                  e.preventDefault()
                  const form = new FormData(e.currentTarget)
                  await api('/customers/' + id + '/documents', { method: 'POST', form })
                  reload()
                }}><Input name="file" type="file" /><Button className="mt-2" type="submit">Загрузить</Button></form>
              </Card>
            )}
          </div>
          <Card>
            <h2 className="font-semibold">История оплат</h2>
            {data.payments.slice(0, 6).map((p) => <div key={String(p.id)} className="flex justify-between border-b border-line py-2 text-sm"><span>{fmtDate(String(p.paid_at || p.created_at))}</span><b>{money(Number(p.amount))}</b></div>)}
            <h2 className="mt-5 font-semibold">Таймлайн</h2>
            {data.timeline.slice(0, 8).map((e, i) => <div key={i} className="py-2 text-sm"><div className="text-xs text-muted">{fmtDate(e.at)}</div>{e.text}</div>)}
          </Card>
          <Modal open={modal === 'pay'} title="Создать оплату" onClose={() => setModal('')}>
            <PayForm id={id} subs={data.subscriptions} onDone={() => { setModal(''); reload() }} />
          </Modal>
          <Modal open={modal === 'discount'} title="Скидка на удержание" onClose={() => setModal('')}>
            <DiscountForm id={id} price={Number(current?.price || 0)} onDone={(result) => { toast.success(`Итог ${money(result.total)}`); setModal(''); reload() }} />
          </Modal>
          <Modal open={modal === 'task'} title="Задача" onClose={() => setModal('')}>
            <form className="grid gap-3" onSubmit={(e) => { e.preventDefault(); const f = new FormData(e.currentTarget); run('/tasks', { title: f.get('title'), customer_id: Number(id), due_date: f.get('due_date'), priority: 'high' }, 'Задача создана') }}>
              <Field label="Название"><Input name="title" defaultValue="Позвонить клиенту для продления" /></Field>
              <Field label="Дата"><Input name="due_date" type="date" /></Field>
              <Button type="submit">Создать</Button>
            </form>
          </Modal>
          <Modal open={modal === 'address'} title="Адрес и график" onClose={() => setModal('')}>
            <AddressForm data={data} onSave={saveCustomer} />
          </Modal>
        </div>
      )}
    </Gate>
  )
}

function PayForm({ id, subs, onDone }: { id: string; subs: Array<Record<string, string | number | null>>; onDone: () => void }) {
  const [link, setLink] = useState('')
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const result = await api<{ link: { url: string }; payment: { id: number } }>('/payments', { method: 'POST', body: { customer_id: Number(id), subscription_id: Number(form.get('subscription_id')), amount: Number(form.get('amount')), method: 'kaspi' } })
    setLink(result.link.url)
    toast.success('Счёт создан. Kaspi не подключён — оплату отмечает менеджер.')
  }
  return (
    <form className="grid gap-3" onSubmit={submit}>
      <Field label="Подписка"><Select name="subscription_id">{subs.map((s) => <option key={String(s.id)} value={String(s.id)}>{s.program_name} · {money(Number(s.total_amount))}</option>)}</Select></Field>
      <Field label="Сумма"><Input name="amount" type="number" defaultValue={Number(subs[0]?.total_amount || 0)} /></Field>
      <Button type="submit">Создать счёт</Button>
      {link && <div className="text-sm break-all">Ссылка: {link}<div className="mt-2"><Button type="button" tone="ghost" onClick={onDone}>Готово</Button></div></div>}
    </form>
  )
}

function DiscountForm({ id, price, onDone }: { id: string; price: number; onDone: (r: { total: number }) => void }) {
  const [percent, setPercent] = useState(10)
  const discount = Math.round(price * percent / 100)
  return (
    <form className="grid gap-3" onSubmit={async (e) => { e.preventDefault(); const result = await api<{ total: number }>('/customers/' + id + '/discount', { method: 'POST', body: { percent } }); onDone(result) }}>
      <div className="flex gap-2">{[5, 10, 15, 20].map((n) => <button type="button" key={n} className={`rounded-xl px-3 py-2 ${percent === n ? 'bg-lime' : 'bg-paper'}`} onClick={() => setPercent(n)}>{n}%</button>)}</div>
      <Field label="Или процент вручную"><Input type="number" value={percent} onChange={(e) => setPercent(Number(e.target.value))} /></Field>
      <div className="text-sm">Обычная цена: {money(price)}<br />Скидка: {percent}%<br />Итог: {money(price - discount)}</div>
      <Button type="submit">Сохранить скидку</Button>
    </form>
  )
}

function AddressForm({ data, onSave }: { data: CardData; onSave: (body: unknown) => void }) {
  const selected = new Set(data.schedule.map((d) => d.weekday))
  const [daysOn, setDays] = useState<number[]>([...selected])
  const address = data.address || {}
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    onSave({
      address: { city: form.get('city'), district: form.get('district'), street: form.get('street'), building: form.get('building'), apartment: form.get('apartment') },
      schedule: { days: daysOn, slot: form.get('slot'), time_from: form.get('time_from'), time_to: form.get('time_to') },
    })
  }
  return (
    <form className="grid gap-3" onSubmit={submit}>
      <div className="flex flex-wrap gap-2">{days.map((name, i) => <button type="button" key={name} className={`rounded-xl px-3 py-2 ${daysOn.includes(i + 1) ? 'bg-lime' : 'bg-paper'}`} onClick={() => setDays(daysOn.includes(i + 1) ? daysOn.filter((d) => d !== i + 1) : [...daysOn, i + 1])}>{name}</button>)}</div>
      <Field label="Слот"><Select name="slot" defaultValue={data.schedule[0]?.slot || 'morning'}><option value="morning">Утро</option><option value="day">День</option><option value="evening">Вечер</option><option value="custom">Свой интервал</option></Select></Field>
      <div className="grid grid-cols-2 gap-2"><Field label="С"><Input name="time_from" type="time" /></Field><Field label="До"><Input name="time_to" type="time" /></Field></div>
      <Field label="Город"><Input name="city" defaultValue={address.city || ''} /></Field>
      <Field label="Район"><Input name="district" defaultValue={address.district || ''} /></Field>
      <Field label="Улица"><Input name="street" defaultValue={address.street || ''} /></Field>
      <Field label="Дом"><Input name="building" defaultValue={address.building || ''} /></Field>
      <Button type="submit">Сохранить</Button>
    </form>
  )
}
