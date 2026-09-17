import { NavLink } from 'react-router-dom'

const DEFAULT_NAV_ITEMS = [
  { to: '/', label: 'Overview', icon: DashboardIcon },
  { to: '/patents', label: 'Patents', icon: PatentsIcon },
]

// `items` override the default links (UnifiedLayout passes per-role nav).
// `mobileOpen` slides the sidebar in as an overlay drawer on small screens;
// on lg+ it is always visible. State lives in the layout, not here.
export default function Sidebar({ collapsed = false, onToggle, items = DEFAULT_NAV_ITEMS, mobileOpen = false, onCloseMobile }) {
  return (
    <aside className={`fixed left-0 top-0 h-screen bg-navy dark:bg-navy-800 text-white z-40 transition-all duration-300 flex flex-col ${collapsed ? 'w-[64px]' : 'w-[240px]'} ${mobileOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}>
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
          className="touch-target text-white/50 hover:text-white transition-colors p-xs rounded hover:bg-white/10"
          aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        >
          <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5">
            {collapsed ? (
              <path d="M6 4l4 4-4 4" />
            ) : (
              <path d="M10 4l-4 4 4 4" />
            )}
          </svg>
        </button>
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-lg overflow-y-auto" aria-label="Primary">
        <ul className="space-y-xxs">
          {items.map(({ to, label, icon: Icon, end }) => (
            <li key={to}>
              <NavLink
                to={to}
                end={end ?? to === '/'}
                onClick={() => onCloseMobile?.()}
                className={({ isActive }) =>
                  `flex items-center gap-sm px-lg py-sm mx-xs rounded-md text-body-sm-medium font-sans transition-all duration-150 min-h-[44px] ${
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

export function DashboardIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" >
      <rect x="2" y="2" width="5.5" height="5.5" rx="1" />
      <rect x="10.5" y="2" width="5.5" height="5.5" rx="1" />
      <rect x="2" y="10.5" width="5.5" height="5.5" rx="1" />
      <rect x="10.5" y="10.5" width="5.5" height="5.5" rx="1" />
    </svg>
  )
}

export function PatentsIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" >
      <path d="M4 2h10a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V4a2 2 0 012-2z" />
      <path d="M6 6h6M6 9h6M6 12h3" />
    </svg>
  )
}

export function UsersIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" >
      <circle cx="9" cy="6" r="3" />
      <path d="M3 16c0-3.3 2.7-6 6-6s6 2.7 6 6" />
    </svg>
  )
}

export function BellIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" >
      <path d="M9 2a5 5 0 015 5c0 4 1.5 5.5 1.5 5.5h-13S4 11 4 7a5 5 0 015-5z" />
      <path d="M7.5 15a1.5 1.5 0 003 0" />
    </svg>
  )
}
