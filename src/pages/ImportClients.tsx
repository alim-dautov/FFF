import { FormEvent, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Button, Card, Field, PageHeader, Select, Table } from '../components/ui'
import { api } from '../lib/api'
import { useApp } from '../store'

type UploadResult = {
  token: string
  filename: string
  headers: string[]
  mapping: Record<string, string | null>
  row_count: number
  fields: string[]
}

type PreviewResult = {
  mapping: Record<string, string | null>
  preview: Array<Record<string, unknown>>
  counts: {
    will_create_customers: number
    will_create_subscriptions: number
    will_update: number
    will_skip: number
  }
  errors: Array<{ row: number; message: string }>
  duplicate_groups: Array<{ name_key: string; rows: number[]; full_name: string; mode: string }>
  row_count: number
  can_import: boolean
}

type CommitResult = {
  batch_id: number
  counts: {
    created_customers: number
    created_subscriptions: number
    updated_rows: number
    skipped_rows: number
    error_rows: number
  }
  errors: Array<{ row: number; message: string }>
  error_csv: string
}

const fieldLabels: Record<string, string> = {
  full_name: 'ФИО',
  line: 'Сегмент линейки',
  section: 'Раздел в таблице',
  tariff_days: 'Дней доставки в неделю',
  pack_days: 'Пакет (дни)',
  kcal: 'Калории (ккал)',
  ration_no: 'Рацион №',
  diet: 'Диета/особенности',
  delivery_time: 'Время доставки',
  period_start: 'Последний период: с',
  period_end: 'Последний период: по',
  end_date: 'Дата окончания',
  pauses: 'Пауза',
  flag_mark: 'Метка',
  notes: 'Примечание',
  source_text: 'Исходная строка Excel',
  source_row: 'Строка в источнике',
  status: 'Статус клиента',
  tariff_days_ref: 'Дней в неделю (справочно)',
  needs_review: 'Требует проверки',
  review_reasons: 'Причины проверки',
}

