import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

// Integration: real App + router + layout + pages, only the API mocked.
vi.mock('../../api.js', () => ({
  api: {
    login: vi.fn(),
    me: vi.fn(),
    applications: vi.fn(),
    users: vi.fn(),
    notifications: vi.fn(),
    createApplication: vi.fn(),
    markFiled: vi.fn(),
    acknowledgeNipo: vi.fn(),
    recordDefectSheet: vi.fn(),
    markGranted: vi.fn(),
    markRejected: vi.fn(),
    documents: vi.fn(),
    uploadDocument: vi.fn(),
    deleteDocument: vi.fn(),
    downloadDocument: vi.fn(),
    vaultStatus: vi.fn(),
    vaultUnlock: vi.fn(),
    vaultLock: vi.fn(),
    updateApplication: vi.fn(),
    applicationHistory: vi.fn(),
  },
  getToken: vi.fn(),
  setToken: vi.fn(),
  clearToken: vi.fn(),
  isTokenExpired: vi.fn().mockReturnValue(false),
  refreshToken: vi.fn(),
}))

import App from '../../App.jsx'
import { api, getToken } from '../../api.js'

const ADMIN = { email: 'md@pdn.ac.lk', role: 'admin' }
const INVENTOR = { email: 'inv@pdn.ac.lk', role: 'inventor' }
const APPS = [
  { id: 'app-1', title: 'Integration Patent', status: 'filed', application_number: 'PAT-1', inventors: [], technology_area: 'AI' },
]

function renderApp(path = '/') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>
  )
}

describe('app flows', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('logs in and navigates admin layout: dashboard -> patents -> detail -> documents', async () => {
    getToken.mockReturnValue(null)
    api.login.mockResolvedValue({ access_token: 'tok' })
    api.me.mockResolvedValue(ADMIN)
    api.applications.mockResolvedValue(APPS)
    api.users.mockResolvedValue([])
    api.applicationHistory.mockResolvedValue([])
    api.vaultStatus.mockResolvedValue({ locked: true, remaining_seconds: 0 })
    api.documents.mockResolvedValue([])
    renderApp()

    // Login flow through the real login page.
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeInTheDocument())
    await userEvent.type(screen.getByLabelText('Email'), 'md@pdn.ac.lk')
    await userEvent.type(screen.getByLabelText('Password'), 'pw')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    // Admin shell with role nav.
    await waitFor(() => expect(screen.getByText('Admin Dashboard')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'Users' })).toBeInTheDocument()

    // Navigate to patents via the sidebar link (real routing + layout).
    await userEvent.click(screen.getByRole('link', { name: 'Patents' }))
    await waitFor(() => expect(screen.getByText('Integration Patent')).toBeInTheDocument())

    // Open the detail modal from the row.
    await userEvent.click(screen.getByText('Integration Patent'))
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Patent Details' })).toBeInTheDocument())
    await userEvent.keyboard('{Escape}')

    // Switch to the documents tab through the URL-driven tabs.
    await userEvent.click(screen.getByRole('button', { name: 'Documents' }))
    await waitFor(() => expect(screen.getByText('Vault Locked')).toBeInTheDocument())
  })

  it('serves the inventor shell with section routes', async () => {
    getToken.mockReturnValue('tok')
    api.me.mockResolvedValue(INVENTOR)
    api.applications.mockResolvedValue([])
    api.notifications.mockResolvedValue([])
    renderApp(['/user'])

    await waitFor(() => expect(screen.getByText('Welcome back')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'My Patents' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('link', { name: 'Notifications' }))
    await waitFor(() => expect(screen.getByText('No notifications yet.')).toBeInTheDocument())
    expect(screen.queryByText('Welcome back')).not.toBeInTheDocument()
  })

  it('recovers to login when the session is invalid', async () => {
    getToken.mockReturnValue('bad')
    api.me.mockRejectedValue(new Error('Invalid or expired token'))
    renderApp()

    await waitFor(() => expect(screen.getByLabelText('Email')).toBeInTheDocument())
  })
})
