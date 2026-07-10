import { useState, useEffect } from 'react'
import { api } from '../api.js'
import Badge from '../components/ui/Badge.jsx'
import Button from '../components/ui/Button.jsx'
import Card from '../components/ui/Card.jsx'
import Avatar from '../components/ui/Avatar.jsx'

export default function UserPanel({ user, onLogout }) {
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

  function handleLogout() {
    onLogout()
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-ivory flex items-center justify-center">
        <div className="flex items-center gap-sm text-steel">
          <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
            <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
          <span className="font-sans text-body-sm">Loading...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-ivory">
      {/* Header */}
      <header className="sticky top-0 z-30 bg-ivory/80 backdrop-blur-md border-b border-hairline-soft">
        <div className="px-xl py-md flex items-center justify-between max-w-6xl mx-auto">
          <div className="flex items-center gap-md">
            <span className="font-display text-heading-4 text-navy tracking-tight">BLITTO</span>
            <span className="text-micro text-muted uppercase tracking-widest font-sans hidden sm:inline">
              Patent Management
            </span>
          </div>
          <div className="flex items-center gap-lg">
            <div className="flex items-center gap-sm text-right">
              <Avatar name={user?.email} size="sm" />
              <div className="hidden sm:block">
                <p className="text-body-sm-medium text-ink font-sans leading-tight">{user?.email}</p>
                <p className="text-micro text-status-granted font-sans capitalize">{user?.role}</p>
              </div>
            </div>
            <Button variant="ghost" size="sm" onClick={handleLogout}>
              Sign out
            </Button>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="px-xl py-section-sm max-w-6xl mx-auto">
        <div className="space-y-xxl">
          {/* Welcome */}
          <div className="animate-slide-up">
            <h1 className="font-display text-heading-1 text-ink mb-xs">Welcome back</h1>
            <p className="text-body-md text-steel font-sans">
              View your patents and notifications
            </p>
          </div>

          {/* My Patents */}
          <div className="animate-slide-up stagger-1">
            <h2 className="font-display text-heading-3 text-ink mb-lg">My Patents</h2>
            {apps.length === 0 ? (
              <Card variant="base">
                <p className="text-body-sm text-steel font-sans py-lg text-center">
                  No patents found. Your patents will appear here once submitted.
                </p>
              </Card>
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

          {/* Patent Detail (expanded inline) */}
          {selectedApp && (
            <Card variant="base" className="animate-slide-up">
              <div className="flex items-center justify-between mb-lg">
                <h3 className="font-display text-heading-4 text-ink">Patent Details</h3>
                <Button variant="ghost" size="sm" onClick={() => setSelectedApp(null)}>
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="2">
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
          <div className="animate-slide-up stagger-2">
            <h2 className="font-display text-heading-3 text-ink mb-lg">Notifications</h2>
            {notifications.length === 0 ? (
              <Card variant="base">
                <p className="text-body-sm text-steel font-sans py-lg text-center">
                  No notifications yet. You'll receive updates about your patents here.
                </p>
              </Card>
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
        </div>
      </main>
    </div>
  )
}
