import { useState, useEffect, useRef, useCallback } from 'react'
import { api } from '../../api.js'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import Card from '../../components/ui/Card.jsx'
import DataTable from '../../components/ui/DataTable.jsx'
import Dropdown from '../../components/ui/Dropdown.jsx'
import SearchInput from '../../components/ui/SearchInput.jsx'
import Modal from '../../components/ui/Modal.jsx'
import Input from '../../components/ui/Input.jsx'
import { sendStatusNotification, buildNotifyItems, STATUS_OPTIONS } from '../../utils/notifyHelpers.js'

export default function AdminPatentsPage() {
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [selectedApp, setSelectedApp] = useState(null)
  const [showNotifyModal, setShowNotifyModal] = useState(false)
  const [notifyForm, setNotifyForm] = useState({ subject: '', body: '' })
  const [sending, setSending] = useState(false)

  useEffect(() => {
    loadApps()
  }, [])

  async function loadApps() {
    setLoading(true)
    try {
      const data = await api.applications()
      setApps(data || [])
    } catch {
      // ignore
    } finally {
      setLoading(false)
    }
  }

  async function handleSendNotification(e) {
    e.preventDefault()
    if (!selectedApp) return
    setSending(true)
    try {
      await api.sendNotification(selectedApp.id, notifyForm.subject, notifyForm.body)
      setShowNotifyModal(false)
      setNotifyForm({ subject: '', body: '' })
      alert('Notification sent successfully!')
    } catch (err) {
      alert(err.message)
    } finally {
      setSending(false)
    }
  }

  async function handleNotifyStatus(app, newStatus) {
    try {
      await sendStatusNotification(app, newStatus)
      alert(`Notification sent: status change to "${newStatus}"`)
    } catch (err) {
      alert(err.message)
    }
  }

  const getNotifyItems = (app) => buildNotifyItems(app, handleNotifyStatus)

  const filtered = apps.filter((app) => {
    const matchesSearch = !search ||
      app.title?.toLowerCase().includes(search.toLowerCase()) ||
      app.application_number?.toLowerCase().includes(search.toLowerCase()) ||
      app.id?.toLowerCase().includes(search.toLowerCase())
    const matchesStatus = !filterStatus || app.status?.toLowerCase() === filterStatus
    return matchesSearch && matchesStatus
  })

  const columns = [
    {
      key: 'title',
      label: 'Title',
      render: (val) => <span className="font-medium font-sans">{val}</span>,
    },
    {
      key: 'application_number',
      label: 'Reference',
      render: (val, row) => (
        <span className="font-mono text-steel text-body-sm">{val || row.id?.slice(0, 8)}</span>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      render: (val) => <Badge>{val || 'draft'}</Badge>,
    },
    {
      key: 'inventor_email',
      label: 'Inventor',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    {
      key: 'technology_area',
      label: 'Tech Area',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    {
      key: 'actions',
      label: '',
      render: (_, row) => (
        <Dropdown
          trigger={
            <button
              type="button"
              onClick={(e) => e.stopPropagation()}
              className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
              aria-label="Notify inventor"
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                <circle cx="8" cy="3" r="1.5" />
                <circle cx="8" cy="8" r="1.5" />
                <circle cx="8" cy="13" r="1.5" />
              </svg>
            </button>
          }
          items={getNotifyItems(row)}
        />
      ),
    },
  ]

  return (
    <div className="space-y-xxl">
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Patents</h1>
        <p className="text-body-md text-steel font-sans">
          Manage patent portfolio and send notifications to inventors
        </p>
      </div>

      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-lg animate-slide-up stagger-2">
        <p className="text-body-md text-steel font-sans">
          {apps.length} {apps.length === 1 ? 'patent' : 'patents'} tracked
        </p>
      </div>

      <div className="flex flex-col sm:flex-row gap-sm animate-slide-up stagger-2">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search patents..."
          className="flex-1"
        />
        <select
          value={filterStatus}
          onChange={(e) => setFilterStatus(e.target.value)}
          className="h-9 px-md bg-ivory-200 text-ink text-body-sm border border-transparent rounded-md outline-none font-sans cursor-pointer hover:border-hairline focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
        >
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map((s) => (
            <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
          ))}
        </select>
      </div>

      <div className="animate-slide-up stagger-3">
        {loading ? (
          <div className="flex items-center justify-center py-section">
            <div className="flex items-center gap-sm text-steel">
              <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
              </svg>
              <span className="font-sans text-body-sm">Loading patents...</span>
            </div>
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={filtered}
            onRowClick={setSelectedApp}
            emptyMessage="No patents found."
          />
        )}
      </div>

      {/* Patent Detail Modal */}
      <Modal
        open={!!selectedApp}
        onClose={() => setSelectedApp(null)}
        title="Patent Details"
        size="lg"
      >
        {selectedApp && (
          <div className="space-y-lg">
            <div className="grid grid-cols-2 gap-lg">
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Title</p>
                <p className="text-body-md text-ink font-sans font-medium">{selectedApp.title}</p>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Status</p>
                <Badge>{selectedApp.status || 'draft'}</Badge>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Reference</p>
                <p className="text-body-sm text-ink font-mono">{selectedApp.application_number || selectedApp.id}</p>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Inventor</p>
                <p className="text-body-sm text-ink font-sans">{selectedApp.inventor_email || '\u2014'}</p>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Technology Area</p>
                <p className="text-body-sm text-ink font-sans">{selectedApp.technology_area || '\u2014'}</p>
              </div>
            </div>

            <div className="border-t border-hairline pt-lg">
              <p className="text-micro text-muted uppercase tracking-wider font-sans mb-sm">Admin Actions</p>
              <div className="flex flex-wrap gap-sm">
                {STATUS_OPTIONS.filter((s) => s !== selectedApp.status).map((s) => (
                  <Button
                    key={s}
                    variant="secondary"
                    size="sm"
                    onClick={async () => {
                      try {
                        await api.changeStatus(selectedApp.id, s)
                        setSelectedApp({ ...selectedApp, status: s })
                        await loadApps()
                      } catch (err) {
                        alert(err.message)
                      }
                    }}
                  >
                    Move to {s}
                  </Button>
                ))}
              </div>
            </div>

            <div className="border-t border-hairline pt-lg">
              <p className="text-micro text-muted uppercase tracking-wider font-sans mb-sm">Notifications</p>
              <Button
                variant="primary"
                size="sm"
                onClick={() => setShowNotifyModal(true)}
              >
                <svg className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M2 4l5 3.5L12 4" />
                  <rect x="1" y="2" width="12" height="10" rx="1" />
                </svg>
                Send Notification to Inventor
              </Button>
            </div>
          </div>
        )}
      </Modal>

      {/* Send Notification Modal */}
      <Modal open={showNotifyModal} onClose={() => setShowNotifyModal(false)} title="Send Notification" size="md">
        <form onSubmit={handleSendNotification} className="flex flex-col gap-lg">
          <p className="text-body-sm text-steel font-sans">
            Send a notification to <span className="font-medium text-ink">{selectedApp?.inventor_email}</span> regarding patent "{selectedApp?.title}"
          </p>
          <Input
            id="notify-subject"
            label="Subject"
            value={notifyForm.subject}
            onChange={(e) => setNotifyForm({ ...notifyForm, subject: e.target.value })}
            required
            placeholder="e.g. Action Required: Response Deadline"
          />
          <div>
            <label className="block text-body-sm-medium text-charcoal font-sans mb-xs">
              Message <span className="text-copper ml-1 text-caption">*</span>
            </label>
            <textarea
              value={notifyForm.body}
              onChange={(e) => setNotifyForm({ ...notifyForm, body: e.target.value })}
              required
              rows={4}
              placeholder="Enter the notification message..."
              className="w-full px-md py-sm bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
            />
          </div>
          <div className="flex justify-end gap-sm pt-sm">
            <Button variant="secondary" type="button" onClick={() => setShowNotifyModal(false)}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" disabled={sending}>
              {sending ? 'Sending...' : 'Send Notification'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
