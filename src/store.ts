import { create } from 'zustand'
import { api, setCsrf } from './lib/api'

export type User = {
  id: number
  email: string
  full_name: string
  role: string
  role_name: string
  employee_id: number | null
  permissions: string[]
}

type Session = {
  user: User | null
  ready: boolean
  theme: 'light' | 'dark'
  grokOpen: boolean
  grokDraft: string
  boot: () => Promise<void>
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  setTheme: (theme: 'light' | 'dark') => void
  openGrok: (draft?: string) => void
  closeGrok: () => void
  can: (permission: string) => boolean
}

const saved = localStorage.getItem('fff-theme') === 'dark' ? 'dark' : 'light'
document.documentElement.dataset.theme = saved

export const useApp = create<Session>((set, get) => ({
  user: null,
  ready: false,
  theme: saved,
  grokOpen: false,
  grokDraft: '',
  async boot() {
    try {
      const data = await api<{ user: User; csrf: string }>('/auth/me')
      setCsrf(data.csrf)
      set({ user: data.user, ready: true })
    } catch {
      set({ user: null, ready: true })
    }
  },
  async login(email, password) {
    const data = await api<{ user: User; csrf: string }>('/auth/login', { method: 'POST', body: { email, password } })
    setCsrf(data.csrf)
    set({ user: data.user })
  },
  async logout() {
    await api('/auth/logout', { method: 'POST' })
    set({ user: null })
  },
  setTheme(theme) {
    localStorage.setItem('fff-theme', theme)
    document.documentElement.dataset.theme = theme
    set({ theme })
  },
  openGrok(draft = '') {
    set({ grokOpen: true, grokDraft: draft })
  },
  closeGrok() {
    set({ grokOpen: false, grokDraft: '' })
  },
  can(permission) {
    const perms = get().user?.permissions ?? []
    return perms.includes(permission) || perms.includes('*')
  },
}))
