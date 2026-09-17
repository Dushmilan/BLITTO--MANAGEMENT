// Pulse-animated loading placeholder (issue #33). Shaped like content
// rows to prevent layout shift; announced via role=status + aria-busy.
export default function Skeleton({ rows = 1, label = 'Loading...' }) {
  return (
    <div role="status" aria-label={label} aria-busy="true" className="space-y-sm">
      <span className="sr-only">{label}</span>
      {Array.from({ length: rows }).map((_, i) => (
        <div
          key={i}
          data-skeleton
          aria-hidden="true"
          className="animate-pulse rounded-md bg-ivory-200 dark:bg-white/10 h-12"
        />
      ))}
    </div>
  )
}
