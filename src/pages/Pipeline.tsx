import { FormEvent, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { DndContext, PointerSensor, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core'
import { toast } from 'sonner'
import { Button, Empty, Field, Input, Modal, PageHeader, Select, Skeleton } from '../components/ui'
import { api } from '../lib/api'
import { money } from '../lib/format'
import { leadStages } from '../lib/labels'
import { useApi } from '../lib/useApi'

type Lead = {
  id: number
  name: string
  phone: string
  stage: string
  manager_name?: string
  program_name?: string
  expected_amount: number
  source: string
  comment?: string
  created_at: string
  last_contact_at?: string
  next_contact_at?: string
  customer_id?: number
}

function LeadCard({ lead }: { lead: Lead }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id: String(lead.id) })
  const style = transform ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)`, zIndex: 5 } : undefined
  return (
    <article ref={setNodeRef} style={style} {...listeners} {...attributes} className={`cursor-grab rounded-panel border border-line bg-card p-3 shadow-card transition hover:border-lime/40 ${isDragging ? 'opacity-70' : ''}`}>
      <div className="font-semibold">{lead.name}</div>
      <div className="text-sm text-muted">{lead.phone}</div>
      <div className="mt-2 text-sm">{lead.program_name || 'Программа не выбрана'}</div>
      <div className="num mt-1 text-sm font-semibold">{money(lead.expected_amount)}</div>
      <div className="mt-2 text-xs text-muted">{lead.manager_name} · {lead.source}</div>
      {lead.next_contact_at && <div className="text-xs text-muted">Следующий контакт {lead.next_contact_at}</div>}
      {lead.comment && <p className="mt-2 text-xs">{lead.comment}</p>}
    </article>
  )
}

function Column({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  const { setNodeRef, isOver } = useDroppable({ id })
  return (
    <div ref={setNodeRef} className={`w-72 shrink-0 rounded-panel bg-paper p-2 transition ${isOver ? 'ring-2 ring-lime' : ''}`}>
      <div className="px-2 py-2 text-xs font-semibold tracking-wide text-muted uppercase">{title}</div>
      <div className="grid gap-2">{children}</div>
    </div>
  )
}

export function PipelinePage() {
  const { data, loading, error, reload, setData } = useApi<{ data: Lead[] }>('/leads')
  const [open, setOpen] = useState(false)
  const programs = useApi<{ data: Array<{ id: number; name: string }> }>('/programs')
  const staff = useApi<{ data: Array<{ id: number; full_name: string }> }>('/staff')
  const navigate = useNavigate()
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }))
  const grouped = useMemo(() => {
    const map: Record<string, Lead[]> = {}
    for (const stage of leadStages) map[stage[0]] = []
    for (const lead of data?.data ?? []) map[lead.stage]?.push(lead)
    return map
  }, [data])

  async function onDragEnd(event: DragEndEvent) {
    const stage = String(event.over?.id || '')
    const id = Number(event.active.id)
    const lead = data?.data.find((item) => item.id === id)
    if (!lead || !stage || lead.stage === stage) return
    setData({ data: data!.data.map((item) => item.id === id ? { ...item, stage } : item) })
    try {
      const updated = await api<Lead>('/leads/' + id, { method: 'PATCH', body: { stage } })
      if (stage === 'active_client' && updated.customer_id) navigate('/customers/' + updated.customer_id)
      else reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Не удалось перенести лид')
      reload()
    }
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    try {
      await api('/leads', { method: 'POST', body: Object.fromEntries(form.entries()) })
      setOpen(false)
      reload()
      toast.success('Лид создан')
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка')
    }
  }

  if (loading) return <div className="grid gap-3 md:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-48" />)}</div>
  if (error) return <Empty tone="error" title="Не удалось загрузить" text={error} />

  return (
    <div>
      <PageHeader title="Воронка продаж" text="Оплаченный лид сразу становится клиентом с подпиской.">
        <Button onClick={() => setOpen(true)}>Новый лид</Button>
      </PageHeader>
      <DndContext sensors={sensors} onDragEnd={onDragEnd}>
        <div className="flex gap-3 overflow-auto pb-4">
          {leadStages.map(([id, title]) => (
            <Column key={id} id={id} title={`${title} · ${grouped[id]?.length || 0}`}>
              {grouped[id]?.map((lead) => <LeadCard key={lead.id} lead={lead} />)}
            </Column>
          ))}
        </div>
      </DndContext>
      <Modal open={open} title="Новый лид" onClose={() => setOpen(false)}>
        <form className="grid gap-3" onSubmit={create}>
          <Field label="Имя"><Input name="name" required /></Field>
          <Field label="Телефон"><Input name="phone" placeholder="+7 777 000 00 00" required /></Field>
          <Field label="Источник"><Select name="source"><option value="instagram">Instagram</option><option value="site">Сайт</option><option value="call">Звонок</option><option value="whatsapp">WhatsApp</option></Select></Field>
          <Field label="Программа"><Select name="program_id">{programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</Select></Field>
          <Field label="Сумма"><Input name="expected_amount" type="number" defaultValue={99000} /></Field>
          <Field label="Менеджер"><Select name="manager_id">{staff.data?.data.map((p) => <option key={p.id} value={p.id}>{p.full_name}</option>)}</Select></Field>
          <Button type="submit">Сохранить</Button>
        </form>
      </Modal>
    </div>
  )
}
