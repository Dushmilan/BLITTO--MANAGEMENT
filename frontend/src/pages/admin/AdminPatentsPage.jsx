import { useState, useEffect } from 'react'
import { api } from '../../api.js'
import Badge from '../../components/ui/Badge.jsx'
import Button from '../../components/ui/Button.jsx'
import DataTable from '../../components/ui/DataTable.jsx'
import Dropdown from '../../components/ui/Dropdown.jsx'
import SearchInput from '../../components/ui/SearchInput.jsx'
import Modal from '../../components/ui/Modal.jsx'
import { buildStatusChangeItems, STATUS_OPTIONS } from '../../utils/notifyHelpers.js'

export default function AdminPatentsPage() {
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [selectedApp, setSelectedApp] = useState(null)

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

  async function handleStatusChange(app, newStatus) {
    try {
      await api.changeStatus(app.id, newStatus)
      await loadApps()
      if (selectedApp?.id === app.id) {
        setSelectedApp({ ...app, status: newStatus })
      }
    } catch (err) {
      alert(err.message)
    }
  }

  const getStatusItems = (app) => buildStatusChangeItems(app, handleStatusChange)

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
              className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
              aria-label="Change status"
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                <circle cx="8" cy="3" r="1.5" />
                <circle cx="8" cy="8" r="1.5" />
                <circle cx="8" cy="13" r="1.5" />
              </svg>
            </button>
          }
          items={getStatusItems(row)}
        />
      ),
    },
  ]

  return (
    <div className="space-y-xxl">
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Patents</h1>
        <p className="text-body-md text-steel font-sans">
          Manage patent portfolio and update application status
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
          </div>
        )}
      </Modal>
    </div>
  )
}
