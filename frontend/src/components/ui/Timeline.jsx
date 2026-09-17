import { Link } from 'react-router-dom'
import EmptyState from './EmptyState.jsx'

// Activity timeline (issue #20). Items with a `to` target render as
// keyboard-accessible links (focus-visible styled); others stay text.
export default function Timeline({ items = [] }) {
  if (items.length === 0) {
    return <EmptyState title="No recent activity." />
  }

  return (
    <div className="relative">
      {/* Vertical line */}
      <div className="absolute left-[11px] top-2 bottom-2 w-px bg-hairline" aria-hidden="true" />

      <ul className="space-y-lg">
        {items.map((item, i) => (
          <li
            key={item.id || i}
            className="relative flex gap-md pl-xl animate-slide-up"
            style={{ animationDelay: `${i * 0.06}s` }}
          >
            {/* Dot */}
            <div className="absolute left-0 top-1.5 w-[7px] h-[7px] rounded-full bg-copper border-2 border-canvas z-10" aria-hidden="true" />

            <div className="flex-1 min-w-0">
              {item.to ? (
                <Link
                  to={item.to}
                  aria-label={`View patent: ${item.text}`}
                  className="text-body-sm text-ink font-sans rounded hover:text-copper hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-copper"
                >
                  {item.text}
                </Link>
              ) : (
                <p className="text-body-sm text-ink font-sans">{item.text}</p>
              )}
              <p className="text-caption text-muted mt-xxs font-sans">{item.time}</p>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
