import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Bell, ChartColumn, ClipboardList, LayoutDashboard, LogOut, Menu, MessageCircle, Moon, Package, Repeat, Salad, Search, Settings, Sparkles, Sun, Truck, Users, Wallet } from 'lucide-react'
import { api } from '../lib/api'
import { clsx } from '../lib/format'
import { useApp } from '../store'
import { GrokPanel } from './GrokPanel'

const nav = [
  { to: '/', label: 'Главная', icon: LayoutDashboard, perm: 'dashboard.view', end: true },
  { to: '/pipeline', label: 'Воронка продаж', icon: ClipboardList, perm: 'leads.view' },
  { to: '/customers', label: 'Клиенты', icon: Users, perm: 'customers.view', badge: 'customers' },
  { to: '/subscriptions', label: 'Подписки', icon: Repeat, perm: 'subscriptions.view', badge: 'renewals' },
  { to: '/deliveries', label: 'Доставки', icon: Truck, perm: 'deliveries.view' },
  { to: '/orders', label: 'Заказы', icon: ClipboardList, perm: 'payments.view' },
  { to: '/inventory', label: 'Склад', icon: Package, perm: 'inventory.view' },
  { to: '/programs', label: 'Программы', icon: Salad, perm: 'programs.view' },
  { to: '/employees', label: 'Сотрудники', icon: Users, perm: 'employees.view', badge: 'tasks', alt: 'tasks.view' },
  { to: '/finance', label: 'Финансы', icon: Wallet, perm: 'finance.view' },
  { to: '/whatsapp', label: 'WhatsApp', icon: MessageCircle, perm: 'whatsapp.view' },
  { to: '/analytics', label: 'Аналитика', icon: ChartColumn, perm: 'analytics.view' },
  { to: '/notifications', label: 'Уведомления', icon: Bell, perm: 'notifications.view', badge: 'notifications' },
  { to: '/settings', label: 'Настройки', icon: Settings, perm: 'dashboard.view' },
]

type Badges = { notifications: number; customers: number; renewals: number; tasks: number }

