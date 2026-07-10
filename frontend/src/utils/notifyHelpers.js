export const STATUS_OPTIONS = ['draft', 'filed', 'published', 'examination', 'granted', 'rejected', 'maintenance']

export function buildStatusChangeItems(app, onStatusChange) {
  return STATUS_OPTIONS
    .filter((s) => s !== app.status)
    .map((s) => ({
      label: `Move to ${s.charAt(0).toUpperCase() + s.slice(1)}`,
      onClick: () => onStatusChange(app, s),
    }))
}
