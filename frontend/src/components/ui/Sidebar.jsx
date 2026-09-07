import { NavLink } from 'react-router-dom'

const NAV_ITEMS = [
  { to: '/', label: 'Overview', icon: DashboardIcon },
  { to: '/patents', label: 'Patents', icon: PatentsIcon },
]

export default function Sidebar({ collapsed = false, onToggle }) {
  return (
    <aside className={`fixed left-0 top-0 h-screen bg-navy text-white z-40 transition-all duration-300 flex flex-col ${collapsed ? 'w-[64px]' : 'w-[240px]'}`}>
      {/* Brand */}
      <div className="px-lg py-xl border-b border-white/10 flex items-center justify-between">
        {!collapsed && (
          <div className="flex flex-col">
            <span className="font-display text-heading-4 text-white tracking-tight">BLITTO</span>
            <span className="text-micro text-white/50 font-sans tracking-wider uppercase">Patent Management</span>
          </div>
        )}
        <button
          onClick={onToggle}
          className="text-white/50 hover:text-white transition-colors p-xs rounded hover:bg-white/10"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
            {collapsed ? (
              <path d="M6 4l4 4-4 4" />
            ) : (
              <path d="M10 4l-4 4 4 4" />
            )}
          </svg>
        </button>
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-lg overflow-y-auto">
        <ul className="space-y-xxs">
          {NAV_ITEMS.map(({ to, label, icon: Icon }) => (
            <li key={to}>
              <NavLink
                to={to}
                end={to === '/'}
                className={({ isActive }) =>
                  `flex items-center gap-sm px-lg py-sm mx-xs rounded-md text-body-sm-medium font-sans transition-all duration-150 ${
                    isActive
                      ? 'bg-copper text-white'
                      : 'text-white/60 hover:bg-white/8 hover:text-white'
                  } ${collapsed ? 'justify-center' : ''}`
                }
              >
                <Icon />
                {!collapsed && <span>{label}</span>}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      {/* Footer */}
      {!collapsed && (
        <div className="px-lg py-lg border-t border-white/10">
          <p className="text-micro text-white/30 font-sans">University of Peradeniya</p>
        </div>
      )}
    </aside>
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
