import Button from './Button.jsx'

// Standardized empty state (issue #32): icon + title + description +
// optional CTA, with proper heading hierarchy for assistive tech.
export default function EmptyState({ icon, title, description, actionLabel, onAction }) {
  return (
    <div className="bg-canvas rounded-lg border border-hairline p-xxl text-center">
      {icon && (
        <div className="mx-auto mb-md w-11 h-11 rounded-full bg-ivory-200 flex items-center justify-center text-steel" aria-hidden="true">
          {icon}
        </div>
      )}
      <h3 className="font-display text-heading-4 text-ink mb-xs">{title}</h3>
      {description && (
        <p className="text-body-sm text-steel font-sans mb-lg">{description}</p>
      )}
      {actionLabel && onAction && (
        <Button variant="secondary" size="sm" onClick={onAction}>
          {actionLabel}
        </Button>
      )}
    </div>
  )
}
