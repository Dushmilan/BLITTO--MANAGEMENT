import { useState, useEffect, useRef } from 'react'
import { api } from '../api.js'
import Card from '../components/ui/Card.jsx'
import Button from '../components/ui/Button.jsx'
import DataTable from '../components/ui/DataTable.jsx'
import SearchInput from '../components/ui/SearchInput.jsx'

export default function DocumentsPage({ user }) {
  const [apps, setApps] = useState([])
  const [selectedAppId, setSelectedAppId] = useState('')
  const [documents, setDocuments] = useState([])
  const [loading, setLoading] = useState(true)
  const [docsLoading, setDocsLoading] = useState(false)
  const [search, setSearch] = useState('')
  const [uploading, setUploading] = useState(false)
  const fileInputRef = useRef(null)

  const canUpload = user?.role === 'admin' || user?.role === 'paralegal'

  useEffect(() => {
    async function load() {
      try {
        const data = await api.applications()
        setApps(data || [])
        if (data?.length > 0) {
          setSelectedAppId(data[0].id)
        }
      } catch {
        // ignore
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [])

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

  if (loading) {
    return (
      <div className="flex items-center justify-center py-section">
        <div className="flex items-center gap-sm text-steel">
          <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
            <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
          <span className="font-sans text-body-sm">Loading documents...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-xxl">
      {/* Header */}
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Documents</h1>
        <p className="text-body-md text-steel font-sans">
          Document vault for patent application artifacts
        </p>
      </div>

      {/* Application selector */}
      <Card variant="base" className="animate-slide-up stagger-1">
        <div className="flex flex-col sm:flex-row sm:items-center gap-lg">
          <div className="flex-1">
            <label className="block text-micro text-muted uppercase tracking-wider font-sans mb-xs">
              Select Application
            </label>
            <select
              value={selectedAppId}
              onChange={(e) => setSelectedAppId(e.target.value)}
              className="w-full h-10 px-md bg-canvas text-ink text-body-md border border-hairline rounded-md outline-none font-sans cursor-pointer focus:border-copper focus:ring-2 focus:ring-copper-100 transition-all duration-200"
            >
              <option value="">Choose an application</option>
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

      {/* Documents table */}
      {selectedAppId && (
        <div className="animate-slide-up stagger-2">
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
