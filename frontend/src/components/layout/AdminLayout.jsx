import { useState } from 'react'
import { Outlet, useNavigate, NavLink } from 'react-router-dom'
import Avatar from '../ui/Avatar.jsx'
import Button from '../ui/Button.jsx'

const ADMIN_NAV_ITEMS = [
  { to: '/admin', label: 'Overview', icon: DashboardIcon, end: true },
  { to: '/admin/patents', label: 'Patents', icon: PatentsIcon },
  { to: '/admin/users', label: 'Users', icon: UsersIcon },
]

export default function AdminLayout({ user, onLogout }) {
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
  }

  return (
    <div className="min-h-screen bg-ivory grain-overlay">
      {/* Sidebar */}
      <aside className={`fixed left-0 top-0 h-screen bg-navy text-white z-40 transition-all duration-300 flex flex-col ${sidebarCollapsed ? 'w-[64px]' : 'w-[240px]'}`}>
        <div className="px-lg py-xl border-b border-white/10 flex items-center justify-between">
          {!sidebarCollapsed && (
            <div className="flex flex-col">
              <span className="font-display text-heading-4 text-white tracking-tight">BLITTO</span>
              <span className="text-micro text-white/50 font-sans tracking-wider uppercase">Admin Panel</span>
            </div>
          )}
          <button
            onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
            className="text-white/50 hover:text-white transition-colors p-xs rounded hover:bg-white/10"
            aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
              {sidebarCollapsed ? (
                <path d="M6 4l4 4-4 4" />
              ) : (
                <path d="M10 4l-4 4 4 4" />
              )}
            </svg>
          </button>
        </div>

        <nav className="flex-1 py-lg overflow-y-auto">
          <ul className="space-y-xxs">
            {ADMIN_NAV_ITEMS.map(({ to, label, icon: Icon, end }) => (
              <li key={to}>
                <NavLink
                  to={to}
                  end={end}
                  className={({ isActive }) =>
                    `flex items-center gap-sm px-lg py-sm mx-xs rounded-md text-body-sm-medium font-sans transition-all duration-150 ${
                      isActive
                        ? 'bg-copper text-white'
                        : 'text-white/60 hover:bg-white/8 hover:text-white'
                    } ${sidebarCollapsed ? 'justify-center' : ''}`
                  }
                >
                  <Icon />
                  {!sidebarCollapsed && <span>{label}</span>}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        {!sidebarCollapsed && (
          <div className="px-lg py-lg border-t border-white/10">
            <p className="text-micro text-white/30 font-sans">University of Peradeniya</p>
          </div>
        )}
      </aside>

      {/* Main content area */}
      <div className={`transition-all duration-300 ${sidebarCollapsed ? 'ml-[64px]' : 'ml-[240px]'}`}>
        <header className="sticky top-0 z-30 bg-ivory/80 backdrop-blur-md border-b border-hairline-soft">
          <div className="px-xl py-md flex items-center justify-between">
            <div className="flex items-center gap-sm">
              <span className="text-micro text-muted uppercase tracking-widest font-sans hidden sm:inline">
                Admin Panel
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

        <main className="px-xl py-section-sm">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function DashboardIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <rect x="2" y="2" width="5.5" height="5.5" rx="1" />
      <rect x="10.5" y="2" width="5.5" height="5.5" rx="1" />
      <rect x="2" y="10.5" width="5.5" height="5.5" rx="1" />
      <rect x="10.5" y="10.5" width="5.5" height="5.5" rx="1" />
    </svg>
  )
}

function PatentsIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M4 2h10a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4a2 2 0 012-2z" />
      <path d="M6 6h6M6 9h6M6 12h3" />
    </svg>
  )
}

function UsersIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="9" cy="6" r="3" />
      <path d="M3 16c0-3.3 2.7-6 6-6s6 2.7 6 6" />
    </svg>
  )
}
