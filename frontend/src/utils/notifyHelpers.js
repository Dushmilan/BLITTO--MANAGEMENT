export const STATUS_OPTIONS = ['draft', 'filed', 'acknowledged', 'defect_sheet_1', 'defect_sheet_2', 'defect_sheet_3', 'granted', 'rejected']

export function buildStatusChangeItems(app, onStatusChange) {
  return STATUS_OPTIONS
    .filter((s) => s !== app.status)
    .map((s) => ({
      label: `Move to ${s.charAt(0).toUpperCase() + s.slice(1)}`,
      onClick: () => onStatusChange(app, s),
    }))
}

// Normalize the inventor field to [{ inventor_name, inventor_email }].
// The API returns an array, but legacy snapshots or hand-built payloads
// may carry a single email string or a bare object — coerce those too so
// every view renders the same shape (issue #24).
export function normalizeInventors(value) {
  if (!value) return []
  if (typeof value === 'string') {
    return value ? [{ inventor_name: '', inventor_email: value }] : []
  }
  if (Array.isArray(value)) {
    return value
      .map((inv) => {
        if (typeof inv === 'string') return { inventor_name: '', inventor_email: inv }
        if (inv && typeof inv === 'object') {
          return {
            inventor_name: inv.inventor_name || '',
            inventor_email: inv.inventor_email || '',
          }
        }
        return null
      })
      .filter(Boolean)
  }
  if (typeof value === 'object') {
    return [{
      inventor_name: value.inventor_name || '',
      inventor_email: value.inventor_email || '',
    }]
  }
  return []
}

// Compact table rendering: first inventor name plus an overflow count.
export function inventorSummary(value) {
  const list = normalizeInventors(value)
  if (list.length === 0) return { first: '', extra: 0 }
  return {
    first: list[0].inventor_name || list[0].inventor_email || '',
    extra: list.length - 1,
  }
}
