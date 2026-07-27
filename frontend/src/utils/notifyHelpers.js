export const STATUS_OPTIONS = ['draft', 'filed', 'acknowledged', 'defect_sheet_1', 'defect_sheet_2', 'defect_sheet_3', 'granted', 'rejected']

export function buildStatusChangeItems(app, onStatusChange) {
  return STATUS_OPTIONS
    .filter((s) => s !== app.status)
    .map((s) => ({
      label: `Move to ${s.charAt(0).toUpperCase() + s.slice(1)}`,
      onClick: () => onStatusChange(app, s),
    }))
}
