import { useState, useEffect } from 'react'
import { api } from '../api.js'
import Card from '../components/ui/Card.jsx'
import Button from '../components/ui/Button.jsx'
import DataTable from '../components/ui/DataTable.jsx'
import SearchInput from '../components/ui/SearchInput.jsx'
import Modal from '../components/ui/Modal.jsx'
import Input from '../components/ui/Input.jsx'

const DEADLINE_TYPES = [
  'filing_deadline',
  'response_deadline',
  'maintenance_fee',
  'appeal_deadline',
  'IDS_deadline',
  'continuation_deadline',
]

export default function DocketPage() {
  const [deadlines, setDeadlines] = useState([])
  const [apps, setApps] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [showAddModal, setShowAddModal] = useState(false)
  const [addForm, setAddForm] = useState({ application_id: '', type: 'filing_deadline', due_date: '' })
  const [adding, setAdding] = useState(false)

  useEffect(() => {
    loadData()
  }, [])

  async function loadData() {
    setLoading(true)
    try {
      const [deadlinesData, appsData] = await Promise.allSettled([
        api.deadlines(),
        api.applications(),
      ])
      if (deadlinesData.status === 'fulfilled') setDeadlines(deadlinesData.value || [])
      if (appsData.status === 'fulfilled') setApps(appsData.value || [])
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
      await loadData()
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
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-lg animate-slide-up">
        <div>
          <h1 className="font-display text-heading-1 text-ink mb-xs">Docket</h1>
          <p className="text-body-md text-steel font-sans">
            {deadlines.length} deadline{deadlines.length !== 1 ? 's' : ''} tracked
          </p>
        </div>
        <Button variant="primary" onClick={() => setShowAddModal(true)}>
          <svg className="mr-xs" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M7 2v10M2 7h10" />
          </svg>
          Add Deadline
        </Button>
      </div>

      {/* Filters */}
      <div className="animate-slide-up stagger-1">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search deadlines..."
          className="max-w-[400px]"
        />
      </div>

      {/* Table */}
      <div className="animate-slide-up stagger-2">
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
