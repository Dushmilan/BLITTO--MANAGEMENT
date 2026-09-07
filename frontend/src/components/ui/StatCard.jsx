export default function StatCard({ label, value, trend, trendLabel, icon, className = '' }) {
  return (
    <div className={`bg-canvas rounded-lg p-xl border border-hairline hover:shadow-editorial-md hover:border-copper-200 transition-all duration-200 ${className}`}>
      <div className="flex items-start justify-between mb-sm">
        <span className="text-body-sm text-steel font-sans uppercase tracking-wider text-micro">{label}</span>
        {icon && <span className="text-copper">{icon}</span>}
      </div>
      <div className="text-display-lg font-display text-ink leading-none mb-xs">
        {value}
      </div>
      {(trend || trendLabel) && (
        <div className="flex items-center gap-xs">
          {trend && (
            <span className={`text-caption-bold ${trend > 0 ? 'text-status-granted' : 'text-status-rejected'}`}>
              {trend > 0 ? '↑' : '↓'} {Math.abs(trend)}%
            </span>
          )}
          {trendLabel && (
            <span className="text-caption text-muted">{trendLabel}</span>
          )}
        </div>
      )}
    </div>
  )
}
