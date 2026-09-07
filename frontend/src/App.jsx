import { useState, useEffect, useCallback } from 'react'
import { Routes, Route, Navigate } from 'react-router-dom'
import { api, clearToken, getToken, setToken } from './api.js'
import AppLayout from './components/layout/AppLayout.jsx'
import AdminLayout from './components/layout/AdminLayout.jsx'
import LoginPage from './pages/LoginPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import PatentsPage from './pages/PatentsPage.jsx'
import UserPanel from './pages/UserPanel.jsx'
import AdminDashboard from './pages/admin/AdminDashboard.jsx'

import UsersPage from './pages/admin/UsersPage.jsx'

const ADMIN_ROLES = ['admin', 'attorney', 'paralegal']

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

  const isAdmin = user && ADMIN_ROLES.includes(user.role)

  return (
    <Routes>
      {/* Public routes */}
      <Route
        path="/login"
        element={
          user ? (
            <Navigate to={isAdmin ? '/admin' : '/user'} replace />
          ) : (
            <LoginPage onLogin={handleLogin} />
          )
        }
      />

      {/* Admin routes */}
      <Route
        path="/admin"
        element={
          user && isAdmin ? (
            <AdminLayout user={user} onLogout={handleLogout} />
          ) : (
            <Navigate to={user ? '/user' : '/login'} replace />
          )
        }
      >
        <Route index element={<AdminDashboard />} />
        <Route path="patents" element={<PatentsPage user={user} />} />
        <Route path="users" element={<UsersPage />} />
      </Route>

      {/* User routes (inventor) */}
      <Route
        path="/user"
        element={
          user && !isAdmin ? (
            <UserPanel user={user} onLogout={handleLogout} />
          ) : (
            <Navigate to={user ? '/admin' : '/login'} replace />
          )
        }
      />

      {/* Catch-all redirect */}
      <Route
        path="*"
        element={
          <Navigate to={user ? (isAdmin ? '/admin' : '/user') : '/login'} replace />
        }
      />
    </Routes>
  )
}