export function Shell() {
  const { can, user, logout, theme, setTheme, openGrok } = useApp()
  const [badges, setBadges] = useState<Badges>({ notifications: 0, customers: 0, renewals: 0, tasks: 0 })
  const [query, setQuery] = useState('')
  const [hits, setHits] = useState<Record<string, Array<{ id: number; full_name?: string; name?: string; phone?: string; number?: string }>> | null>(null)
  const [more, setMore] = useState(false)
  const navigate = useNavigate()
  const location = useLocation()

  useEffect(() => {
    const load = () => api<Badges>('/badges').then(setBadges).catch(() => undefined)
    load()
    const timer = window.setInterval(load, 60000)
    return () => window.clearInterval(timer)
  }, [location.pathname])

  useEffect(() => {
    if (query.trim().length < 2) {
      setHits(null)
      return
    }
    const timer = window.setTimeout(() => {
      api<Record<string, Array<{ id: number; full_name?: string; name?: string; phone?: string; number?: string }>>>('/search?q=' + encodeURIComponent(query))
        .then(setHits)
        .catch(() => setHits(null))
    }, 280)
    return () => window.clearTimeout(timer)
  }, [query])

  const items = nav.filter((item) => can(item.perm) || (item.alt && can(item.alt)) || (item.to === '/deliveries' && can('deliveries.own')))
  const linkClass = ({ isActive }: { isActive: boolean }) => clsx('flex min-h-10 items-center gap-2.5 rounded-control px-2.5 text-sm transition', isActive ? 'bg-soft font-semibold text-ink' : 'text-muted hover:bg-paper hover:text-ink')
  const iconBtn = 'grid h-10 w-10 shrink-0 place-items-center rounded-control border border-line bg-card text-muted transition hover:bg-paper hover:text-ink'

  return (
    <div className="min-h-dvh md:grid md:h-dvh md:max-h-dvh md:grid-cols-[248px_1fr] md:overflow-hidden">
      <aside className="sticky top-0 hidden h-dvh max-h-dvh flex-col overflow-hidden border-r border-line bg-card px-3 py-3 md:flex">
        <div className="mb-4 shrink-0 rounded-panel bg-graphite px-3 py-2.5">
          <img src="/brand/logo.png" alt="FOODFORFIT" className="h-10 w-full object-contain" />
        </div>
        <nav className="scroll-thin min-h-0 flex-1 space-y-0.5 overflow-y-auto">
          {items.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
              <item.icon size={16} strokeWidth={1.75} />
              <span className="flex-1">{item.label}</span>
              {item.badge && badges[item.badge as keyof Badges] > 0 && <span className="min-w-5 rounded-full bg-danger px-1.5 text-center text-[11px] font-semibold text-white">{badges[item.badge as keyof Badges]}</span>}
            </NavLink>
          ))}
        </nav>
        <button onClick={() => openGrok()} className="mt-3 mb-1 flex min-h-11 w-full shrink-0 items-center justify-center gap-2 rounded-control bg-lime px-3 text-sm font-semibold text-graphite shadow-card">
          <Sparkles size={16} strokeWidth={1.75} /> Ассистент
        </button>
      </aside>
      <div className="min-w-0 md:flex md:h-full md:min-h-0 md:flex-col">
        <header className="safe-top sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-paper/90 px-4 py-2.5 backdrop-blur md:gap-3">
          <div className="relative min-w-0 flex-1">
            <Search size={16} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
            <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Клиент, телефон, заказ, SKU" className="h-10 w-full rounded-control border border-line bg-card pr-3 pl-9 text-sm outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/25" />
            {hits && (
              <div className="absolute z-30 mt-2 max-h-96 w-full overflow-auto rounded-panel border border-line bg-card p-1.5 shadow-card">
                {Object.entries(hits).flatMap(([group, rows]) => rows.map((row) => (
                  <button key={group + row.id} className="block w-full rounded-control px-3 py-2 text-left hover:bg-soft" onClick={() => { setQuery(''); setHits(null); navigate(group === 'customers' ? `/customers/${row.id}` : group === 'leads' ? '/pipeline' : `/${group === 'products' ? 'inventory' : group}`) }}>
                    <div className="text-sm font-medium">{row.full_name || row.name || row.number || row.phone}</div>
                    <div className="text-xs text-muted">{group}</div>
                  </button>
                )))}
                {Object.values(hits).every((rows) => rows.length === 0) && <div className="px-3 py-4 text-sm text-muted">Ничего не найдено</div>}
              </div>
            )}
          </div>
          <button className={iconBtn} onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')} aria-label="Тема">
            {theme === 'dark' ? <Sun size={16} strokeWidth={1.75} /> : <Moon size={16} strokeWidth={1.75} />}
          </button>
          <button className={clsx(iconBtn, 'relative')} onClick={() => navigate('/notifications')} aria-label="Уведомления">
            <Bell size={16} strokeWidth={1.75} />
            {badges.notifications > 0 && <span className="absolute -top-1 -right-1 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-semibold text-white">{badges.notifications}</span>}
          </button>
          <div className="hidden min-w-0 text-right sm:block">
            <div className="truncate text-sm font-semibold">{user?.full_name}</div>
            <div className="truncate text-xs text-muted">{user?.role_name}</div>
          </div>
          <button className={iconBtn} onClick={() => logout().then(() => navigate('/login'))} aria-label="Выйти"><LogOut size={16} strokeWidth={1.75} /></button>
        </header>
        <main className="rise min-h-0 flex-1 overflow-auto px-4 py-6 pb-[calc(6.5rem+env(safe-area-inset-bottom))] md:px-8 md:pb-8"><Outlet /></main>
      </div>
      <nav className="safe-bottom fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t border-line bg-card md:hidden">
        {items.slice(0, 4).map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => clsx('grid min-h-14 place-items-center gap-1 py-2 text-[11px]', isActive ? 'font-semibold text-lime-deep' : 'text-muted')}>
            <item.icon size={18} strokeWidth={1.75} />{item.label.split(' ')[0]}
          </NavLink>
        ))}
        <button className="grid min-h-14 place-items-center gap-1 py-2 text-[11px] text-muted" onClick={() => setMore(true)}><Menu size={18} strokeWidth={1.75} />Ещё</button>
      </nav>
      {more && (
        <div className="fixed inset-0 z-40 bg-graphite/40 md:hidden" onClick={() => setMore(false)}>
          <div className="safe-bottom absolute inset-x-0 bottom-0 rounded-t-card border border-line bg-card p-3" onClick={(e) => e.stopPropagation()}>
            {items.slice(4).map((item) => (
              <NavLink key={item.to} to={item.to} onClick={() => setMore(false)} className="flex min-h-11 items-center gap-3 rounded-control px-3 text-sm">
                <item.icon size={16} strokeWidth={1.75} /> {item.label}
              </NavLink>
            ))}
            <button className="mt-2 flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-lime px-3 text-sm font-semibold text-graphite" onClick={() => { setMore(false); openGrok() }}><Sparkles size={16} strokeWidth={1.75} /> Ассистент</button>
          </div>
        </div>
      )}
      <button className="fixed right-4 bottom-[calc(5.75rem+env(safe-area-inset-bottom))] z-30 grid h-14 w-14 place-items-center rounded-full bg-lime text-graphite shadow-card md:bottom-6" onClick={() => openGrok()} aria-label="Ассистент">
        <Sparkles />
      </button>
      <GrokPanel />
    </div>
  )
}
