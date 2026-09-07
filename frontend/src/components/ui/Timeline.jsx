export default function Timeline({ items = [] }) {
  if (items.length === 0) {
    return (
      <div className="py-xl text-center">
        <p className="text-body-md text-steel font-sans">No recent activity.</p>
      </div>
    )
  }

  return (
    <div className="relative">
      {/* Vertical line */}
      <div className="absolute left-[11px] top-2 bottom-2 w-px bg-hairline" />

      <ul className="space-y-lg">
        {items.map((item, i) => (
          <li
            key={item.id || i}
            className="relative flex gap-md pl-xl animate-slide-up"
            style={{ animationDelay: `${i * 0.06}s` }}
          >
            {/* Dot */}
            <div className="absolute left-0 top-1.5 w-[7px] h-[7px] rounded-full bg-copper border-2 border-canvas z-10" />

            <div className="flex-1 min-w-0">
              <p className="text-body-sm text-ink font-sans">{item.text}</p>
              <p className="text-caption text-muted mt-xxs font-sans">{item.time}</p>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
