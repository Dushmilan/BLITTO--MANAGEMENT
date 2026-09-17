import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import UserPanel from '../UserPanel.jsx'

vi.mock('../../api.js', () => ({
  api: { applications: vi.fn(), notifications: vi.fn() },
}))

import { api } from '../../api.js'

function renderPage(user, onLogout = vi.fn()) {
  return render(
    <MemoryRouter>
      <UserPanel user={user} onLogout={onLogout} />
    </MemoryRouter>
  )
}

const APPS = [
  { id: '1', title: 'My Patent', status: 'filed', application_number: 'PAT-001', inventors: [{ inventor_name: 'Me' }], technology_area: 'AI' },
  { id: '2', title: 'My Second', status: 'draft' },
]
const NOTIFS = [
  { id: 'n1', subject: 'Status Update', body: 'Your patent has been filed', sent_at: '2024-06-01T12:00:00Z' },
]

const USER = { email: 'inventor@test.com', role: 'inventor' }

describe('UserPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading then renders welcome message', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    renderPage(USER)
    expect(screen.getByText('Loading...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('Welcome back')).toBeInTheDocument()
    })
  })

  it('renders my patents table', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    renderPage(USER)
    await waitFor(() => {
      expect(screen.getByText('My Patent')).toBeInTheDocument()
    })
    expect(screen.getByText('My Second')).toBeInTheDocument()
  })

  it('shows empty state when no patents', async () => {
    api.applications.mockResolvedValue([])
    api.notifications.mockResolvedValue(NOTIFS)
    renderPage(USER)
    await waitFor(() => {
      expect(screen.getByText(/No patents found/)).toBeInTheDocument()
    })
  })

  it('expands patent detail on view', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    renderPage(USER)
    await waitFor(() => {
      expect(screen.getByText('My Patent')).toBeInTheDocument()
    })
    await userEvent.click(screen.getAllByText('View')[0])
    expect(screen.getByText('Patent Details')).toBeInTheDocument()
    expect(screen.getByText('AI')).toBeInTheDocument()
  })

  it('renders notifications list', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    renderPage(USER)
    await waitFor(() => {
      expect(screen.getByText('Status Update')).toBeInTheDocument()
    })
    expect(screen.getByText('Your patent has been filed')).toBeInTheDocument()
  })

  it('shows empty state when no notifications', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue([])
    renderPage(USER)
    await waitFor(() => {
      expect(screen.getByText(/No notifications yet/)).toBeInTheDocument()
    })
  })

  it('shows section slices via the section prop', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    render(
      <MemoryRouter>
        <UserPanel user={USER} section="notifications" />
      </MemoryRouter>
    )
    await waitFor(() => {
      expect(screen.getByText('Status Update')).toBeInTheDocument()
    })
    expect(screen.queryByText('My Patent')).not.toBeInTheDocument()
    expect(screen.queryByText('Welcome back')).not.toBeInTheDocument()
  })

  it('overview section links to patents and notifications', async () => {
    api.applications.mockResolvedValue(APPS)
    api.notifications.mockResolvedValue(NOTIFS)
    render(
      <MemoryRouter>
        <UserPanel user={USER} section="overview" />
      </MemoryRouter>
    )
    await waitFor(() => {
      expect(screen.getByText('Welcome back')).toBeInTheDocument()
    })
    expect(screen.getByText(/My Patents →/)).toBeInTheDocument()
    expect(screen.getByText(/Notifications →/)).toBeInTheDocument()
  })
})
