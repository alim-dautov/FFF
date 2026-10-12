import { useEffect, useState } from 'react'
import { api } from './api'

export function useApi<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(Boolean(path))
  const [tick, setTick] = useState(0)

  useEffect(() => {
    if (!path) return
    let live = true
    setLoading(true)
    api<T>(path)
      .then((result) => { if (live) { setData(result); setError('') } })
      .catch((err: Error) => { if (live) setError(err.message) })
      .finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [path, tick])

  return { data, error, loading, reload: () => setTick((n) => n + 1), setData }
}
