import { FormEvent, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AlertCircle } from 'lucide-react'
import { toast } from 'sonner'
import { Avatar, Badge, Button, Card, Field, Gate, Input, Modal, Select, Tabs, Textarea } from '../components/ui'
import { api } from '../lib/api'
import { daysLabel, fmtDate, money } from '../lib/format'
import { label, tone } from '../lib/labels'
import { TariffBadge, statusLabel } from '../lib/nutrition'
import { useApi } from '../lib/useApi'
import { useApp } from '../store'

type Ration = Record<string, string | number | null | boolean> & {
  id: number
  full_name?: string
  line?: string
  status?: string
  needs_review?: number
  review_reasons?: string | null
  expired_active?: boolean
}

type CardData = {
  customer: Record<string, string | number | null>
  manager: { full_name: string } | null
  address: Record<string, string | null> | null
  schedule: Array<{ weekday: number; slot: string; time_from?: string; time_to?: string }>
  subscriptions: Array<Record<string, string | number | null>>
  rations: Ration[]
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
  const [modal, setModal] = useState<'pay' | 'discount' | 'task' | 'address' | 'ration' | ''>('')
  const [editRation, setEditRation] = useState<Ration | null>(null)
  const navigate = useNavigate()
  const openGrok = useApp((s) => s.openGrok)
  const customer = data?.customer
  const current = data?.current
  const daysLeft = current ? Number(current.days_left) : null
  const rations = data?.rations ?? []
  const latestRation = rations[0]

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
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="text-sm text-muted">Подписка питания</div>
                  {latestRation ? (
                    <div className="mt-2 space-y-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold">{String(latestRation.line)}</span>
                        <TariffBadge row={latestRation} />
                        <Badge tone={tone(String(latestRation.status))}>{statusLabel(String(latestRation.status))}</Badge>
                        {latestRation.needs_review ? <span className="inline-flex items-center gap-1 text-xs text-orange"><AlertCircle size={12} /> проверить</span> : null}
                      </div>
                      <div className="text-sm text-muted">
                        пакет {String(latestRation.pack_days || '—')} · {String(latestRation.kcal || '—')} ккал · рацион {String(latestRation.ration_no || '—')}
                      </div>
                      <div className="text-sm text-muted">
                        {String(latestRation.diet || 'диета —')} · {String(latestRation.delivery_time || 'время —')} · до {fmtDate(String(latestRation.end_date || ''))}
                      </div>
                      {latestRation.review_reasons ? <p className="text-sm text-orange">{String(latestRation.review_reasons)}</p> : null}
                    </div>
                  ) : (
                    <p className="mt-2 text-sm text-muted">Подписок питания пока нет.</p>
                  )}
                </div>
                <Button tone="secondary" onClick={() => { setEditRation(null); setModal('ration') }}>Добавить</Button>
              </div>
            </Card>
            <Card>
              <div className="text-sm text-muted">Доставка</div>
              <div className="mt-2 font-semibold">{data.schedule.map((d) => days[d.weekday - 1]).join(' ') || 'Дни не выбраны'} · {label(data.schedule[0]?.slot)}</div>
              <div className="text-sm text-muted">{data.address ? `${data.address.city}, ${data.address.street} ${data.address.building}` : 'Адрес не заполнен'}</div>
            </Card>
            <Tabs tabs={[['overview', 'Обзор'], ['rations', 'Подписка'], ['subs', 'Продажи'], ['pays', 'Оплаты'], ['delivery', 'Доставки'], ['food', 'Программа'], ['wa', 'WhatsApp'], ['history', 'История'], ['tasks', 'Задачи'], ['docs', 'Документы']]} value={tab} onChange={setTab} />
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
            {tab === 'rations' && (
              <div className="space-y-3">
                {rations.length === 0 && <Card><p className="text-sm text-muted">Нет подписок питания.</p></Card>}
                {rations.map((ration) => (
                  <Card key={String(ration.id)}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <div className="flex flex-wrap items-center gap-2">
                          <b>{String(ration.full_name)}</b>
                          <TariffBadge row={ration} />
                          <Badge tone={tone(String(ration.status))}>{statusLabel(String(ration.status))}</Badge>
                          {ration.needs_review ? <span className="inline-flex items-center gap-1 text-xs text-orange"><AlertCircle size={12} /> проверить</span> : null}
                        </div>
                        <dl className="mt-3 grid gap-2 sm:grid-cols-2 text-sm">
                          {[
                            ['Линейка', ration.line], ['Раздел', ration.section], ['Тариф', ration.tariff_days],
                            ['Пакет', ration.pack_days], ['Ккал', ration.kcal], ['Рацион', ration.ration_no],
                            ['Диета', ration.diet], ['Доставка', ration.delivery_time],
                            ['Период', `${fmtDate(String(ration.period_start || ''))} — ${fmtDate(String(ration.period_end || ''))}`],
                            ['Окончание', fmtDate(String(ration.end_date || ''))], ['Паузы', ration.pauses],
                            ['Метка', ration.flag_mark], ['Примечание', ration.notes],
                            ['Причины проверки', ration.review_reasons],
                          ].map(([k, v]) => <div key={String(k)}><dt className="text-muted">{k}</dt><dd className="font-medium">{String(v || '—')}</dd></div>)}
                        </dl>
                      </div>
                      <Button tone="ghost" onClick={() => { setEditRation(ration); setModal('ration') }}>Изменить</Button>
                    </div>
                  </Card>
                ))}
              </div>
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
          <Modal open={modal === 'ration'} title={editRation ? 'Изменить подписку' : 'Новая подписка питания'} onClose={() => setModal('')}>
            <RationForm
              customerName={String(customer.full_name)}
              ration={editRation}
              onSubmit={async (body) => {
                try {
                  if (editRation) await api('/customers/' + id + '/rations/' + editRation.id, { method: 'PATCH', body })
                  else await api('/customers/' + id + '/rations', { method: 'POST', body })
                  toast.success('Подписка сохранена')
                  setModal('')
                  reload()
                } catch (err) {
                  toast.error(err instanceof Error ? err.message : 'Ошибка')
                }
              }}
            />
          </Modal>
        </div>
      )}
    </Gate>
  )
}

