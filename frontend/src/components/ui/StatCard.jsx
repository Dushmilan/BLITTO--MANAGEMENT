export default function StatCard({ label, value, trend, trendLabel, icon, className = '' }) {
  return (
    <div className={`bg-canvas dark:bg-navy-800 rounded-lg p-xl border border-hairline dark:border-hairline-dark hover:shadow-editorial-md hover:border-copper-200 transition-all duration-200 ${className}`}>
      <div className="flex items-start justify-between mb-sm">
        <span className="text-steel dark:text-white/60 font-sans uppercase tracking-wider text-micro">{label}</span>
        {icon && <span className="text-copper">{icon}</span>}
      </div>
      <div className="text-display-lg font-display text-ink dark:text-white leading-none mb-xs">
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
            <span className="text-caption text-muted dark:text-white/50">{trendLabel}</span>
          )}
        </div>
      )}
    </div>
  )
}
