import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import UsersPage from '../admin/UsersPage.jsx'

vi.mock('../../api.js', () => ({
  api: { users: vi.fn() },
}))

import { api } from '../../api.js'

function renderPage() {
  return render(
    <MemoryRouter>
      <UsersPage />
    </MemoryRouter>
  )
}

const USERS = [
  { id: '1', email: 'admin@test.com', role: 'admin', created_at: '2024-01-01T00:00:00Z' },
  { id: '2', email: 'inventor@test.com', role: 'inventor', created_at: '2024-02-15T00:00:00Z' },
  { id: '3', email: 'paralegal@test.com', role: 'paralegal', created_at: '2024-03-20T00:00:00Z' },
]

describe('UsersPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows loading then renders user list', async () => {
    api.users.mockResolvedValue(USERS)
    renderPage()
    expect(screen.getByText('Loading users...')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByText('admin@test.com')).toBeInTheDocument()
    })
    expect(screen.getByText('3 users registered')).toBeInTheDocument()
  })

  it('shows empty table when no users', async () => {
    api.users.mockResolvedValue([])
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('No users found.')).toBeInTheDocument()
    })
    expect(screen.getByText('0 users registered')).toBeInTheDocument()
  })

  it('filters users by search term', async () => {
    api.users.mockResolvedValue(USERS)
    renderPage()
    await waitFor(() => {
      expect(screen.getByText('admin@test.com')).toBeInTheDocument()
    })
    const searchInput = screen.getByPlaceholderText('Search users...')
    await userEvent.type(searchInput, 'inventor')
    expect(screen.queryByText('admin@test.com')).not.toBeInTheDocument()
    expect(screen.getByText('inventor@test.com')).toBeInTheDocument()
  })
})
