import { api } from '../api.js'

export const STATUS_OPTIONS = ['draft', 'filed', 'published', 'examination', 'granted', 'rejected', 'maintenance']

export async function sendStatusNotification(app, newStatus) {
  await api.notifyStatusChange(app.inventor_email, app.id, newStatus)
}

export function buildNotifyItems(app, onNotify) {
  return STATUS_OPTIONS
    .filter((s) => s !== app.status)
    .map((s) => ({
      label: `Notify: ${s.charAt(0).toUpperCase() + s.slice(1)}`,
      onClick: () => onNotify(app, s),
    }))
}
