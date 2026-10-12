export const leadStages = [
  ['new', 'Новый лид'],
  ['contacted', 'Связались'],
  ['qualified', 'Квалификация'],
  ['program_selected', 'Подобрали программу'],
  ['proposal_sent', 'Отправили предложение'],
  ['awaiting_payment', 'Ожидаем оплату'],
  ['paid', 'Оплачено'],
  ['active_client', 'Активный клиент'],
  ['rejected', 'Отказ'],
  ['no_answer', 'Не дозвонились'],
] as const

export const customerFilters = [
  ['all', 'Все'],
  ['active', 'Активные'],
  ['new', 'Новые'],
  ['renewal', 'На продление'],
  ['ending_3', 'Через 3 дня'],
  ['ending_tomorrow', 'Завтра'],
  ['ending_today', 'Сегодня'],
  ['overdue', 'Просроченные'],
  ['paused', 'Приостановленные'],
  ['lost', 'Потерянные'],
  ['vip', 'VIP'],
] as const

export const labels: Record<string, string> = {
  active: 'Активна',
  ending: 'Заканчивается',
  ended: 'Закончилась',
  awaiting_renewal: 'Ожидает продления',
  renewed: 'Продлена',
  paused: 'Приостановлена',
  cancelled: 'Отменена',
  new: 'Новый',
  renewal_required: 'Требуется продление',
  lost: 'Потерян',
  pending: 'Ожидает',
  paid: 'Оплачен',
  failed: 'Не прошёл',
  refunded: 'Возврат',
  preparing: 'Подготовка',
  ready: 'Готово',
  handed: 'Передано курьеру',
  on_the_way: 'В пути',
  delivered: 'Доставлено',
  problem: 'Проблема',
  open: 'Открыта',
  done: 'Завершена',
  morning: 'Утро',
  day: 'День',
  evening: 'Вечер',
  custom: 'Свой интервал',
  info: 'Информация',
  important: 'Важно',
  critical: 'Критично',
  high: 'Высокий',
  normal: 'Обычный',
  low: 'Низкий',
  in: 'Приход',
  out: 'Расход',
  writeoff: 'Списание',
  transfer: 'Перемещение',
  adjust: 'Корректировка',
  draft: 'Черновик',
  queued: 'В очереди',
  sent: 'Отправлено',
  received: 'Получено',
}

export function label(code?: string | null) {
  if (!code) return '—'
  return labels[code] || leadStages.find((s) => s[0] === code)?.[1] || code
}

export function tone(code?: string | null) {
  if (!code) return 'neutral'
  if (['critical', 'problem', 'failed', 'lost', 'overdue', 'renewal_required'].includes(code)) return 'danger'
  if (['ending', 'pending', 'important', 'high', 'paused', 'awaiting_renewal'].includes(code)) return 'orange'
  if (['paid', 'active', 'delivered', 'done', 'sent'].includes(code)) return 'lime'
  return 'neutral'
}
