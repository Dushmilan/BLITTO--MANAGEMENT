import { describe, it, expect, beforeEach, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'

// Mock the API module so App never hits the network.
vi.mock('../api.js', () => ({
  api: { login: vi.fn(), me: vi.fn(), applications: vi.fn(), notifications: vi.fn(), users: vi.fn() },
  getToken: vi.fn(),
  setToken: vi.fn(),
  clearToken: vi.fn(),
}))

// Mock the pages/layout so this suite tests only App's routing + auth gating,
// independent of the (actively-evolving) page visuals.
vi.mock('../pages/LoginPage.jsx', () => ({
  default: ({ onLogin }) => (
    <div>
      <span>LOGIN_PAGE</span>
      <button onClick={() => onLogin('admin@peradeniya.lk', 'pw')}>do-login</button>
    </div>
  ),
}))
vi.mock('../components/layout/AdminLayout.jsx', () => ({
  default: ({ user, onLogout }) => (
    <div>
      <span>ADMIN_LAYOUT</span>
      <span>{user.email}</span>
      <button onClick={onLogout}>do-logout</button>
    </div>
  ),
}))
vi.mock('../pages/UserPanel.jsx', () => ({
  default: ({ user, onLogout }) => (
    <div>
      <span>USER_PANEL</span>
      <span>{user.email}</span>
      <button onClick={onLogout}>do-logout</button>
    </div>
  ),
}))
vi.mock('../pages/admin/AdminDashboard.jsx', () => ({ default: () => <div>ADMIN_DASH</div> }))
vi.mock('../pages/admin/AdminPatentsPage.jsx', () => ({ default: () => <div>ADMIN_PATENTS</div> }))
vi.mock('../pages/admin/UsersPage.jsx', () => ({ default: () => <div>ADMIN_USERS</div> }))

import App from '../App.jsx'
import { api, getToken, setToken, clearToken } from '../api.js'

const ADMIN = { email: 'admin@peradeniya.lk', role: 'admin' }
const INVENTOR = { email: 'inventor@peradeniya.lk', role: 'inventor' }

function renderApp(initialEntries = ['/']) {
  return render(
    <MemoryRouter initialEntries={initialEntries}>
      <App />
    </MemoryRouter>
  )
}

describe('App routing & auth gating', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('redirects to the login page when unauthenticated', async () => {
    getToken.mockReturnValue(null)
    renderApp()
    await waitFor(() => expect(screen.getByText('LOGIN_PAGE')).toBeInTheDocument())
    expect(screen.queryByText('ADMIN_LAYOUT')).not.toBeInTheDocument()
    expect(screen.queryByText('USER_PANEL')).not.toBeInTheDocument()
  })

  it('restores the session on mount when a token exists (admin)', async () => {
    getToken.mockReturnValue('tok')
    api.me.mockResolvedValue(ADMIN)
    renderApp()
    await waitFor(() => expect(screen.getByText('ADMIN_LAYOUT')).toBeInTheDocument())
    expect(screen.getByText('admin@peradeniya.lk')).toBeInTheDocument()
  })

  it('restores the session on mount when a token exists (inventor)', async () => {
    getToken.mockReturnValue('tok')
    api.me.mockResolvedValue(INVENTOR)
    renderApp()
    await waitFor(() => expect(screen.getByText('USER_PANEL')).toBeInTheDocument())
    expect(screen.getByText('inventor@peradeniya.lk')).toBeInTheDocument()
  })

  it('clears the session and shows login if the stored token is invalid', async () => {
    getToken.mockReturnValue('bad-token')
    api.me.mockRejectedValue(new Error('401'))
    renderApp()
    await waitFor(() => expect(screen.getByText('LOGIN_PAGE')).toBeInTheDocument())
    expect(clearToken).toHaveBeenCalled()
  })

  it('logs in: stores token, loads the user, and shows admin layout', async () => {
    getToken.mockReturnValue(null)
    api.login.mockResolvedValue({ access_token: 'tok' })
    api.me.mockResolvedValue(ADMIN)
    renderApp()

    await waitFor(() => expect(screen.getByText('LOGIN_PAGE')).toBeInTheDocument())
    await userEvent.click(screen.getByText('do-login'))

    await waitFor(() => expect(screen.getByText('ADMIN_LAYOUT')).toBeInTheDocument())
    expect(api.login).toHaveBeenCalledWith('admin@peradeniya.lk', 'pw')
    expect(setToken).toHaveBeenCalledWith('tok')
  })

  it('logs out and returns to the login page', async () => {
    getToken.mockReturnValue('tok')
    api.me.mockResolvedValue(ADMIN)
    renderApp()

    await waitFor(() => expect(screen.getByText('ADMIN_LAYOUT')).toBeInTheDocument())
    await userEvent.click(screen.getByText('do-logout'))

    expect(clearToken).toHaveBeenCalled()
    await waitFor(() => expect(screen.getByText('LOGIN_PAGE')).toBeInTheDocument())
  })
})
