import { useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import Sidebar from '../ui/Sidebar.jsx'
import Avatar from '../ui/Avatar.jsx'
import Button from '../ui/Button.jsx'

export default function AppLayout({ user, onLogout }) {
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const navigate = useNavigate()

  function handleLogout() {
    onLogout()
    navigate('/login')
  }

  const roleColors = {
    admin: 'text-copper',
    attorney: 'text-status-filed',
    paralegal: 'text-status-examination',
    inventor: 'text-status-granted',
  }

  return (
    <div className="min-h-screen bg-ivory grain-overlay">
      <Sidebar
        collapsed={sidebarCollapsed}
        onToggle={() => setSidebarCollapsed(!sidebarCollapsed)}
      />

      {/* Main content area */}
      <div className={`transition-all duration-300 ${sidebarCollapsed ? 'ml-[64px]' : 'ml-[240px]'}`}>
        {/* Top header */}
        <header className="sticky top-0 z-30 bg-ivory/80 backdrop-blur-md border-b border-hairline-soft">
          <div className="px-xl py-md flex items-center justify-between">
            <div className="flex items-center gap-sm">
              <span className="text-micro text-muted uppercase tracking-widest font-sans hidden sm:inline">
                Patent Management System
              </span>
            </div>
            <div className="flex items-center gap-lg">
              <div className="flex items-center gap-sm text-right">
                <Avatar name={user?.email} size="sm" />
                <div className="hidden sm:block">
                  <p className="text-body-sm-medium text-ink font-sans leading-tight">{user?.email}</p>
                  <p className={`text-micro capitalize font-sans ${roleColors[user?.role] || 'text-slate'}`}>
                    {user?.role}
                  </p>
                </div>
              </div>
              <Button variant="ghost" size="sm" onClick={handleLogout}>
                Sign out
              </Button>
            </div>
          </div>
        </header>

        {/* Page content */}
        <main className="px-xl py-section-sm">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
