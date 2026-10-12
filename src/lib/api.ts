export class ApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

let csrf = ''

export function setCsrf(value: string) {
  csrf = value
}

export async function api<T>(path: string, init: { method?: string; body?: unknown; form?: FormData } = {}): Promise<T> {
  const headers: Record<string, string> = {}
  if (csrf) headers['X-CSRF-Token'] = csrf
  let body: BodyInit | undefined
  if (init.form) {
    body = init.form
  } else if (init.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(init.body)
  }
  const response = await fetch('/api' + path, {
    method: init.method ?? 'GET',
    credentials: 'include',
    headers,
    body,
  })
  if (response.status === 204) return undefined as T
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(response.status, data.message || 'Не удалось выполнить запрос')
  }
  if (data.csrf) setCsrf(data.csrf)
  return data as T
}
