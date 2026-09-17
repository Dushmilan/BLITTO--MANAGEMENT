import { useState, useEffect, useRef, useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../api.js'
import Badge from '../components/ui/Badge.jsx'
import Button from '../components/ui/Button.jsx'
import Card from '../components/ui/Card.jsx'
import DataTable from '../components/ui/DataTable.jsx'

import Dropdown from '../components/ui/Dropdown.jsx'
import SearchInput from '../components/ui/SearchInput.jsx'
import Modal from '../components/ui/Modal.jsx'
import ConfirmDialog from '../components/ui/ConfirmDialog.jsx'
import Input from '../components/ui/Input.jsx'
import { useToast } from '../hooks/useToast.js'
import { STATUS_OPTIONS, inventorSummary, normalizeInventors } from '../utils/notifyHelpers.js'

const TABS = [
  { id: 'list', label: 'Patents' },
  { id: 'documents', label: 'Documents' },
]

export default function PatentsPage({ user }) {
  // Tab state lives in the URL (?tab=documents) so navigation and
  // back/forward preserve the workflow; unknown values fall back to 'list'.
  const [searchParams, setSearchParams] = useSearchParams()
  const tabFromUrl = searchParams.get('tab')
  const [activeTab, setActiveTab] = useState(
    TABS.some((t) => t.id === tabFromUrl) ? tabFromUrl : 'list'
  )
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)
  const [docAppId, setDocAppId] = useState('')
  const isAdmin = user?.role === 'admin'
  const visibleTabs = TABS.filter((t) => !t.adminOnly || isAdmin)

  function selectTab(id) {
    setActiveTab(id)
    setSearchParams(id === 'list' ? {} : { tab: id })
  }

  function goToDocuments(appId) {
    setDocAppId(appId)
    selectTab('documents')
  }

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
              onClick={() => selectTab(tab.id)}
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
        <PatentsTab apps={apps} loading={loading} onReload={loadApps} user={user} onViewDocuments={goToDocuments} />
      )}
      {activeTab === 'documents' && (
        <DocumentsTab apps={apps} user={user} initialAppId={docAppId} />
      )}
    </div>
  )
}

/* ─── Patents List Tab ─── */