function RationForm({ customerName, ration, onSubmit }: { customerName: string; ration: Ration | null; onSubmit: (body: Record<string, unknown>) => void }) {
  return (
    <form className="grid max-h-[70dvh] gap-3 overflow-auto" onSubmit={(e) => {
      e.preventDefault()
      const f = new FormData(e.currentTarget)
      onSubmit({
        full_name: f.get('full_name'),
        line: f.get('line'),
        section: f.get('section') || null,
        tariff_days: f.get('tariff_days') ? Number(f.get('tariff_days')) : null,
        pack_days: f.get('pack_days') || null,
        kcal: f.get('kcal') || null,
        ration_no: f.get('ration_no') || null,
        diet: f.get('diet') || null,
        delivery_time: f.get('delivery_time') || null,
        period_start: f.get('period_start') || null,
        period_end: f.get('period_end') || null,
        end_date: f.get('end_date') || null,
        pauses: f.get('pauses') || null,
        status: f.get('status'),
        tariff_days_ref: f.get('tariff_days_ref') ? Number(f.get('tariff_days_ref')) : null,
        flag_mark: f.get('flag_mark') || null,
        notes: f.get('notes') || null,
        needs_review: f.get('needs_review') === '1',
        review_reasons: f.get('review_reasons') || null,
      })
    }}>
      <Field label="ФИО"><Input name="full_name" defaultValue={String(ration?.full_name || customerName)} required /></Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Линейка"><Select name="line" defaultValue={String(ration?.line || 'Стандарт')}><option>Премиум</option><option>Стандарт</option></Select></Field>
        <Field label="Статус"><Select name="status" defaultValue={String(ration?.status || 'active')}><option value="active">Активный</option><option value="ended">Подписка закончилась</option><option value="paused">Пауза</option></Select></Field>
        <Field label="Раздел"><Input name="section" defaultValue={String(ration?.section || '')} /></Field>
        <Field label="Дней в неделю"><Select name="tariff_days" defaultValue={ration?.tariff_days == null ? '' : String(ration.tariff_days)}><option value="">—</option><option value="7">7</option><option value="6">6</option><option value="5">5</option><option value="4">4</option></Select></Field>
        <Field label="Пакет"><Input name="pack_days" defaultValue={String(ration?.pack_days || '')} /></Field>
        <Field label="Ккал"><Input name="kcal" defaultValue={String(ration?.kcal || '')} /></Field>
        <Field label="Рацион №"><Input name="ration_no" defaultValue={String(ration?.ration_no || '')} /></Field>
        <Field label="Диета"><Input name="diet" defaultValue={String(ration?.diet || '')} /></Field>
        <Field label="Время доставки"><Select name="delivery_time" defaultValue={String(ration?.delivery_time || '')}><option value="">—</option><option value="утро">утро</option><option value="вечер">вечер</option></Select></Field>
        <Field label="Метка"><Input name="flag_mark" defaultValue={String(ration?.flag_mark || '')} maxLength={5} /></Field>
        <Field label="Период с"><Input name="period_start" type="date" defaultValue={String(ration?.period_start || '')} /></Field>
        <Field label="Период по"><Input name="period_end" type="date" defaultValue={String(ration?.period_end || '')} /></Field>
        <Field label="Дата окончания"><Input name="end_date" type="date" defaultValue={String(ration?.end_date || '')} /></Field>
        <Field label="Дней справочно"><Input name="tariff_days_ref" type="number" defaultValue={String(ration?.tariff_days_ref || '')} /></Field>
      </div>
      <Field label="Паузы"><Textarea name="pauses" defaultValue={String(ration?.pauses || '')} /></Field>
      <Field label="Примечание"><Textarea name="notes" defaultValue={String(ration?.notes || '')} /></Field>
      <Field label="Причины проверки"><Textarea name="review_reasons" defaultValue={String(ration?.review_reasons || '')} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="needs_review" value="1" defaultChecked={Number(ration?.needs_review) === 1} /> Требует проверки</label>
      <Button type="submit">Сохранить</Button>
    </form>
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
