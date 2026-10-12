import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api } from '../lib/api'
import { useApp } from '../store'
import { Button } from './ui'

type Link = { type: string; id: number; label: string }
type Message = { id: number; role: string; content: string; sources_text?: string; links?: Link[] }

const prompts = ['Выручка за месяц', 'Клиенты на продление', 'Продажи менеджеров', 'Что требует внимания?']

export function GrokPanel() {
  const { grokOpen, closeGrok, grokDraft } = useApp()
  const location = useLocation()
  const navigate = useNavigate()
  const [text, setText] = useState('')
  const [conversationId, setConversationId] = useState<number | null>(null)
  const [messages, setMessages] = useState<Message[]>([])
  const [list, setList] = useState<Array<{ id: number; title: string }>>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (grokDraft) setText(grokDraft)
  }, [grokDraft])

  useEffect(() => {
    if (!grokOpen) return
    api<{ data: Array<{ id: number; title: string }> }>('/ai/conversations').then((r) => setList(r.data)).catch(() => undefined)
  }, [grokOpen])

  async function send(message = text) {
    const value = message.trim()
    if (!value) return
    setBusy(true)
    setError('')
    setText('')
    setMessages((prev) => [...prev, { id: Date.now(), role: 'user', content: value }])
    const customer = location.pathname.match(/^\/customers\/(\d+)/)
    try {
      const data = await api<{ conversation_id: number; message: string; sources?: string; links: Link[] }>('/ai/chat', {
        method: 'POST',
        body: { message: value, conversation_id: conversationId, context: { path: location.pathname, customer_id: customer ? Number(customer[1]) : null } },
      })
      setConversationId(data.conversation_id)
      setMessages((prev) => [...prev, { id: Date.now() + 1, role: 'assistant', content: data.message, sources_text: data.sources, links: data.links }])
    } catch (err) {
      setError(err instanceof Error ? err.message : 'AI Agent временно недоступен.')
    } finally {
      setBusy(false)
    }
  }

  if (!grokOpen) return null
  return (
    <div className="modal-backdrop fixed inset-0 z-50 flex justify-end bg-graphite/30" onMouseDown={closeGrok}>
      <aside className="modal-panel flex h-full w-full max-w-xl flex-col border-l border-line bg-card shadow-card" onMouseDown={(e) => e.stopPropagation()}>
        <header className="safe-top flex items-center justify-between border-b border-line px-5 py-4">
          <div>
            <div className="font-semibold">Ассистент</div>
            <div className="text-xs text-ai">Online · только чтение</div>
          </div>
          <div className="flex gap-2">
            <Button tone="ghost" onClick={() => { setConversationId(null); setMessages([]) }}>Новый</Button>
            <Button tone="ghost" onClick={closeGrok}>Закрыть</Button>
          </div>
        </header>
        <div className="flex gap-2 overflow-auto border-b border-line px-4 py-2">
          {prompts.map((prompt) => <button key={prompt} className="shrink-0 rounded-full bg-ai-soft px-3 py-1.5 text-xs text-ai" onClick={() => send(prompt)}>{prompt}</button>)}
        </div>
        <div className="flex-1 space-y-4 overflow-auto px-5 py-4">
          {list.length > 0 && messages.length === 0 && (
            <div className="space-y-1">
              {list.map((item) => (
                <button key={item.id} className="block w-full rounded-xl px-3 py-2 text-left text-sm hover:bg-soft" onClick={async () => {
                  const data = await api<{ messages: Message[] }>('/ai/conversations/' + item.id)
                  setConversationId(item.id)
                  setMessages(data.messages)
                }}>{item.title}</button>
              ))}
            </div>
          )}
          {messages.map((message) => (
            <article key={message.id} className={message.role === 'user' ? 'ml-8 rounded-panel bg-soft px-4 py-3' : 'rounded-panel border border-line px-4 py-3'}>
              <div className="text-sm whitespace-pre-wrap">{message.content}</div>
              {message.links && message.links.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {message.links.map((link) => (
                    <button key={link.type + link.id} className="rounded-full border border-line px-3 py-1 text-xs font-semibold" onClick={() => { closeGrok(); navigate('/customers/' + link.id) }}>{link.label}</button>
                  ))}
                </div>
              )}
              {message.sources_text && <div className="mt-3 text-xs text-muted">Использованные данные: {message.sources_text}</div>}
            </article>
          ))}
          {error && <div className="rounded-2xl bg-danger/10 px-4 py-3 text-sm text-danger">{error}</div>}
        </div>
        <form className="safe-bottom border-t border-line p-4" onSubmit={(e) => { e.preventDefault(); send() }}>
          <div className="flex gap-2">
            <input value={text} onChange={(e) => setText(e.target.value)} placeholder="Спросите о продажах, клиентах, выручке, складе..." className="min-h-11 flex-1 rounded-control border border-line bg-paper px-3 outline-none transition focus:border-lime focus:ring-2 focus:ring-lime/25" />
            <Button type="submit" disabled={busy}>{busy ? '...' : 'Спросить'}</Button>
          </div>
          <div className="mt-2 text-xs text-muted">/sales /revenue /customers /renewals /deliveries /inventory /tasks /managers /analytics</div>
        </form>
      </aside>
    </div>
  )
}
