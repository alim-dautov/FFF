import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ClipboardList, Sparkles, Truck, Users, Wallet } from 'lucide-react'
import { money } from '../lib/format'
import { useApi } from '../lib/useApi'
import { useApp } from '../store'
import { Card, Gate, PageHeader, StatCard } from '../components/ui'

type Dash = {
  kpis: Record<string, number | null>
  attention: Array<{ title: string; subtitle: string; href: string; tone: string }>
  focus: Array<{ label: string; value: number; href: string }>
}

const kpiMeta: Array<[string, string]> = [
  ['revenue_today', 'Выручка сегодня'],
  ['revenue_month', 'Выручка за месяц'],
  ['new_customers', 'Новые клиенты'],
  ['active_customers', 'Активные клиенты'],
  ['renewal_customers', 'Клиенты на продление'],
  ['ending_today', 'Заканчиваются сегодня'],
  ['ending_1_3', 'Заканчиваются через 1–3 дня'],
  ['overdue', 'Просроченные продления'],
  ['new_leads', 'Новые лиды'],
  ['conversion', 'Конверсия продаж'],
  ['avg_check', 'Средний чек'],
  ['mrr', 'MRR'],
  ['deliveries_today', 'Доставки сегодня'],
]

const moneyKeys = new Set(['revenue_today', 'revenue_month', 'avg_check', 'mrr'])
const groups: Array<{ title: string; keys: string[] }> = [
  { title: 'Деньги', keys: ['revenue_today', 'revenue_month', 'avg_check', 'mrr'] },
  { title: 'Клиенты и продажи', keys: ['new_customers', 'active_customers', 'renewal_customers', 'new_leads', 'conversion', 'deliveries_today'] },
  { title: 'Сроки', keys: ['ending_today', 'ending_1_3', 'overdue'] },
]

function kpiTone(key: string): 'lime' | 'info' | 'orange' | 'danger' | 'neutral' {
  if (moneyKeys.has(key) || key === 'conversion') return 'lime'
  if (key === 'overdue') return 'danger'
  if (key === 'ending_today' || key === 'ending_1_3' || key === 'renewal_customers') return 'orange'
  if (key === 'deliveries_today') return 'neutral'
  return 'info'
}

function kpiIcon(key: string) {
  if (moneyKeys.has(key) || key === 'conversion') return <Wallet size={15} strokeWidth={1.75} />
  if (key === 'overdue' || key.startsWith('ending')) return <AlertTriangle size={15} strokeWidth={1.75} />
  if (key === 'new_leads') return <ClipboardList size={15} strokeWidth={1.75} />
  if (key === 'deliveries_today') return <Truck size={15} strokeWidth={1.75} />
  return <Users size={15} strokeWidth={1.75} />
}

function formatKpi(key: string, value: number) {
  if (moneyKeys.has(key)) return money(value)
  if (key === 'conversion') return `${value}%`
  return value
}

function focusTone(label: string): 'lime' | 'info' | 'orange' | 'danger' | 'neutral' {
  if (label.includes('Просрочен') || label.includes('Проблем')) return 'danger'
  if (label.includes('ожидают') || label.includes('минимума') || label.includes('заканчивают')) return 'orange'
  if (label.includes('лиды')) return 'info'
  return 'neutral'
}

export function DashboardPage() {
  const { data, loading, error } = useApi<Dash>('/dashboard')
  const navigate = useNavigate()
  const openGrok = useApp((s) => s.openGrok)
  const titles = Object.fromEntries(kpiMeta)
  return (
    <div>
      <PageHeader title="Сегодня в FOODFORFIT" text="Контроль продаж, подписок и продлений." />
      <Gate loading={loading} error={error}>
        {data && (
          <div className="space-y-8">
            {groups.map((group) => {
              const keys = group.keys.filter((key) => data.kpis[key] !== null && data.kpis[key] !== undefined)
              if (!keys.length) return null
              return (
                <section key={group.title}>
                  <h2 className="mb-3 text-xs font-semibold tracking-wide text-muted uppercase">{group.title}</h2>
                  <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {keys.map((key) => (
                      <StatCard key={key} label={titles[key]} tone={kpiTone(key)} icon={kpiIcon(key)} value={formatKpi(key, Number(data.kpis[key]))} />
                    ))}
                  </div>
                </section>
              )
            })}
            <div className="grid gap-4 xl:grid-cols-[1.2fr_.8fr]">
              <Card>
                <h2 className="text-base font-semibold">Сегодня требуют внимания</h2>
                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                  {data.focus.map((item) => {
                    const tone = focusTone(item.label)
                    return (
                      <button key={item.label} onClick={() => navigate(item.href)} className={`rounded-panel border border-line border-l-2 px-4 py-3 text-left transition hover:bg-paper ${tone === 'danger' ? 'border-l-danger' : tone === 'orange' ? 'border-l-orange' : tone === 'info' ? 'border-l-info' : 'border-l-lime'}`}>
                        <div className="num text-2xl font-semibold tracking-tight">{item.value}</div>
                        <div className="mt-1 text-sm text-muted">{item.label}</div>
                      </button>
                    )
                  })}
                </div>
              </Card>
              <Card className="border-ai/20 bg-ai-soft/40">
                <div className="flex items-center gap-2">
                  <span className="grid h-8 w-8 place-items-center rounded-control bg-ai-soft text-ai"><Sparkles size={15} strokeWidth={1.75} /></span>
                  <h2 className="text-base font-semibold">Ассистент</h2>
                </div>
                <p className="mt-2 text-sm text-muted">Спросите о продажах, клиентах, выручке, складе...</p>
                <div className="mt-4 grid gap-2">
                  {['Выручка за месяц', 'Клиенты на продление', 'Что требует внимания?'].map((prompt) => (
                    <button key={prompt} className="rounded-control bg-card px-3 py-2.5 text-left text-sm transition hover:bg-paper" onClick={() => openGrok(prompt)}>{prompt}</button>
                  ))}
                </div>
              </Card>
            </div>
            <Card>
              <h2 className="mb-1 text-base font-semibold">Требует внимания</h2>
              <div className="divide-y divide-line">
                {data.attention.length === 0 && <p className="py-4 text-sm text-muted">Срочных сигналов нет.</p>}
                {data.attention.map((item) => (
                  <button key={item.title} onClick={() => navigate(item.href)} className={`flex w-full items-center justify-between gap-3 border-l-2 py-3 pl-3 text-left ${item.tone === 'critical' ? 'border-danger' : item.tone === 'important' ? 'border-orange' : 'border-info'}`}>
                    <span>
                      <span className="font-medium">{item.title}</span>
                      <span className="mt-0.5 block text-sm text-muted">{item.subtitle}</span>
                    </span>
                    <span className="shrink-0 text-sm font-medium text-lime-deep">Открыть</span>
                  </button>
                ))}
              </div>
            </Card>
          </div>
        )}
      </Gate>
    </div>
  )
}
