import { createContext, useCallback, useMemo, useRef, useState } from 'react'
import Toast from './ui/Toast.jsx'

// Default context is a silent no-op so components (and their unit tests)
// keep working when rendered outside a provider. The real app wraps
// everything in <ToastProvider> in App.jsx.
const noop = () => {}
export const ToastContext = createContext({
  success: noop,
  error: noop,
  info: noop,
})

let nextId = 1
const AUTO_DISMISS_MS = 5000

export default function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const timers = useRef(new Map())

  const dismiss = useCallback((id) => {
    const timer = timers.current.get(id)
    if (timer) {
      clearTimeout(timer)
      timers.current.delete(id)
    }
    setToasts((prev) => prev.filter((t) => t.id !== id))
  }, [])

  const push = useCallback((kind, message) => {
    const id = nextId++
    setToasts((prev) => [...prev, { id, kind, message }])
    timers.current.set(id, setTimeout(() => dismiss(id), AUTO_DISMISS_MS))
  }, [dismiss])

  const value = useMemo(() => ({
    success: (message) => push('success', message),
    error: (message) => push('error', message),
    info: (message) => push('info', message),
  }), [push])

  return (
    <ToastContext.Provider value={value}>
      {children}
      <Toast toasts={toasts} onDismiss={dismiss} />
    </ToastContext.Provider>
  )
}