function PatentsTab({ apps, loading, onReload, user, onViewDocuments }) {
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
  const [pendingConfirm, setPendingConfirm] = useState(null)
  const toast = useToast()
  const [searchParams] = useSearchParams()
  const [editingTech, setEditingTech] = useState(false)
  const [techDraft, setTechDraft] = useState('')
  const [savingTech, setSavingTech] = useState(false)
  const [history, setHistory] = useState([])

  // Deep-link (?focus=<id>) opens the patent detail modal once data arrives.
  useEffect(() => {
    const focusId = searchParams.get('focus')
    if (focusId && apps.length > 0) {
      const match = apps.find((a) => a.id === focusId)
      if (match) setSelectedApp(match)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [apps])

  // Reset the detail editor and load status history whenever selection changes.
  useEffect(() => {
    setEditingTech(false)
    setHistory([])
    if (!selectedApp) return
    setTechDraft(selectedApp.technology_area || '')
    let cancelled = false
    api.applicationHistory(selectedApp.id)
      .then((h) => { if (!cancelled) setHistory(h || []) })
      .catch(() => { if (!cancelled) setHistory([]) })
    return () => { cancelled = true }
  }, [selectedApp])

  async function handleTechSave() {
    if (!selectedApp) return
    setSavingTech(true)
    try {
      const updated = await api.updateApplication(selectedApp.id, { technology_area: techDraft })
      setSelectedApp(updated)
      setEditingTech(false)
      toast.success('Technology area updated')
      await onReload()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSavingTech(false)
    }
  }

  function goToDocuments(appId) {
    setSelectedApp(null)
    onViewDocuments?.(appId)
  }

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
      toast.success('Patent application created')
      await onReload()
    } catch (err) {
      toast.error(err.message)
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
        setPendingConfirm({ kind: 'reject', app })
        return
      }
      await onReload()
    } catch (err) {
      toast.error(err.message)
    }
  }

  async function handleGrantConfirm() {
    if (!grantingApp || !grantPatentNumber.trim()) return
    // Step 1 done (number captured) — step 2 is the explicit confirmation.
    setPendingConfirm({ kind: 'grant', app: grantingApp, number: grantPatentNumber.trim() })
    setShowGrantModal(false)
  }

  // Runs after the ConfirmDialog is accepted (grant/reject live here;
  // document deletes confirm inside DocumentsTab, which owns that state).
  async function handlePendingConfirm() {
    const pending = pendingConfirm
    setPendingConfirm(null)
    if (!pending) return
    setGranting(true)
    try {
      if (pending.kind === 'grant') {
        await api.markGranted(pending.app.id, pending.number)
        setGrantingApp(null)
        setGrantPatentNumber('')
        toast.success('Patent granted')
      } else if (pending.kind === 'reject') {
        await api.markRejected(pending.app.id)
        toast.success('Patent rejected')
      }
      await onReload()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setGranting(false)
    }
  }

  function pendingDialogProps() {
    if (!pendingConfirm) return null
    if (pendingConfirm.kind === 'grant') {
      return {
        title: 'Confirm grant',
        message: `Grant "${pendingConfirm.app.title}" (currently ${pendingConfirm.app.status}) with patent number ${pendingConfirm.number}? This action is irreversible.`,
        confirmLabel: 'Grant Patent',
        requireConfirm: 'I understand granting is permanent and irreversible',
      }
    }
    return {
      title: 'Reject patent?',
      message: `Reject "${pendingConfirm.app.title}"? This action is irreversible.`,
      confirmLabel: 'Reject',
      danger: true,
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
        const { first, extra } = inventorSummary(val)
        if (!first) return <span className="text-body-sm text-steel font-sans">{'\u2014'}</span>
        return (
          <span className="text-body-sm text-steel font-sans">
            {first}{extra > 0 && <span className="text-muted"> +{extra} more</span>}
          </span>
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
                    className="touch-target w-8 h-8 flex items-center justify-center rounded-full hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
                    aria-label="Filing actions"
                  >
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
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
          <svg aria-hidden="true" className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
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
            <div className="flex items-center gap-sm text-steel" role="status" aria-live="polite">
              <svg aria-hidden="true" className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
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
                  className="touch-target mt-0.5 w-7 h-7 flex items-center justify-center rounded-md hover:bg-status-rejected/10 text-slate hover:text-status-rejected transition-colors duration-150 shrink-0"
                  aria-label="Remove inventor"
                >
                  <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
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
              <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5">
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
                  {normalizeInventors(selectedApp.inventors).length > 0 ? normalizeInventors(selectedApp.inventors).map((inv, i) => (
                    <p key={i} className="text-body-sm text-ink font-sans">
                      {inv.inventor_name} <span className="text-steel">({inv.inventor_email})</span>
                    </p>
                  )) : <p className="text-body-sm text-steel font-sans">{'\u2014'}</p>}
                </div>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Technology Area</p>
                {editingTech ? (
                  <div className="flex items-center gap-xs">
                    <Input
                      value={techDraft}
                      onChange={(e) => setTechDraft(e.target.value)}
                      placeholder="e.g. Electrical Engineering"
                      aria-label="Technology area"
                    />
                    <Button variant="primary" size="sm" onClick={handleTechSave} disabled={savingTech}>
                      {savingTech ? 'Saving...' : 'Save'}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setEditingTech(false)}>
                      Cancel
                    </Button>
                  </div>
                ) : (
                  <p className="text-body-sm text-ink font-sans">
                    {selectedApp.technology_area || '—'}
                    <button
                      type="button"
                      onClick={() => setEditingTech(true)}
                      aria-label="Edit technology area"
                      className="touch-target ml-xs text-copper hover:text-copper-700 text-body-sm font-sans"
                    >
                      Edit
                    </button>
                  </p>
                )}
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Created</p>
                <p className="text-body-sm text-ink font-sans">
                  {selectedApp.created_at ? new Date(selectedApp.created_at).toLocaleString() : '—'}
                </p>
              </div>
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Last updated</p>
                <p className="text-body-sm text-ink font-sans">
                  {selectedApp.updated_at ? new Date(selectedApp.updated_at).toLocaleString() : '—'}
                </p>
              </div>
            </div>

            {/* Status history */}
            {history.length > 0 && (
              <div>
                <p className="text-micro text-muted uppercase tracking-wider font-sans mb-sm">Status history</p>
                <ul className="space-y-xs">
                  {history.map((h) => (
                    <li key={h.id} className="text-body-sm text-steel font-sans">
                      {h.old_status} → <span className="text-ink font-medium">{h.new_status}</span>
                      <span className="text-muted"> by {h.changed_by}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            <div className="flex flex-wrap gap-sm">
              <Button variant="secondary" size="sm" onClick={() => goToDocuments(selectedApp.id)}>
                View documents
              </Button>
            </div>

            {/* Critical filing actions stay visible — no three-dot hunt.
                Grant/Reject are one-way; Reject still confirms (see #26). */}
            {['filed', 'acknowledged', 'examination', 'defect_sheet_1', 'defect_sheet_2', 'defect_sheet_3'].includes(selectedApp.status?.toLowerCase()) && (
              <div className="flex flex-wrap gap-sm pt-md border-t border-hairline" aria-label="Filing decision">
                <Button variant="primary" size="sm" onClick={() => handleFilingAction(selectedApp, 'grant')}>
                  Grant Patent
                </Button>
                <Button variant="danger" size="sm" onClick={() => handleFilingAction(selectedApp, 'reject')}>
                  Reject
                </Button>
              </div>
            )}
          </div>
        )}
      </Modal>

      {/* Destructive/irreversible confirmations (issues #26, #28) */}
      {pendingConfirm && (
        <ConfirmDialog
          open
          {...pendingDialogProps()}
          onConfirm={handlePendingConfirm}
          onCancel={() => setPendingConfirm(null)}
        />
      )}
    </div>
  )
}

/* ─── Documents Tab ─── */

function DocumentsTab({ apps, user, initialAppId = '' }) {
  const [selectedAppId, setSelectedAppId] = useState(initialAppId)

  // Follow deep-links from the patent detail modal ("View documents").
  useEffect(() => {
    if (initialAppId) setSelectedAppId(initialAppId)
  }, [initialAppId])
  const [documents, setDocuments] = useState([])
  const [docsLoading, setDocsLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [uploading, setUploading] = useState(false)
  const [uploadStatus, setUploadStatus] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [pendingDelete, setPendingDelete] = useState(null)
  const fileInputRef = useRef(null)
  const toast = useToast()

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
      toast.error(err.message)
    }
  }

  async function handleDelete(doc) {
    setPendingDelete(doc)
  }

  async function handleDeleteConfirm() {
    const doc = pendingDelete
    setPendingDelete(null)
    if (!doc) return
    setDeleting(doc.id)
    try {
      await api.deleteDocument(selectedAppId, doc.id)
      await loadDocs()
      toast.success('Document deleted')
    } catch (err) {
      toast.error(err.message)
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
            className="touch-target w-7 h-7 flex items-center justify-center rounded-md hover:bg-ivory-200 text-slate hover:text-ink transition-colors duration-150"
            aria-label={`Download ${row.filename}`}
            title="Download"
          >
            <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
              <path d="M7 10V3M4 7l3 3 3-3" />
              <path d="M2 10v2a1 1 0 001 1h8a1 1 0 001-1v-2" />
            </svg>
          </button>
          {canDelete && (
            <button
              type="button"
              onClick={() => handleDelete(row)}
              disabled={deleting === row.id}
              className="touch-target w-7 h-7 flex items-center justify-center rounded-md hover:bg-status-rejected/10 text-slate hover:text-status-rejected transition-colors duration-150 disabled:opacity-40"
              aria-label={`Delete ${row.filename}`}
              title="Delete"
            >
              {deleting === row.id ? (
                <svg aria-hidden="true" className="animate-spin" width="14" height="14" viewBox="0 0 14 14" fill="none">
                  <circle cx="7" cy="7" r="5" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                  <path d="M7 2a5 5 0 013.54 1.46" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                </svg>
              ) : (
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
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
                    <svg aria-hidden="true" className="animate-spin" width="14" height="14" viewBox="0 0 14 14" fill="none">
                      <circle cx="7" cy="7" r="5" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                      <path d="M7 2a5 5 0 013.54 1.46" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                    </svg>
                    Uploading...
                  </span>
                ) : (
                  <>
                    <svg aria-hidden="true" className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
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
              <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                <circle cx="7" cy="7" r="5" />
                <path d="M7 3.5v4l3 1.5" />
              </svg>
              Session: {formatTime(vaultRemaining)}
            </div>
          )}
        </div>
        {/* Selected-patent context bar (issue #30) */}
        {selectedAppId && (() => {
          const selected = apps.find((a) => a.id === selectedAppId)
          if (!selected) return null
          return (
            <div className="mt-md flex flex-wrap items-center gap-x-md gap-y-xs rounded-md bg-ivory-200 px-md py-sm" data-testid="doc-context-bar">
              <span className="text-body-sm-medium text-ink font-sans">{selected.title}</span>
              <Badge>{selected.status || 'draft'}</Badge>
              <span className="text-body-sm text-steel font-mono">
                {(selected.application_number || selected.id)?.slice(0, 12)}
              </span>
              {selected.technology_area && (
                <span className="text-body-sm text-steel font-sans">{selected.technology_area}</span>
              )}
            </div>
          )
        })()}
        {uploadStatus && (
          <div aria-live="polite" className={`mt-md px-md py-sm rounded-md text-body-sm font-sans flex items-center gap-sm ${
            uploadStatus.type === 'success'
              ? 'bg-status-granted/10 text-status-granted'
              : 'bg-status-rejected/10 text-status-rejected'
          }`}>
            {uploadStatus.type === 'success' ? (
              <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
                <path d="M3 7l3 3 5-5" />
              </svg>
            ) : (
              <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
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
              <svg aria-hidden="true" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5">
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
                <div className="flex items-center gap-sm text-steel" role="status" aria-live="polite">
                  <svg aria-hidden="true" className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
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
                  <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-copper">
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

      {/* Document delete confirmation (issue #26) */}
      {pendingDelete && (
        <ConfirmDialog
          open
          title="Delete document?"
          message={`Delete "${pendingDelete.filename}"? This cannot be undone.`}
          confirmLabel="Delete"
          danger
          onConfirm={handleDeleteConfirm}
          onCancel={() => setPendingDelete(null)}
        />
      )}
    </div>
  )
}

function FileIcon() {
  return (
    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10 2H4a1 1 0 00-1 1v10a1 1 0 001 1h8a1 1 0 001-1V5l-3-3z" />
      <path d="M10 2v3h3" />
    </svg>
  )
}

/* ─── Filing Workflow Tab (admin only) ─── */


