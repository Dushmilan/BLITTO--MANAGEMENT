import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api.js'
import StatCard from '../../components/ui/StatCard.jsx'
import Card from '../../components/ui/Card.jsx'
import Badge from '../../components/ui/Badge.jsx'

export default function AdminDashboard() {
  const [apps, setApps] = useState([])
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    async function load() {
      try {
        const [appData, userData] = await Promise.all([
          api.applications(),
          api.users(),
        ])
        setApps(appData || [])
        setUsers(userData || [])
      } catch (err) {
        console.error(err)
      } finally {
        setLoading(false)
      }
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
  const totalUsers = users.length
  const inventors = users.filter(u => u.role === 'inventor').length
  const statusCounts = apps.reduce((acc, app) => {
    const s = (app.status || 'draft').toLowerCase()
    acc[s] = (acc[s] || 0) + 1
    return acc
  }, {})

  const recentApps = apps.slice(-5).reverse()

  return (
    <div className="space-y-xxl">
      <div>
        <h1 className="font-display text-heading-1 text-ink mb-xs">Admin Dashboard</h1>
        <p className="text-body-md text-steel font-sans">
          Overview of the patent management system
        </p>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-lg">
        <StatCard label="Total Patents" value={totalApps}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><rect x="3" y="2" width="14" height="16" rx="2" /><path d="M7 7h6M7 10h6M7 13h3" /></svg>}
        />
        <StatCard label="Total Users" value={totalUsers}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="10" cy="7" r="3" /><path d="M4 18c0-3.3 2.7-6 6-6s6 2.7 6 6" /></svg>}
        />
        <StatCard label="Inventors" value={inventors}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><circle cx="10" cy="7" r="3" /><path d="M4 18c0-3.3 2.7-6 6-6s6 2.7 6 6" /></svg>}
        />
        <StatCard label="Filed" value={statusCounts.filed || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M5 10l3 3 7-7" /><circle cx="10" cy="10" r="8" /></svg>}
        />
        <StatCard label="Granted" value={statusCounts.granted || 0}
          icon={<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M10 2l2.5 5 5.5.8-4 3.9.9 5.3L10 14.5 5.1 17l.9-5.3-4-3.9 5.5-.8z" /></svg>}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-xl">
        <Card variant="base">
          <div className="flex items-center justify-between mb-lg">
            <h2 className="font-display text-heading-4 text-ink">Recent Applications</h2>
            <Link to="/admin/patents" className="text-body-sm-medium text-copper hover:text-copper-700 transition-colors font-sans">View all</Link>
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

        <Card variant="base">
          <div className="flex items-center justify-between mb-lg">
            <h2 className="font-display text-heading-4 text-ink">Recent Users</h2>
            <Link to="/admin/users" className="text-body-sm-medium text-copper hover:text-copper-700 transition-colors font-sans">View all</Link>
          </div>
          {users.length === 0 ? (
            <p className="text-body-sm text-steel font-sans py-lg">No users yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="doc-table">
                <thead>
                  <tr>
                    <th>Email</th>
                    <th>Role</th>
                  </tr>
                </thead>
                <tbody>
                  {users.slice(0, 5).map((u) => (
                    <tr key={u.id}>
                      <td className="font-medium font-sans">{u.email}</td>
                      <td><Badge>{u.role}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}
