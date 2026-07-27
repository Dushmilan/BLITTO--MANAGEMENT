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
import { STATUS_OPTIONS } from '../utils/notifyHelpers.js'

const TABS = [
  { id: 'list', label: 'Patents' },
  { id: 'documents', label: 'Documents' },
]

export default function PatentsPage({ user }) {
  const [activeTab, setActiveTab] = useState('list')
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)
  const isAdmin = user?.role === 'admin'
  const visibleTabs = TABS.filter((t) => !t.adminOnly || isAdmin)

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
          Manage patent portfolio and documents
        </p>
      </div>

      {/* Tabs */}
      <div className="border-b border-hairline animate-slide-up stagger-1">
        <nav className="flex gap-lg -mb-px">
          {visibleTabs.map((tab) => (
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
  const [newForm, setNewForm] = useState({ title: '', inventors: [{ inventor_name: '', inventor_email: '' }], technology_area: '' })
  const [creating, setCreating] = useState(false)
  const [showGrantModal, setShowGrantModal] = useState(false)
  const [grantingApp, setGrantingApp] = useState(null)
  const [grantPatentNumber, setGrantPatentNumber] = useState('')
  const [granting, setGranting] = useState(false)

  function addInventor() {
    setNewForm({ ...newForm, inventors: [...newForm.inventors, { inventor_name: '', inventor_email: '' }] })
  }

  function removeInventor(i) {
    const inv = newForm.inventors.filter((_, idx) => idx !== i)
    setNewForm({ ...newForm, inventors: inv.length ? inv : [{ inventor_name: '', inventor_email: '' }] })
  }

  function updateInventor(i, field, value) {
    const inv = [...newForm.inventors]
    inv[i] = { ...inv[i], [field]: value }
    setNewForm({ ...newForm, inventors: inv })
  }

  async function handleCreate(e) {
    e.preventDefault()
    setCreating(true)
    try {
      const payload = {
        title: newForm.title,
        inventors: newForm.inventors.filter(inv => inv.inventor_name || inv.inventor_email),
        technology_area: newForm.technology_area,
      }
      await api.createApplication(payload)
      setShowNewModal(false)
      setNewForm({ title: '', inventors: [{ inventor_name: '', inventor_email: '' }], technology_area: '' })
      await onReload()
    } catch (err) {
      alert(err.message)
    } finally {
      setCreating(false)
    }
  }

  async function handleFilingAction(app, action) {
    try {
      if (action === 'file') {
        await api.markFiled(app.id)
      } else if (action === 'acknowledge') {
        await api.acknowledgeNipo(app.id)
      } else if (action === 'defect') {
        for (let n = 1; n <= 3; n++) {
          try {
            await api.recordDefectSheet(app.id, n, '')
            break
          } catch (err) {
            if (err.message?.includes('already exists')) {
              if (n === 3) throw new Error('Maximum 3 defect sheets reached')
              continue
            }
            throw err
          }
        }
      } else if (action === 'grant') {
        setGrantingApp(app)
        setGrantPatentNumber('')
        setShowGrantModal(true)
        return
      } else if (action === 'reject') {
        if (!confirm('Reject this patent?')) return
        await api.markRejected(app.id)
      }
      await onReload()
    } catch (err) {
      alert(err.message)
    }
  }

  async function handleGrantConfirm() {
    if (!grantingApp || !grantPatentNumber.trim()) return
    setGranting(true)
    try {
      await api.markGranted(grantingApp.id, grantPatentNumber.trim())
      setShowGrantModal(false)
      setGrantingApp(null)
      setGrantPatentNumber('')
      await onReload()
    } catch (err) {
      alert(err.message)
    } finally {
      setGranting(false)
    }
  }

  function getFilingItems(app) {
    const items = []
    const status = app.status?.toLowerCase()
    if (status === 'draft') {
      items.push({ label: 'File Patent', onClick: () => handleFilingAction(app, 'file') })
    } else if (status === 'filed') {
      items.push({ label: 'Acknowledge', onClick: () => handleFilingAction(app, 'acknowledge') })
      items.push({ label: 'Add Defect Sheet', onClick: () => handleFilingAction(app, 'defect') })
      items.push({ label: 'Grant', onClick: () => handleFilingAction(app, 'grant') })
      items.push({ type: 'separator' })
      items.push({ label: 'Reject', onClick: () => handleFilingAction(app, 'reject') })
    } else if (['acknowledged', 'examination', 'defect_sheet_1', 'defect_sheet_2', 'defect_sheet_3'].includes(status)) {
      items.push({ label: 'Add Defect Sheet', onClick: () => handleFilingAction(app, 'defect') })
      items.push({ label: 'Grant', onClick: () => handleFilingAction(app, 'grant') })
      items.push({ type: 'separator' })
      items.push({ label: 'Reject', onClick: () => handleFilingAction(app, 'reject') })
    }
    return items
  }

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
      key: 'inventors',
      label: 'Inventors',
      render: (val) => {
        if (!val || val.length === 0) return <span className="text-body-sm text-steel font-sans">{'\u2014'}</span>
        return (
          <div className="flex flex-wrap gap-x-sm gap-y-0.5">
            {val.map((inv, i) => (
              <span key={i} className="text-body-sm text-steel font-sans">
                {inv.inventor_name || inv.inventor_email}{i < val.length - 1 ? ',' : ''}
              </span>
            ))}
          </div>
        )
      },
    },
    {
      key: 'technology_area',
      label: 'Tech Area',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    ...(user?.role === 'admin'
      ? [{
          key: 'actions',
          label: '',
          render: (_, row) => {
            const items = getFilingItems(row)
            if (items.length === 0) return null
            return (
              <Dropdown
                trigger={
                  <button
                    type="button"
                    className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
                    aria-label="Filing actions"
                  >
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                      <circle cx="8" cy="3" r="1.5" />
                      <circle cx="8" cy="8" r="1.5" />
                      <circle cx="8" cy="13" r="1.5" />
                    </svg>
                  </button>
                }
                items={items}
              />
            )
          },
        }]
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
          <div className="space-y-sm">
            <label className="block text-micro text-muted uppercase tracking-wider font-sans">
              Inventors
            </label>
            {newForm.inventors.map((inv, i) => (
              <div key={i} className="flex gap-sm items-start">
                <div className="flex-1 space-y-xs">
                  <input
                    type="text"
                    value={inv.inventor_name}
                    onChange={(e) => updateInventor(i, 'inventor_name', e.target.value)}
                    placeholder="Inventor name"
                    className="w-full h-9 px-sm bg-canvas text-ink text-body-sm border border-hairline rounded-md outline-none font-sans placeholder:text-steel focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
                  />
                  <input
                    type="email"
                    value={inv.inventor_email}
                    onChange={(e) => updateInventor(i, 'inventor_email', e.target.value)}
                    placeholder="inventor@university.edu"
                    className="w-full h-9 px-sm bg-canvas text-ink text-body-sm border border-hairline rounded-md outline-none font-sans placeholder:text-steel focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
                  />
                </div>
                <button
                  type="button"
                  onClick={() => removeInventor(i)}
                  className="mt-0.5 w-7 h-7 flex items-center justify-center rounded-md hover:bg-status-rejected/10 text-slate hover:text-status-rejected transition-colors duration-150 shrink-0"
                  aria-label="Remove inventor"
                >
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <path d="M3 3l8 8M11 3l-8 8" />
                  </svg>
                </button>
              </div>
            ))}
            <button
              type="button"
              onClick={addInventor}
              className="flex items-center gap-xs text-body-sm text-copper hover:text-copper-600 font-sans transition-colors duration-150"
            >
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5">
                <path d="M6 2v8M2 6h8" />
              </svg>
              Add inventor
            </button>
          </div>
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

      {/* Grant Modal */}
      <Modal open={showGrantModal} onClose={() => { setShowGrantModal(false); setGrantingApp(null) }} title="Grant Patent" size="sm">
        <div className="flex flex-col gap-lg">
          <p className="font-sans text-body-sm text-steel">
            Enter the patent number for <span className="font-medium text-ink">{grantingApp?.title}</span>.
          </p>
          <Input
            id="grant-patent-number"
            label="Patent Number"
            value={grantPatentNumber}
            onChange={(e) => setGrantPatentNumber(e.target.value)}
            required
            placeholder="e.g. LK/PAT/2026/00123"
            autoFocus
          />
          <div className="flex justify-end gap-sm pt-sm">
            <Button variant="secondary" type="button" onClick={() => { setShowGrantModal(false); setGrantingApp(null) }}>
              Cancel
            </Button>
            <Button variant="primary" type="button" onClick={handleGrantConfirm} disabled={granting || !grantPatentNumber.trim()}>
              {granting ? 'Granting...' : 'Confirm Grant'}
            </Button>
          </div>
        </div>
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
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Inventors</p>
                <div className="space-y-xs">
                  {selectedApp.inventors && selectedApp.inventors.length > 0 ? selectedApp.inventors.map((inv, i) => (
                    <p key={i} className="text-body-sm text-ink font-sans">
                      {inv.inventor_name} <span className="text-steel">({inv.inventor_email})</span>
                    </p>
                  )) : <p className="text-body-sm text-steel font-sans">{'\u2014'}</p>}
                </div>
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

/* ─── Documents Tab ─── */

function DocumentsTab({ apps, user }) {
  const [selectedAppId, setSelectedAppId] = useState('')
  const [documents, setDocuments] = useState([])
  const [docsLoading, setDocsLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [uploading, setUploading] = useState(false)
  const [uploadStatus, setUploadStatus] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const fileInputRef = useRef(null)

  const [vaultLocked, setVaultLocked] = useState(true)
  const [vaultRemaining, setVaultRemaining] = useState(0)
  const [vaultUnlocking, setVaultUnlocking] = useState(false)
  const [vaultError, setVaultError] = useState('')
  const [unlockKey, setUnlockKey] = useState('')

  const isStaff = user?.role === 'admin' || user?.role === 'paralegal'
  const canUpload = isStaff
  const canDelete = canUpload

  async function checkVaultStatus() {
    try {
      const status = await api.vaultStatus()
      setVaultLocked(status.locked)
      setVaultRemaining(status.remaining_seconds || 0)
    } catch {
      setVaultLocked(true)
    }
  }

  useEffect(() => {
    checkVaultStatus()
  }, [])

  useEffect(() => {
    if (vaultLocked || vaultRemaining <= 0) return
    const interval = setInterval(() => {
      setVaultRemaining((prev) => {
        if (prev <= 1) {
          checkVaultStatus()
          return 0
        }
        return prev - 1
      })
    }, 1000)
    return () => clearInterval(interval)
  }, [vaultLocked, vaultRemaining])

  async function handleUnlock() {
    if (!unlockKey.trim()) return
    setVaultUnlocking(true)
    setVaultError('')
    try {
      await api.vaultUnlock(unlockKey.trim())
      setVaultLocked(false)
      setUnlockKey('')
      await checkVaultStatus()
    } catch (err) {
      setVaultError(err.message)
    } finally {
      setVaultUnlocking(false)
    }
  }

  async function handleLock() {
    try {
      await api.vaultLock()
      setVaultLocked(true)
      setVaultRemaining(0)
    } catch {
      // ignore
    }
  }

  useEffect(() => {
    if (apps.length > 0 && !selectedAppId) {
      setSelectedAppId(apps[0].id)
    }
  }, [apps])

  useEffect(() => {
    loadDocs()
  }, [selectedAppId])

  async function loadDocs() {
    if (!selectedAppId) {
      setDocuments([])
      return
    }
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

  async function handleUpload(e) {
    const file = e.target.files?.[0]
    if (!file || !selectedAppId) return
    setUploading(true)
    setUploadStatus(null)
    try {
      await api.uploadDocument(selectedAppId, file.name, file)
      await loadDocs()
      setUploadStatus({ type: 'success', message: `Uploaded "${file.name}"` })
    } catch (err) {
      setUploadStatus({ type: 'error', message: err.message })
    } finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  async function handleDownload(doc) {
    try {
      await api.downloadDocument(selectedAppId, doc.id)
    } catch (err) {
      alert(err.message)
    }
  }

  async function handleDelete(doc) {
    if (!confirm(`Delete "${doc.filename}"? This cannot be undone.`)) return
    setDeleting(doc.id)
    try {
      await api.deleteDocument(selectedAppId, doc.id)
      await loadDocs()
    } catch (err) {
      alert(err.message)
    } finally {
      setDeleting(null)
    }
  }

  function formatSize(bytes) {
    if (bytes === 0 || !bytes) return '\u2014'
    const units = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(1024))
    const size = (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0)
    return `${size} ${units[i]}`
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
      key: 'file_size',
      label: 'Size',
      render: (val) => (
        <span className="font-mono text-body-sm text-steel">{formatSize(val)}</span>
      ),
    },
    {
      key: 'uploaded_by',
      label: 'Uploaded By',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    {
      key: 'uploaded_at',
      label: 'Date',
      render: (val) => (
        <span className="font-mono text-body-sm text-steel">
          {val ? new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '\u2014'}
        </span>
      ),
    },
    {
      key: 'actions',
      label: '',
      render: (_, row) => (
        <div className="flex items-center justify-end gap-xs">
          <button
            type="button"
            onClick={() => handleDownload(row)}
            className="w-7 h-7 flex items-center justify-center rounded-md hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
            aria-label={`Download ${row.filename}`}
            title="Download"
          >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
              <path d="M7 10V3M4 7l3 3 3-3" />
              <path d="M2 10v2a1 1 0 001 1h8a1 1 0 001-1v-2" />
            </svg>
          </button>
          {canDelete && (
            <button
              type="button"
              onClick={() => handleDelete(row)}
              disabled={deleting === row.id}
              className="w-7 h-7 flex items-center justify-center rounded-md hover:bg-status-rejected/10 text-slate hover:text-status-rejected transition-colors duration-150 disabled:opacity-40"
              aria-label={`Delete ${row.filename}`}
              title="Delete"
            >
              {deleting === row.id ? (
                <svg className="animate-spin" width="14" height="14" viewBox="0 0 14 14" fill="none">
                  <circle cx="7" cy="7" r="5" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                  <path d="M7 2a5 5 0 013.54 1.46" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                </svg>
              ) : (
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                  <path d="M2 4h10M5 4V2.5A.5.5 0 015.5 2h3a.5.5 0 01.5.5V4M11 4v7.5a1 1 0 01-1 1H4a1 1 0 01-1-1V4" />
                  <path d="M5.5 6.5v4M8.5 6.5v4" />
                </svg>
              )}
            </button>
          )}
        </div>
      ),
    },
  ]

  function formatTime(seconds) {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = seconds % 60
    if (h > 0) return `${h}h ${m}m ${s}s`
    if (m > 0) return `${m}m ${s}s`
    return `${s}s`
  }

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
          {canUpload && selectedAppId && !vaultLocked && (
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
              {isStaff && !vaultLocked && (
                <Button variant="secondary" onClick={handleLock}>
                  Lock Vault
                </Button>
              )}
            </div>
          )}
          {isStaff && !vaultLocked && vaultRemaining > 0 && (
            <div className="flex items-center gap-sm text-body-sm text-steel font-sans">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                <circle cx="7" cy="7" r="5" />
                <path d="M7 3.5v4l3 1.5" />
              </svg>
              Session: {formatTime(vaultRemaining)}
            </div>
          )}
        </div>
        {uploadStatus && (
          <div className={`mt-md px-md py-sm rounded-md text-body-sm font-sans flex items-center gap-sm ${
            uploadStatus.type === 'success'
              ? 'bg-status-granted/10 text-status-granted'
              : 'bg-status-rejected/10 text-status-rejected'
          }`}>
            {uploadStatus.type === 'success' ? (
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                <path d="M3 7l3 3 5-5" />
              </svg>
            ) : (
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                <circle cx="7" cy="7" r="5" />
                <path d="M7 4.5v3M7 9.5v.01" />
              </svg>
            )}
            <span>{uploadStatus.message}</span>
            <button
              type="button"
              onClick={() => setUploadStatus(null)}
              className="ml-auto text-current opacity-60 hover:opacity-100"
            >
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5">
                <path d="M3 3l6 6M9 3l-6 6" />
              </svg>
            </button>
          </div>
        )}
      </Card>

      <div className="relative">
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

        {vaultLocked && selectedAppId && (
          <div className="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-navy/30 backdrop-blur-sm">
            <Card variant="base" className="w-full max-w-md p-xl text-center shadow-xl">
              <div className="flex justify-center mb-lg">
                <div className="w-14 h-14 rounded-full bg-copper-100 flex items-center justify-center">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-copper">
                    <rect x="4" y="9" width="16" height="12" rx="2" />
                    <path d="M8 9V6a4 4 0 118 0v3" />
                    <path d="M12 14v3" />
                  </svg>
                </div>
              </div>
              <h3 className="font-display text-heading-4 text-ink mb-xs">Vault Locked</h3>
              <p className="font-sans text-body-sm text-steel mb-lg">
                Enter the master key to unlock the document vault. The session stays unlocked for 12 hours.
              </p>
              <input
                type="password"
                value={unlockKey}
                onChange={(e) => setUnlockKey(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleUnlock()}
                placeholder="Master key"
                className="w-full h-10 px-md mb-sm bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
                autoFocus
              />
              {vaultError && (
                <p className="text-body-sm text-status-rejected font-sans mb-sm">{vaultError}</p>
              )}
              <Button
                variant="primary"
                className="w-full"
                onClick={handleUnlock}
                disabled={vaultUnlocking || !unlockKey.trim()}
              >
                {vaultUnlocking ? 'Unlocking...' : 'Unlock Vault'}
              </Button>
            </Card>
          </div>
        )}
      </div>
    </div>
  )
}

function FileIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10 2H4a1 1 0 00-1 1v10a1 1 0 001 1h8a1 1 0 001-1V5l-3-3z" />
      <path d="M10 2v3h3" />
    </svg>
  )
}

/* ─── Filing Workflow Tab (admin only) ─── */


