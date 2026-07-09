import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import StatCard from '../components/ui/StatCard.jsx'
import Card from '../components/ui/Card.jsx'
import Badge from '../components/ui/Badge.jsx'
import Timeline from '../components/ui/Timeline.jsx'

export default function DashboardPage() {
  const [apps, setApps] = useState([])
  const [deadlines, setDeadlines] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    async function load() {
      try {
        const appData = await api.applications()
        setApps(appData || [])
      } catch (err) {
        setError(err.message)
      }
      try {
        const deadlineData = await api.deadlines()
        setDeadlines(deadlineData || [])
      } catch {
        // deadlines endpoint may require staff role
      }
      setLoading(false)
    }
    load()
  }, [])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-section">
        <div className="flex items-center gap-sm text-steel">
          <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
            <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
          <span className="font-sans text-body-sm">Loading dashboard...</span>
        </div>
      </div>
    )
  }

  const totalApps = apps.length
  const statusCounts = apps.reduce((acc, app) => {
    const s = (app.status || 'draft').toLowerCase()
    acc[s] = (acc[s] || 0) + 1
    return acc
  }, {})

  const urgentDeadlines = deadlines
    .filter((d) => {
      if (!d.due_date) return false
      const diff = new Date(d.due_date) - new Date()
      return diff >= 0 && diff < 30 * 24 * 60 * 60 * 1000
    })
    .slice(0, 5)

  const recentApps = apps.slice(-5).reverse()

  const timelineItems = recentApps.map((app) => ({
    id: app.id,
    text: `Application "${app.title}" ${app.status ? `is now ${app.status}` : 'created'}`,
    time: app.created_at ? new Date(app.created_at).toLocaleDateString() : 'Recently',
  }))

  return (
    <div className="space-y-xxl">
      <div>
        <h1 className="font-display text-heading-1 text-ink mb-xs">Dashboard</h1>
        <p className="text-body-md text-steel font-sans">
          Portfolio overview and recent activity
        </p>
      </div>

      {error && (
        <div className="rounded-lg p-md" style={{ backgroundColor: 'rgba(220,38,38,0.1)', border: '1px solid rgba(220,38,38,0.2)' }}>
          <p className="text-body-sm font-sans" style={{ color: '#dc2626' }}>{error}</p>
        </div>
      )}

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-lg">
        <StatCard label="Total" value={totalApps}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><rect x="3" y="2" width="14" height="16" rx="2" /><path d="M7 7h6M7 10h6M7 13h3" /></svg>}
        />
        <StatCard label="Filed" value={statusCounts.filed || statusCounts.published || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M5 10l3 3 7-7" /><circle cx="10" cy="10" r="8" /></svg>}
        />
        <StatCard label="Examination" value={statusCounts.examination || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="10" cy="10" r="8" /><path d="M10 6v4l2.5 1.5" /></svg>}
        />
        <StatCard label="Granted" value={statusCounts.granted || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M10 2l2.5 5 5.5.8-4 3.9.9 5.3L10 14.5 5.1 17l.9-5.3-4-3.9 5.5-.8z" /></svg>}
        />
        <StatCard label="Draft" value={statusCounts.draft || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M12 2H5a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7l-5-5z" /><path d="M11 2v5h5" /></svg>}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-xl">
        <Card variant="base">
          <div className="flex items-center justify-between mb-lg">
            <h2 className="font-display text-heading-4 text-ink">Upcoming Deadlines</h2>
            <Link to="/docket" className="text-body-sm-medium text-copper hover:text-copper-700 transition-colors font-sans">View all</Link>
          </div>
          {urgentDeadlines.length === 0 ? (
            <p className="text-body-sm text-steel font-sans py-lg">No urgent deadlines.</p>
          ) : (
            <ul className="space-y-sm">
              {urgentDeadlines.map((d, i) => {
                const daysLeft = Math.ceil((new Date(d.due_date) - new Date()) / (1000 * 60 * 60 * 24))
                return (
                  <li key={d.id || i} className="flex items-center justify-between py-sm border-b last:border-0" style={{ borderColor: '#f0ede8' }}>
                    <div>
                      <p className="text-body-sm-medium text-ink font-sans">{d.deadline_type || d.type || 'Deadline'}</p>
                      <p className="text-caption text-muted font-sans">{d.application_id}</p>
                    </div>
                    <span className="text-caption-bold font-mono px-xs py-xxs rounded" style={{ backgroundColor: daysLeft <= 7 ? 'rgba(220,38,38,0.1)' : daysLeft <= 14 ? 'rgba(217,119,6,0.1)' : '#f3f0ec', color: daysLeft <= 7 ? '#dc2626' : daysLeft <= 14 ? '#d97706' : '#64748b' }}>
                      {daysLeft}d
                    </span>
                  </li>
                )
              })}
            </ul>
          )}
        </Card>

        <Card variant="base" className="lg:col-span-2">
          <h2 className="font-display text-heading-4 text-ink mb-lg">Recent Activity</h2>
          <Timeline items={timelineItems} />
        </Card>
      </div>

      <Card variant="base">
        <div className="flex items-center justify-between mb-lg">
          <h2 className="font-display text-heading-4 text-ink">Recent Applications</h2>
          <Link to="/applications" className="text-body-sm-medium text-copper hover:text-copper-700 transition-colors font-sans">View all</Link>
        </div>
        {recentApps.length === 0 ? (
          <p className="text-body-sm text-steel font-sans py-lg">No applications yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="doc-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Reference</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {recentApps.map((app) => (
                  <tr key={app.id}>
                    <td className="font-medium font-sans">{app.title}</td>
                    <td className="font-mono text-steel text-body-sm">{app.application_number || app.id?.slice(0, 8)}</td>
                    <td><Badge>{app.status || 'draft'}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
