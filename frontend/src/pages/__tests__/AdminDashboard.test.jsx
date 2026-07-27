import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import AdminDashboard from '../admin/AdminDashboard.jsx'

vi.mock('../../api.js', () => ({
  api: { applications: vi.fn(), users: vi.fn() },
}))

import { api } from '../../api.js'

function renderPage() {
  return render(
    <MemoryRouter>
      <AdminDashboard />
    </MemoryRouter>
  )
}

const APPS = [
  { id: '1', title: 'Patent Alpha', status: 'granted', application_number: 'PAT-001' },
  { id: '2', title: 'Patent Beta', status: 'examination', application_number: 'PAT-002' },
  { id: '3', title: 'Patent Gamma', status: 'draft' },
]
const USERS = [
  { id: '1', email: 'admin@test.com', role: 'admin' },
  { id: '2', email: 'inv@test.com', role: 'inventor' },
]

describe('AdminDashboard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading then renders stat cards', async () => {
    api.applications.mockResolvedValue(APPS)
    api.users.mockResolvedValue(USERS)
    renderPage()
    expect(screen.getByText('Loading dashboard...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('Admin Dashboard')).toBeInTheDocument()
    })
    expect(screen.getAllByText('3').length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText('2')).toBeInTheDocument()
  })

  it('renders recent applications table', async () => {
    api.applications.mockResolvedValue(APPS)
    api.users.mockResolvedValue(USERS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Patent Alpha')).toBeInTheDocument()
    })
    expect(screen.getByText('Patent Beta')).toBeInTheDocument()
    expect(screen.getByText('Patent Gamma')).toBeInTheDocument()
  })

  it('shows empty state when no apps', async () => {
    api.applications.mockResolvedValue([])
    api.users.mockResolvedValue(USERS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('No applications yet.')).toBeInTheDocument()
    })
  })
})
