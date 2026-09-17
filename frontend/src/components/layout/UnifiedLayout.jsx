import { useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import Sidebar, { DashboardIcon, PatentsIcon, UsersIcon, BellIcon } from '../ui/Sidebar.jsx'
import Avatar from '../ui/Avatar.jsx'
import Button from '../ui/Button.jsx'

const STAFF_NAV = [
  { to: '/admin', label: 'Overview', icon: DashboardIcon, end: true },
  { to: '/admin/patents', label: 'Patents', icon: PatentsIcon },
  { to: '/admin/users', label: 'Users', icon: UsersIcon },
]

const INVENTOR_NAV = [
  { to: '/user', label: 'Overview', icon: DashboardIcon, end: true },
  { to: '/user/patents', label: 'My Patents', icon: PatentsIcon },
  { to: '/user/notifications', label: 'Notifications', icon: BellIcon },
]

const STAFF_ROLES = ['admin', 'attorney', 'paralegal']

// Single layout shell for every role (issue #9). Sidebar state lives here:
// collapsed (desktop) + mobile drawer with overlay backdrop.
export default function UnifiedLayout({ user, onLogout }) {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const navigate = useNavigate()

  const isStaff = STAFF_ROLES.includes(user?.role)
  const items = isStaff ? STAFF_NAV : INVENTOR_NAV

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
        items={items}
        collapsed={collapsed}
        onToggle={() => setCollapsed((c) => !c)}
        mobileOpen={mobileOpen}
        onCloseMobile={() => setMobileOpen(false)}
      />

      {/* Mobile drawer backdrop */}
      {mobileOpen && (
        <div
          data-testid="drawer-backdrop"
          className="fixed inset-0 z-30 bg-navy/60 backdrop-blur-sm lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Main content area: no fixed margin on mobile, responsive on lg */}
      <div className={`transition-all duration-300 ${collapsed ? 'lg:ml-[64px]' : 'lg:ml-[240px]'}`}>
        <header className="sticky top-0 z-20 bg-ivory/80 backdrop-blur-md border-b border-hairline-soft">
          <div className="px-xl py-md flex items-center justify-between">
            <div className="flex items-center gap-sm">
              <button
                type="button"
                onClick={() => setMobileOpen((o) => !o)}
                aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
                className="lg:hidden text-steel hover:text-ink transition-colors p-xs rounded-md hover:bg-ivory-200 focus-visible:ring-2 focus-visible:ring-copper"
              >
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" aria-hidden="true">
                  {mobileOpen ? (
                    <path d="M5 5l10 10M15 5L5 15" />
                  ) : (
                    <path d="M3 6h14M3 10h14M3 14h14" />
                  )}
                </svg>
              </button>
              <span className="text-micro text-muted uppercase tracking-widest font-sans hidden sm:inline">
                {isStaff ? 'Admin Panel' : 'Patent Management'}
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
