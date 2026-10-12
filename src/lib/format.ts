import { format, parseISO } from 'date-fns'
import { ru } from 'date-fns/locale'

export function money(value?: number | null) {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return new Intl.NumberFormat('ru-KZ').format(value) + ' ₸'
}

export function fmtDate(value?: string | null) {
  if (!value) return '—'
  const raw = value.length > 10 ? value.slice(0, 10) : value
  return format(parseISO(raw), 'dd.MM.yyyy', { locale: ru })
}

export function daysLabel(days?: number | null) {
  if (days === null || days === undefined) return '—'
  if (days < 0) return 'Требуется продление'
  if (days === 0) return 'Сегодня'
  if (days === 1) return '1 день'
  return `${days} дн.`
}

export function clsx(...parts: Array<string | false | null | undefined>) {
  return parts.filter(Boolean).join(' ')
}
