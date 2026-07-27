import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import DashboardPage from '../DashboardPage.jsx'

vi.mock('../../api.js', () => ({
  api: { applications: vi.fn() },
}))

import { api } from '../../api.js'

function renderPage() {
  return render(
    <MemoryRouter>
      <DashboardPage />
    </MemoryRouter>
  )
}

const APPS = [
  { id: '1', title: 'Alpha', status: 'granted', application_number: 'PAT-001', created_at: '2024-01-15T00:00:00Z' },
  { id: '2', title: 'Beta', status: 'filed', application_number: 'PAT-002', created_at: '2024-02-20T00:00:00Z' },
  { id: '3', title: 'Gamma', status: 'draft' },
  { id: '4', title: 'Delta', status: 'examination', application_number: 'PAT-004', created_at: '2024-03-10T00:00:00Z' },
  { id: '5', title: 'Epsilon', status: 'granted', application_number: 'PAT-005', created_at: '2024-04-05T00:00:00Z' },
]

describe('DashboardPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading then renders dashboard', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    expect(screen.getByText('Loading dashboard...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('Dashboard')).toBeInTheDocument()
    })
  })

  it('renders stat cards with correct counts', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('5')).toBeInTheDocument()
    })
    expect(screen.getAllByText('1').length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText('2')).toBeInTheDocument()
  })

  it('renders recent activity timeline', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Recent Activity')).toBeInTheDocument()
    })
    expect(screen.getByText(/Application "Epsilon"/)).toBeInTheDocument()
  })

  it('shows error message on API failure', async () => {
    api.applications.mockRejectedValue(new Error('Server error'))
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Server error')).toBeInTheDocument()
    })
  })

  it('renders recent applications table', async () => {
    api.applications.mockResolvedValue(APPS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('Recent Applications')).toBeInTheDocument()
    })
    expect(screen.getByText('Epsilon')).toBeInTheDocument()
  })
})
