import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import Badge from '../components/ui/Badge.jsx'
import Button from '../components/ui/Button.jsx'
import Card from '../components/ui/Card.jsx'
import Skeleton from '../components/ui/Skeleton.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'

// Inventor content inside UnifiedLayout (the layout owns the header/nav).
// `section` selects which slice renders: 'overview' | 'patents' |
// 'notifications' | 'all' (default, backward compatible).
export default function UserPanel({ user, section = 'all' }) {
  const [apps, setApps] = useState([])
  const [notifications, setNotifications] = useState([])
  const [loading, setLoading] = useState(true)
  const [selectedApp, setSelectedApp] = useState(null)

  useEffect(() => {
    async function load() {
      try {
        const [appData, notifData] = await Promise.all([
          api.applications(),
          api.notifications(),
        ])
        setApps(appData || [])
        setNotifications(notifData || [])
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
      <div className="flex items-center justify-center py-xxl">
        <Skeleton rows={3} label="Loading..." />
      </div>
    )
  }

  const showOverview = section === 'all' || section === 'overview'
  const showPatents = section === 'all' || section === 'overview' || section === 'patents'
  const showNotifications = section === 'all' || section === 'overview' || section === 'notifications'

  return (
        <div className="space-y-xxl">
          {/* Welcome */}
          {showOverview && (
          <div className="animate-slide-up">
            <h1 className="font-display text-heading-1 text-ink mb-xs">Welcome back</h1>
            <p className="text-body-md text-steel font-sans">
              View your patents and notifications
            </p>
            {section === 'overview' && (
              <div className="grid grid-cols-2 gap-lg mt-lg">
                <Link to="/user/patents" className="block rounded-xl border border-hairline bg-canvas p-lg hover:border-copper transition-colors">
                  <p className="font-display text-heading-2 text-ink">{apps.length}</p>
                  <p className="text-body-sm text-steel font-sans">My Patents →</p>
                </Link>
                <Link to="/user/notifications" className="block rounded-xl border border-hairline bg-canvas p-lg hover:border-copper transition-colors">
                  <p className="font-display text-heading-2 text-ink">{notifications.length}</p>
                  <p className="text-body-sm text-steel font-sans">Notifications →</p>
                </Link>
              </div>
            )}
          </div>
          )}

          {/* My Patents */}
          {showPatents && (
          <div className="animate-slide-up stagger-1">
            <h2 className="font-display text-heading-3 text-ink mb-lg">My Patents</h2>
            {apps.length === 0 ? (
              <EmptyState
                title="No patents found."
                description="Your patents will appear here once submitted."
              />
            ) : (
              <div className="overflow-x-auto">
                <table className="doc-table">
                  <thead>
                    <tr>
                      <th>Title</th>
                      <th>Reference</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {apps.map((app) => (
                      <tr key={app.id}>
                        <td className="font-medium font-sans">{app.title}</td>
                        <td className="font-mono text-steel text-body-sm">{app.application_number || app.id?.slice(0, 8)}</td>
                        <td><Badge>{app.status || 'draft'}</Badge></td>
                        <td>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelectedApp(selectedApp?.id === app.id ? null : app)}
                          >
                            {selectedApp?.id === app.id ? 'Close' : 'View'}
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
          )}

          {/* Patent Detail (expanded inline) */}
          {showPatents && selectedApp && (
            <Card variant="base" className="animate-slide-up">
              <div className="flex items-center justify-between mb-lg">
                <h3 className="font-display text-heading-4 text-ink">Patent Details</h3>
                <Button variant="ghost" size="sm" onClick={() => setSelectedApp(null)}>
                  <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M3 3l8 8M11 3l-8 8" />
                  </svg>
                </Button>
              </div>
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
                  <p className="text-micro text-muted uppercase tracking-wider font-sans mb-xs">Technology Area</p>
                  <p className="text-body-sm text-ink font-sans">{selectedApp.technology_area || '\u2014'}</p>
                </div>
              </div>
            </Card>
          )}

          {/* Notifications */}
          {showNotifications && (
          <div className="animate-slide-up stagger-2">
            <h2 className="font-display text-heading-3 text-ink mb-lg">Notifications</h2>
            {notifications.length === 0 ? (
              <EmptyState
                title="No notifications yet."
                description="You'll receive updates about your patents here."
              />
            ) : (
              <div className="space-y-md">
                {[...notifications].reverse().map((notif) => (
                  <Card key={notif.id} variant="base">
                    <div className="flex items-start justify-between gap-lg">
                      <div className="flex-1">
                        <p className="text-body-sm-medium text-ink font-sans mb-xs">{notif.subject}</p>
                        <p className="text-body-sm text-steel font-sans">{notif.body}</p>
                      </div>
                      <span className="text-caption text-muted font-sans whitespace-nowrap">
                        {notif.sent_at ? new Date(notif.sent_at).toLocaleDateString('en-US', { 
                          year: 'numeric', 
                          month: 'short', 
                          day: 'numeric',
                          hour: '2-digit',
                          minute: '2-digit'
                        }) : ''}
                      </span>
                    </div>
                  </Card>
                ))}
              </div>
            )}
          </div>
          )}
        </div>
  )
}
