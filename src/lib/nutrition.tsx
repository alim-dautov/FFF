import type { CSSProperties, ReactNode } from 'react'

export type NutritionStatus = 'active' | 'ended' | 'paused'

export function tariffBadgeStyle(row: {
  status?: string | null
  tariff_days?: number | null
  expired_active?: boolean
}): { label: string; style: CSSProperties; className?: string } {
  if (row.status === 'ended') {
    return { label: 'Закончилась', style: { background: '#E23B3B', color: '#fff' } }
  }
  if (row.status === 'paused') {
    return { label: 'Пауза', style: { background: '#D98989', color: '#3b1f1f' } }
  }
  if (row.expired_active) {
    return { label: 'срок истёк', style: { background: '#E23B3B', color: '#fff' } }
  }
  const days = row.tariff_days
  if (days === 7) return { label: '7 дней', style: { background: '#E6DE55', color: '#2a2a10' } }
  if (days === 6) return { label: '6 дней', style: { background: '#7FBE45', color: '#1a2a10' } }
  if (days === 5) return { label: '5 дней', style: { background: '#8BB8EA', color: '#142436' } }
  if (days === 4) return { label: '4 дня', style: { background: '#E4B8B8', color: '#3b1f1f' } }
  return { label: '', style: { background: '#D8DCD4', color: '#667061' }, className: 'min-w-6' }
}

export function statusLabel(status?: string | null) {
  if (status === 'active') return 'Активный'
  if (status === 'ended') return 'Подписка закончилась'
  if (status === 'paused') return 'Пауза'
  return status || '—'
}

export function TariffBadge({ row }: { row: { status?: string | null; tariff_days?: number | null; expired_active?: boolean } }): ReactNode {
  const badge = tariffBadgeStyle(row)
  if (!badge.label && row.tariff_days == null) {
    return (
      <span
        className="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-2 text-[11px] font-semibold"
        style={badge.style}
        title="Тариф не указан"
      />
    )
  }
  return (
    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold" style={badge.style}>
      {badge.label}
    </span>
  )
}
