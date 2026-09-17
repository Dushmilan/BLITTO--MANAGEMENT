import { useState, useEffect, useCallback } from 'react'
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { api, clearToken, getToken, setToken } from './api.js'
import UnifiedLayout from './components/layout/UnifiedLayout.jsx'
import ProtectedRoute from './components/ProtectedRoute.jsx'
import ToastProvider from './components/ToastProvider.jsx'
import KeyboardShortcutsModal from './components/ui/KeyboardShortcutsModal.jsx'
import { ThemeProvider } from './hooks/useTheme.jsx'
import { useKeyboardShortcut } from './hooks/useKeyboardShortcut.js'
import LoginPage from './pages/LoginPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import PatentsPage from './pages/PatentsPage.jsx'
import UserPanel from './pages/UserPanel.jsx'
import AdminDashboard from './pages/admin/AdminDashboard.jsx'

import UsersPage from './pages/admin/UsersPage.jsx'

const STAFF_ROLES = ['admin', 'attorney', 'paralegal']

export default function App() {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()

  useEffect(() => {
    async function loadUser() {
      if (!getToken()) {
        setLoading(false)
        return
      }
      try {
        const me = await api.me()
        setUser(me)
      } catch {
        clearToken()
      } finally {
        setLoading(false)
      }
    }
    loadUser()
  }, [])

  const handleLogin = useCallback(async (email, password) => {
    const token = await api.login(email, password)
    setToken(token.access_token)
    const me = await api.me()
    setUser(me)
    return true
  }, [])

  const handleLogout = useCallback(() => {
    clearToken()
    setUser(null)
  }, [])

  // A 401 anywhere (expired/invalid session) drops the user at /login —
  // announced by api.js, never a silent dead session (issue #41).
  useEffect(() => {
    function onUnauthorized() {
      clearToken()
      setUser(null)
      navigate('/login')
    }
    window.addEventListener('blitto:unauthorized', onUnauthorized)
    return () => window.removeEventListener('blitto:unauthorized', onUnauthorized)
  }, [navigate])

  // Power-user shortcut: Ctrl/Cmd+K focuses the first search box on screen.
  // Escape closes modals/menus in their own components (Modal, Dropdown).
  const focusSearch = useCallback((e) => {
    const input = document.querySelector('input[data-search-input]')
    if (input) {
      e.preventDefault()
      input.focus()
    }
  }, [])
  useKeyboardShortcut('ctrl+k', focusSearch)
  useKeyboardShortcut('meta+k', focusSearch)
  // '?' opens the shortcut reference (issue #45).
  const [shortcutsOpen, setShortcutsOpen] = useState(false)
  useKeyboardShortcut('?', () => setShortcutsOpen(true))

  if (loading) {
    return (
      <div className="min-h-screen bg-ivory flex items-center justify-center">
        <div className="flex flex-col items-center gap-md" role="status" aria-live="polite" aria-label="Loading BLITTO">
          <svg aria-hidden="true" className="animate-spin" width="32" height="32" viewBox="0 0 32 32" fill="none">
            <circle cx="16" cy="16" r="12" stroke="currentColor" strokeWidth="2" className="text-hairline" />
            <path d="M16 4a12 12 0 018.49 3.51" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className="text-copper" />
          </svg>
          <span className="text-body-sm text-steel font-sans">Loading BLITTO...</span>
        </div>
      </div>
    )
  }

  const isStaff = user && STAFF_ROLES.includes(user.role)

  return (
    <ThemeProvider>
    <ToastProvider>
    <KeyboardShortcutsModal open={shortcutsOpen} onClose={() => setShortcutsOpen(false)} />
    <Routes>
      {/* Public routes */}
      <Route
        path="/login"
        element={
          user ? (
            <Navigate to={isStaff ? '/admin' : '/user'} replace />
          ) : (
            <LoginPage onLogin={handleLogin} />
          )
        }
      />

      {/* Staff routes — one shared shell, role-guarded */}
      <Route
        path="/admin"
        element={
          <ProtectedRoute user={user} allowedRoles={STAFF_ROLES}>
            <UnifiedLayout user={user} onLogout={handleLogout} />
          </ProtectedRoute>
        }
      >
        <Route index element={<AdminDashboard />} />
        <Route path="patents" element={<PatentsPage user={user} />} />
        <Route path="users" element={<UsersPage />} />
      </Route>

      {/* Inventor routes — same shell, inventor slices */}
      <Route
        path="/user"
        element={
          <ProtectedRoute user={user} allowedRoles={['inventor']}>
            <UnifiedLayout user={user} onLogout={handleLogout} />
          </ProtectedRoute>
        }
      >
        <Route index element={<UserPanel user={user} section="overview" />} />
        <Route path="patents" element={<UserPanel user={user} section="patents" />} />
        <Route path="notifications" element={<UserPanel user={user} section="notifications" />} />
      </Route>

      {/* Unknown paths redirect to the role home (or login) */}
      <Route
        path="*"
        element={
          <Navigate to={user ? (isStaff ? '/admin' : '/user') : '/login'} replace />
        }
      />
    </Routes>
    </ToastProvider>
    </ThemeProvider>
  )
}
