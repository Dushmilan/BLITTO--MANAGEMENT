import { useContext } from 'react'
import { ToastContext } from '../components/ToastProvider.jsx'

// Reusable toast hook: const toast = useToast(); toast.success('Saved')
// Outside a provider the calls are silent no-ops (see ToastProvider).
export function useToast() {
  return useContext(ToastContext)
}