export function ImportClientsPage() {
  const canImport = useApp((s) => s.can('customers.import'))
  const [upload, setUpload] = useState<UploadResult | null>(null)
  const [mapping, setMapping] = useState<Record<string, string | null>>({})
  const [duplicateMode, setDuplicateMode] = useState<Record<string, string>>({})
  const [existingMode, setExistingMode] = useState('auto')
  const [preview, setPreview] = useState<PreviewResult | null>(null)
  const [result, setResult] = useState<CommitResult | null>(null)
  const [busy, setBusy] = useState(false)

  if (!canImport) return <Navigate to="/customers" replace />

  async function onUpload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setBusy(true)
    setPreview(null)
    setResult(null)
    try {
      const data = await api<UploadResult>('/imports/clients/upload', { method: 'POST', form })
      setUpload(data)
      setMapping(data.mapping)
      toast.success(`Файл загружен: ${data.row_count} строк`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка загрузки')
    } finally {
      setBusy(false)
    }
  }

  async function onPreview() {
    if (!upload) return
    setBusy(true)
    try {
      const data = await api<PreviewResult>('/imports/clients/preview', {
        method: 'POST',
        body: { token: upload.token, mapping, duplicate_mode: duplicateMode, existing_mode: existingMode, filename: upload.filename },
      })
      setPreview(data)
      const modes: Record<string, string> = { ...duplicateMode }
      for (const g of data.duplicate_groups) {
        if (!modes[g.name_key]) modes[g.name_key] = g.mode || 'merge'
      }
      setDuplicateMode(modes)
      toast.success(data.can_import ? 'Предпросмотр готов' : 'Есть ошибки валидации')
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка предпросмотра')
    } finally {
      setBusy(false)
    }
  }

  async function onCommit() {
    if (!upload || !preview?.can_import) return
    setBusy(true)
    try {
      const data = await api<CommitResult>('/imports/clients/commit', {
        method: 'POST',
        body: { token: upload.token, mapping, duplicate_mode: duplicateMode, existing_mode: existingMode, filename: upload.filename },
      })
      setResult(data)
      toast.success(`Импорт завершён: ${data.counts.created_subscriptions} подписок`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка импорта')
    } finally {
      setBusy(false)
    }
  }

  async function onRollback() {
    if (!result?.batch_id) return
    setBusy(true)
    try {
      await api('/imports/clients/' + result.batch_id + '/rollback', { method: 'POST', body: {} })
      toast.success('Импорт откатан')
      setResult(null)
      setPreview(null)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ошибка отката')
    } finally {
      setBusy(false)
    }
  }

  function downloadErrors() {
    if (!result?.error_csv) return
    const blob = new Blob([result.error_csv], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `import-errors-${result.batch_id}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  const fields = useMemo(() => upload?.fields ?? Object.keys(fieldLabels), [upload])

  return (
    <div className="space-y-4">
      <PageHeader title="Импорт клиентов" text="CSV UTF-8 с BOM или XLSX. Лимит 5 МБ. Только для админа.">
        <Link to="/customers?view=nutrition"><Button tone="secondary">К списку</Button></Link>
      </PageHeader>

      <Card>
        <h2 className="font-semibold">1. Загрузка файла</h2>
        <form className="mt-3 flex flex-wrap items-end gap-3" onSubmit={onUpload}>
          <Field label="Файл CSV / XLSX">
            <input name="file" type="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required className="block w-full text-sm" />
          </Field>
          <Button type="submit" disabled={busy}>Загрузить</Button>
        </form>
        {upload && <p className="mt-3 text-sm text-muted">{upload.filename} · {upload.row_count} строк</p>}
      </Card>

      {upload && (
        <Card>
          <h2 className="font-semibold">2. Сопоставление колонок</h2>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            {fields.map((field) => (
              <Field key={field} label={fieldLabels[field] || field}>
                <Select value={mapping[field] ?? ''} onChange={(e) => setMapping({ ...mapping, [field]: e.target.value || null })}>
                  <option value="">не импортировать</option>
                  {upload.headers.map((h) => <option key={h} value={h}>{h}</option>)}
                </Select>
              </Field>
            ))}
          </div>
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            <Field label="Если клиент уже есть в CRM">
              <Select value={existingMode} onChange={(e) => setExistingMode(e.target.value)}>
                <option value="auto">Авто: новая подписка или пропуск</option>
                <option value="skip">Пропустить</option>
                <option value="update_empty">Обновить пустые поля</option>
                <option value="create_subscription">Создать новую подписку</option>
              </Select>
            </Field>
          </div>
          <Button className="mt-4" onClick={onPreview} disabled={busy}>Предпросмотр</Button>
        </Card>
      )}

      {preview && (
        <Card>
          <h2 className="font-semibold">3. Предпросмотр</h2>
          <div className="mt-3 flex flex-wrap gap-3 text-sm">
            <span>Клиентов: <b>{preview.counts.will_create_customers}</b></span>
            <span>Подписок: <b>{preview.counts.will_create_subscriptions}</b></span>
            <span>Обновлений: <b>{preview.counts.will_update}</b></span>
            <span>Пропусков: <b>{preview.counts.will_skip}</b></span>
          </div>
          {preview.duplicate_groups.length > 0 && (
            <div className="mt-4 space-y-2">
              <h3 className="text-sm font-semibold">Дубли ФИО в файле</h3>
              {preview.duplicate_groups.map((g) => (
                <div key={g.name_key} className="rounded-panel border border-line bg-paper p-3 text-sm">
                  <div className="font-medium">{g.full_name}</div>
                  <div className="text-muted">Строки: {g.rows.join(', ')}</div>
                  <Select className="mt-2 max-w-md" value={duplicateMode[g.name_key] || 'merge'} onChange={(e) => setDuplicateMode({ ...duplicateMode, [g.name_key]: e.target.value })}>
                    <option value="merge">Один клиент с несколькими подписками</option>
                    <option value="split">Разные клиенты</option>
                  </Select>
                </div>
              ))}
              <Button tone="secondary" onClick={onPreview} disabled={busy}>Пересчитать с учётом дублей</Button>
            </div>
          )}
          {preview.errors.length > 0 && (
            <div className="mt-4 rounded-panel border border-danger/30 bg-danger/5 p-3 text-sm">
              <div className="font-semibold text-danger">Ошибки валидации ({preview.errors.length})</div>
              <ul className="mt-2 max-h-40 space-y-1 overflow-auto">
                {preview.errors.slice(0, 40).map((e, i) => <li key={i}>Строка {e.row}: {e.message}</li>)}
              </ul>
            </div>
          )}
          <div className="mt-4 overflow-auto">
            <Table>
              <thead className="text-muted">
                <tr>{['#', 'ФИО', 'Линейка', 'Тариф', 'Статус', 'Действие'].map((h) => <th key={h} className="px-3 py-2 font-medium">{h}</th>)}</tr>
              </thead>
              <tbody>
                {preview.preview.map((row) => (
                  <tr key={String(row.row)} className="border-t border-line">
                    <td className="px-3 py-2">{String(row.row)}</td>
                    <td className="px-3 py-2">{String(row.full_name || '')}</td>
                    <td className="px-3 py-2">{String(row.line || '')}</td>
                    <td className="px-3 py-2">{String(row.tariff_days ?? '—')}</td>
                    <td className="px-3 py-2">{String(row.status || '')}</td>
                    <td className="px-3 py-2 text-xs text-muted">{String(row.action || '')}</td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
          <Button className="mt-4" disabled={busy || !preview.can_import} onClick={onCommit}>Импортировать</Button>
        </Card>
      )}

      {result && (
        <Card>
          <h2 className="font-semibold">4. Отчёт</h2>
          <div className="mt-3 flex flex-wrap gap-3 text-sm">
            <span>Создано клиентов: <b>{result.counts.created_customers}</b></span>
            <span>Подписок: <b>{result.counts.created_subscriptions}</b></span>
            <span>Обновлено: <b>{result.counts.updated_rows}</b></span>
            <span>Пропущено: <b>{result.counts.skipped_rows}</b></span>
            <span>Ошибок: <b>{result.counts.error_rows}</b></span>
            <span>Партия: <b>#{result.batch_id}</b></span>
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            {result.errors.length > 0 && <Button tone="secondary" onClick={downloadErrors}>Скачать CSV ошибок</Button>}
            <Button tone="danger" disabled={busy} onClick={onRollback}>Откатить весь импорт</Button>
            <Link to="/customers?view=nutrition"><Button>Открыть список</Button></Link>
          </div>
        </Card>
      )}
    </div>
  )
}
