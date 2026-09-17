const KIND_STYLES = {
  success: 'border-status-granted/30 bg-canvas text-ink',
  error: 'border-status-rejected/30 bg-canvas text-ink',
  info: 'border-hairline bg-canvas text-ink',
}

const KIND_DOT = {
  success: 'bg-status-granted',
  error: 'bg-status-rejected',
  info: 'bg-copper',
}

// Fixed top-right stack; assertive live region so assistive tech announces
// every toast exactly once.
export default function Toast({ toasts, onDismiss }) {
  if (toasts.length === 0) return null
  return (
    <div className="fixed top-lg right-lg z-[100] flex flex-col gap-sm max-w-[360px]" role="alert" aria-live="assertive">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={`flex items-start gap-sm px-md py-sm rounded-md border shadow-editorial-md animate-scale-in ${KIND_STYLES[toast.kind] ?? KIND_STYLES.info}`}
        >
          <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${KIND_DOT[toast.kind] ?? KIND_DOT.info}`} aria-hidden="true" />
          <p className="flex-1 text-body-sm font-sans">{toast.message}</p>
          <button
            type="button"
            onClick={() => onDismiss(toast.id)}
            aria-label="Dismiss notification"
            className="touch-target text-muted hover:text-ink transition-colors rounded focus-visible:ring-2 focus-visible:ring-copper"
          >
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" >
              <path d="M3 3l8 8M11 3l-8 8" />
            </svg>
          </button>
        </div>
      ))}
    </div>
  )
}
