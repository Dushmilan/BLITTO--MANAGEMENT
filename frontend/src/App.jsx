import { useState, useEffect, useCallback } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { api, clearToken, getToken, setToken } from './api.js'
import AppLayout from './components/layout/AppLayout.jsx'
import LoginPage from './pages/LoginPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import ApplicationsPage from './pages/ApplicationsPage.jsx'
import DocketPage from './pages/DocketPage.jsx'
import DocumentsPage from './pages/DocumentsPage.jsx'
import AuditPage from './pages/AuditPage.jsx'

export default function App() {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

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
    // Return true so LoginPage knows to navigate
    return true
  }, [])

  const handleLogout = useCallback(() => {
    clearToken()
    setUser(null)
  }, [])

  if (loading) {
    return (
      <div className="min-h-screen bg-ivory flex items-center justify-center">
        <div className="flex flex-col items-center gap-md">
          <svg className="animate-spin" width="32" height="32" viewBox="0 0 32 32" fill="none">
            <circle cx="16" cy="16" r="12" stroke="currentColor" strokeWidth="2" className="text-hairline" />
            <path d="M16 4a12 12 0 018.49 3.51" stroke="currentColor" strokeWidth="2" strokeLinecap="round" className="text-copper" />
          </svg>
          <span className="text-body-sm text-steel font-sans">Loading BLITTO...</span>
        </div>
      </div>
    )
  }

  return (
    <Routes>
      {/* Public routes */}
      <Route
        path="/login"
        element={
          user ? <Navigate to="/" replace /> : <LoginPage onLogin={handleLogin} />
        }
      />

      {/* Protected routes */}
      <Route
        element={
          user ? (
            <AppLayout user={user} onLogout={handleLogout} />
          ) : (
            <Navigate to="/login" replace />
          )
        }
      >
        <Route path="/" element={<DashboardPage />} />
        <Route path="/applications" element={<ApplicationsPage user={user} />} />
        <Route path="/docket" element={<DocketPage />} />
        <Route path="/documents" element={<DocumentsPage user={user} />} />
        <Route path="/audit" element={<AuditPage />} />
      </Route>

      {/* Catch-all redirect */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
