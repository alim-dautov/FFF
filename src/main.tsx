import { StrictMode, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { Toaster } from 'sonner'
import { App } from './App'
import { useApp } from './store'
import './index.css'

function Root() {
  const boot = useApp((s) => s.boot)
  const theme = useApp((s) => s.theme)
  useEffect(() => { boot() }, [boot])
  return (
    <BrowserRouter>
      <App />
      <Toaster position="top-right" theme={theme === 'dark' ? 'dark' : 'light'} />
    </BrowserRouter>
  )
}

createRoot(document.getElementById('root')!).render(<StrictMode><Root /></StrictMode>)

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined)
  })
}
