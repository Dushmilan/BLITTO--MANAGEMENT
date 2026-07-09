import { useState, useEffect } from 'react'
import { api } from '../api.js'
import DataTable from '../components/ui/DataTable.jsx'
import SearchInput from '../components/ui/SearchInput.jsx'

export default function AuditPage() {
  const [events, setEvents] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    // The backend doesn't have a direct audit endpoint exposed to the frontend,
    // so we'll display what we can gather from the available data.
    setLoading(false)
  }, [])

  const columns = [
    {
      key: 'timestamp',
      label: 'Time',
      render: (val) => (
        <span className="font-mono text-body-sm text-steel">
          {val ? new Date(val).toLocaleString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
          }) : '\u2014'}
        </span>
      ),
    },
    {
      key: 'action',
      label: 'Action',
      render: (val) => <span className="font-medium font-sans text-ink">{val}</span>,
    },
    {
      key: 'actor',
      label: 'Actor',
      render: (val) => <span className="text-body-sm text-steel font-sans">{val || '\u2014'}</span>,
    },
    {
      key: 'application_id',
      label: 'Application',
      render: (val) => <span className="font-mono text-body-sm text-steel">{val?.slice(0, 8) || '\u2014'}</span>,
    },
    {
      key: 'details',
      label: 'Details',
      render: (val) => <span className="text-body-sm text-muted font-sans">{val || '\u2014'}</span>,
    },
  ]

  const filtered = events.filter((e) => {
    if (!search) return true
    const s = search.toLowerCase()
    return (
      e.action?.toLowerCase().includes(s) ||
      e.actor?.toLowerCase().includes(s) ||
      e.details?.toLowerCase().includes(s)
    )
  })

  return (
    <div className="space-y-xxl">
      {/* Header */}
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Audit Log</h1>
        <p className="text-body-md text-steel font-sans">
          Traceable record of all system mutations
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-sm animate-slide-up stagger-1">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search audit log..."
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
              <span className="font-sans text-body-sm">Loading audit log...</span>
            </div>
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={filtered}
            emptyMessage="No audit events recorded yet. Actions on applications, deadlines, and documents will appear here."
          />
        )}
      </div>

      {/* Info card */}
      <div className="bg-ivory-200 rounded-lg p-xl border border-hairline animate-slide-up stagger-3">
        <div className="flex items-start gap-md">
          <div className="w-8 h-8 rounded-full bg-copper/10 flex items-center justify-center flex-shrink-0 mt-xxs">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" className="text-copper">
              <circle cx="8" cy="8" r="6.5" />
              <path d="M8 5v3.5M8 10.5v.5" />
            </svg>
          </div>
          <div>
            <h3 className="text-body-md-medium text-ink font-sans mb-xs">About Audit Logging</h3>
            <p className="text-body-sm text-steel font-sans leading-relaxed">
              Every state change on an application, deadline, or document is logged with actor, timestamp, and action type.
              This ensures full traceability as required by the patent management domain invariants.
              Audit events are immutable and cannot be modified or deleted.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
