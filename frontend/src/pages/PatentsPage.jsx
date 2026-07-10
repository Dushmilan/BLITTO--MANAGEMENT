import { useState, useEffect, useRef, useCallback } from 'react'
import { api } from '../api.js'
import Badge from '../components/ui/Badge.jsx'
import Button from '../components/ui/Button.jsx'
import Card from '../components/ui/Card.jsx'
import DataTable from '../components/ui/DataTable.jsx'
import Dropdown from '../components/ui/Dropdown.jsx'
import SearchInput from '../components/ui/SearchInput.jsx'
import Modal from '../components/ui/Modal.jsx'
import Input from '../components/ui/Input.jsx'
import { sendStatusNotification, buildNotifyItems, STATUS_OPTIONS } from '../utils/notifyHelpers.js'

const DEADLINE_TYPES = ['filing_deadline', 'response_deadline', 'maintenance_fee', 'appeal_deadline', 'IDS_deadline', 'continuation_deadline']

const TABS = [
  { id: 'list', label: 'Patents' },
  { id: 'deadlines', label: 'Deadlines' },
  { id: 'documents', label: 'Documents' },
]

export default function PatentsPage({ user }) {
  const [activeTab, setActiveTab] = useState('list')
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)

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

  return (
    <div className="space-y-xxl">
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Patents</h1>
        <p className="text-body-md text-steel font-sans">
          Manage patent portfolio, deadlines, and documents
        </p>
      </div>

      {/* Tabs */}
      <div className="border-b border-hairline animate-slide-up stagger-1">
        <nav className="flex gap-lg -mb-px">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`py-sm px-xs text-body-sm-medium font-sans border-b-2 transition-colors duration-150 ${
                activeTab === tab.id
                  ? 'border-copper text-copper'
                  : 'border-transparent text-steel hover:text-ink hover:border-hairline'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'list' && (
        <PatentsTab apps={apps} loading={loading} onReload={loadApps} user={user} />
      )}
      {activeTab === 'deadlines' && (
        <DeadlinesTab apps={apps} />
      )}
      {activeTab === 'documents' && (
        <DocumentsTab apps={apps} user={user} />
      )}
    </div>
  )
}

/* ─── Patents List Tab ─── */

function PatentsTab({ apps, loading, onReload, user }) {
  const [search, setSearch] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [showNewModal, setShowNewModal] = useState(false)
  const [selectedApp, setSelectedApp] = useState(null)
  const [newForm, setNewForm] = useState({ title: '', inventor_email: '', technology_area: '' })
  const [creating, setCreating] = useState(false)

  async function handleCreate(e) {
    e.preventDefault()
    setCreating(true)
    try {
      await api.createApplication(newForm)
      setShowNewModal(false)
      setNewForm({ title: '', inventor_email: '', technology_area: '' })
      await onReload()
    } catch (err) {
      alert(err.message)
    } finally {
      setCreating(false)
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
    ...(user?.role === 'admin'
      ? [
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
      : []),
  ]

  return (
    <div className="space-y-xxl">
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-lg animate-slide-up stagger-2">
        <p className="text-body-md text-steel font-sans">
          {apps.length} {apps.length === 1 ? 'patent' : 'patents'} tracked
        </p>
        <Button variant="primary" onClick={() => setShowNewModal(true)}>
          <svg className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M7 2v10M2 7h10" />
          </svg>
          New Patent
        </Button>
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
            emptyMessage="No patents found. Add your first patent to get started."
          />
        )}
      </div>

      {/* New Patent Modal */}
      <Modal open={showNewModal} onClose={() => setShowNewModal(false)} title="New Patent" size="md">
        <form onSubmit={handleCreate} className="flex flex-col gap-lg">
          <Input
            id="patent-title"
            label="Title"
            value={newForm.title}
            onChange={(e) => setNewForm({ ...newForm, title: e.target.value })}
            required
            placeholder="e.g. Novel Semiconductor Device Architecture"
          />
          <Input
            id="patent-inventor"
            label="Inventor Email"
            type="email"
            value={newForm.inventor_email}
            onChange={(e) => setNewForm({ ...newForm, inventor_email: e.target.value })}
            placeholder="inventor@university.edu"
          />
          <Input
            id="patent-tech"
            label="Technology Area"
            value={newForm.technology_area}
            onChange={(e) => setNewForm({ ...newForm, technology_area: e.target.value })}
            placeholder="e.g. Electrical Engineering"
          />
          <div className="flex justify-end gap-sm pt-sm">
            <Button variant="secondary" type="button" onClick={() => setShowNewModal(false)}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" disabled={creating}>
              {creating ? 'Creating...' : 'Create Patent'}
            </Button>
          </div>
        </form>
      </Modal>

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

            {user?.role === 'admin' && (
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
                          await onReload()
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
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

/* ─── Deadlines Tab ─── */

function DeadlinesTab({ apps }) {
  const [deadlines, setDeadlines] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showAddModal, setShowAddModal] = useState(false)
  const [addForm, setAddForm] = useState({ application_id: '', type: 'filing_deadline', due_date: '' })
  const [adding, setAdding] = useState(false)

  useEffect(() => {
    loadDeadlines()
  }, [])

  async function loadDeadlines() {
    setLoading(true)
    try {
      const data = await api.deadlines()
      setDeadlines(data || [])
    } catch {
      // ignore
    } finally {
      setLoading(false)
    }
  }

  async function handleAdd(e) {
    e.preventDefault()
    setAdding(true)
    try {
      await api.addDeadline(addForm.application_id, addForm.type, addForm.due_date)
      setShowAddModal(false)
      setAddForm({ application_id: '', type: 'filing_deadline', due_date: '' })
      await loadDeadlines()
    } catch (err) {
      alert(err.message)
    } finally {
      setAdding(false)
    }
  }

  function getDaysLeft(dateStr) {
    if (!dateStr) return null
    const diff = new Date(dateStr) - new Date()
    return Math.ceil(diff / (1000 * 60 * 60 * 24))
  }

  function getUrgencyClass(days) {
    if (days === null) return ''
    if (days < 0) return 'bg-status-rejected/10 text-status-rejected'
    if (days <= 7) return 'bg-status-rejected/10 text-status-rejected'
    if (days <= 14) return 'bg-status-examination/10 text-status-examination'
    if (days <= 30) return 'bg-status-filed/10 text-status-filed'
    return 'bg-ivory-200 text-slate'
  }

  const filtered = deadlines.filter((d) => {
    if (!search) return true
    const s = search.toLowerCase()
    return (
      d.deadline_type?.toLowerCase().includes(s) ||
      d.application_id?.toLowerCase().includes(s) ||
      d.type?.toLowerCase().includes(s)
    )
  })

  const columns = [
    {
      key: 'deadline_type',
      label: 'Type',
      render: (val, row) => (
        <span className="font-medium font-sans text-ink capitalize">
          {(val || row.type || 'deadline').replace(/_/g, ' ')}
        </span>
      ),
    },
    {
      key: 'application_id',
      label: 'Application',
      render: (val) => <span className="font-mono text-steel text-body-sm">{val?.slice(0, 8) || '\u2014'}</span>,
    },
    {
      key: 'due_date',
      label: 'Due Date',
      render: (val) => (
        <span className="font-mono text-body-sm text-ink">
          {val ? new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '\u2014'}
        </span>
      ),
    },
    {
      key: 'due_date',
      label: 'Time Left',
      render: (val) => {
        const days = getDaysLeft(val)
        return (
          <span className={`text-caption-bold font-mono px-xs py-xxs rounded ${getUrgencyClass(days)}`}>
            {days === null ? '\u2014' : days < 0 ? `${Math.abs(days)}d overdue` : `${days}d`}
          </span>
        )
      },
    },
  ]

  return (
    <div className="space-y-xxl">
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-lg animate-slide-up stagger-2">
        <p className="text-body-md text-steel font-sans">
          {deadlines.length} deadline{deadlines.length !== 1 ? 's' : ''} tracked
        </p>
        <Button variant="primary" onClick={() => setShowAddModal(true)}>
          <svg className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M7 2v10M2 7h10" />
          </svg>
          Add Deadline
        </Button>
      </div>

      <div className="animate-slide-up stagger-2">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search deadlines..."
          className="max-w-[400px]"
        />
      </div>

      <div className="animate-slide-up stagger-3">
        {loading ? (
          <div className="flex items-center justify-center py-section">
            <div className="flex items-center gap-sm text-steel">
              <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
              </svg>
              <span className="font-sans text-body-sm">Loading deadlines...</span>
            </div>
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={filtered}
            emptyMessage="No deadlines found. Add a deadline to start tracking."
          />
        )}
      </div>

      {/* Add Deadline Modal */}
      <Modal open={showAddModal} onClose={() => setShowAddModal(false)} title="Add Deadline" size="md">
        <form onSubmit={handleAdd} className="flex flex-col gap-lg">
          <div>
            <label className="block text-body-sm-medium text-charcoal font-sans mb-xs">
              Application <span className="text-copper ml-1 text-caption">*</span>
            </label>
            <select
              value={addForm.application_id}
              onChange={(e) => setAddForm({ ...addForm, application_id: e.target.value })}
              required
              className="w-full h-10 px-md bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans cursor-pointer focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
            >
              <option value="">Select an application</option>
              {apps.map((app) => (
                <option key={app.id} value={app.id}>
                  {app.title} ({(app.application_number || app.id)?.slice(0, 8)})
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-body-sm-medium text-charcoal font-sans mb-xs">
              Deadline Type <span className="text-copper ml-1 text-caption">*</span>
            </label>
            <select
              value={addForm.type}
              onChange={(e) => setAddForm({ ...addForm, type: e.target.value })}
              required
              className="w-full h-10 px-md bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans cursor-pointer focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
            >
              {DEADLINE_TYPES.map((t) => (
                <option key={t} value={t}>
                  {t.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())}
                </option>
              ))}
            </select>
          </div>
          <Input
            id="due-date"
            label="Due Date"
            type="date"
            value={addForm.due_date}
            onChange={(e) => setAddForm({ ...addForm, due_date: e.target.value })}
            required
          />
          <div className="flex justify-end gap-sm pt-sm">
            <Button variant="secondary" type="button" onClick={() => setShowAddModal(false)}>
              Cancel
            </Button>
            <Button variant="primary" type="submit" disabled={adding}>
              {adding ? 'Adding...' : 'Add Deadline'}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}

/* ─── Documents Tab ─── */

function DocumentsTab({ apps, user }) {
  const [selectedAppId, setSelectedAppId] = useState('')
  const [documents, setDocuments] = useState([])
  const [docsLoading, setDocsLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [uploading, setUploading] = useState(false)
  const fileInputRef = useRef(null)

  const canUpload = user?.role === 'admin' || user?.role === 'paralegal'

  useEffect(() => {
    if (apps.length > 0 && !selectedAppId) {
      setSelectedAppId(apps[0].id)
    }
  }, [apps])

  useEffect(() => {
    if (!selectedAppId) {
      setDocuments([])
      return
    }
    async function loadDocs() {
      setDocsLoading(true)
      try {
        const data = await api.documents(selectedAppId)
        setDocuments(data || [])
      } catch {
        setDocuments([])
      } finally {
        setDocsLoading(false)
      }
    }
    loadDocs()
  }, [selectedAppId])

  async function handleUpload(e) {
    const file = e.target.files?.[0]
    if (!file || !selectedAppId) return
    setUploading(true)
    try {
      await api.uploadDocument(selectedAppId, file.name, file)
      const data = await api.documents(selectedAppId)
      setDocuments(data || [])
    } catch (err) {
      alert(err.message)
    } finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  const selectedApp = apps.find((a) => a.id === selectedAppId)

  const filteredDocs = documents.filter((d) => {
    if (!search) return true
    return d.filename?.toLowerCase().includes(search.toLowerCase())
  })

  const columns = [
    {
      key: 'filename',
      label: 'Filename',
      render: (val) => (
        <div className="flex items-center gap-sm">
          <FileIcon />
          <span className="font-medium font-sans text-ink">{val}</span>
        </div>
      ),
    },
    {
      key: 'uploaded_by',
      label: 'Uploaded By',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    {
      key: 'created_at',
      label: 'Date',
      render: (val) => (
        <span className="font-mono text-body-sm text-steel">
          {val ? new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '\u2014'}
        </span>
      ),
    },
  ]

  return (
    <div className="space-y-xxl">
      <Card variant="base" className="animate-slide-up stagger-2">
        <div className="flex flex-col sm:flex-row sm:items-center gap-lg">
          <div className="flex-1">
            <label className="block text-micro text-muted uppercase tracking-wider font-sans mb-xs">
              Select Patent
            </label>
            <select
              value={selectedAppId}
              onChange={(e) => setSelectedAppId(e.target.value)}
              className="w-full h-10 px-md bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans cursor-pointer focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
            >
              <option value="">Choose a patent</option>
              {apps.map((app) => (
                <option key={app.id} value={app.id}>
                  {app.title} ({(app.application_number || app.id)?.slice(0, 8)})
                </option>
              ))}
            </select>
          </div>
          {canUpload && selectedAppId && (
            <div className="flex items-end gap-sm">
              <input
                ref={fileInputRef}
                type="file"
                onChange={handleUpload}
                className="hidden"
                id="file-upload"
              />
              <Button
                variant="primary"
                onClick={() => fileInputRef.current?.click()}
                disabled={uploading}
              >
                {uploading ? (
                  <span className="flex items-center gap-xs">
                    <svg className="animate-spin" width="14" height="14" viewBox="0 0 14 14" fill="none">
                      <circle cx="7" cy="7" r="5" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                      <path d="M7 2a5 5 0 013.54 1.46" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                    </svg>
                    Uploading...
                  </span>
                ) : (
                  <>
                    <svg className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                      <path d="M7 10V3M4 5l3-3 3 3" />
                      <path d="M2 10v2a1 1 0 001 1h8a1 1 0 001-1v-2" />
                    </svg>
                    Upload
                  </>
                )}
              </Button>
            </div>
          )}
        </div>
      </Card>

      {selectedAppId && (
        <div className="animate-slide-up stagger-3">
          <div className="flex items-center justify-between mb-lg">
            <h2 className="font-display text-heading-4 text-ink">
              {selectedApp?.title || 'Documents'}
            </h2>
            <SearchInput
              value={search}
              onChange={setSearch}
              placeholder="Search documents..."
              className="max-w-[300px]"
            />
          </div>
          {docsLoading ? (
            <div className="flex items-center justify-center py-xl">
              <div className="flex items-center gap-sm text-steel">
                <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                  <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                </svg>
                <span className="font-sans text-body-sm">Loading documents...</span>
              </div>
            </div>
          ) : (
            <DataTable
              columns={columns}
              data={filteredDocs}
              emptyMessage="No documents uploaded yet."
            />
          )}
        </div>
      )}
    </div>
  )
}

function FileIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-copper flex-shrink-0">
      <path d="M10 2H4a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V8l-6-6z" />
      <path d="M10 2v6h6" />
    </svg>
  )
}
