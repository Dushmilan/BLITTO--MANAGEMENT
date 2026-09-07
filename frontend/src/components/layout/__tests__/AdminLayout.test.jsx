import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import AdminLayout from '../AdminLayout.jsx'

describe('AdminLayout', () => {
  it('renders the Outlet content', () => {
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout user={{ email: 'admin@test.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Admin dashboard content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('Admin dashboard content')).toBeInTheDocument()
  })

  it('shows user email and role', () => {
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout user={{ email: 'admin@test.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('admin@test.com')).toBeInTheDocument()
    expect(screen.getByText('admin')).toBeInTheDocument()
  })

  it('renders nav items: Overview, Patents, Users', () => {
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout user={{ email: 'a@b.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('Overview')).toBeInTheDocument()
    expect(screen.getByText('Patents')).toBeInTheDocument()
    expect(screen.getByText('Users')).toBeInTheDocument()
  })

  it('calls onLogout and navigates to /login on sign out', async () => {
    const onLogout = vi.fn()
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout user={{ email: 'a@b.com', role: 'admin' }} onLogout={onLogout} />}>
            <Route index element={<p>Content</p>} />
          </Route>
          <Route path="/login" element={<p>Login page</p>} />
        </Routes>
      </MemoryRouter>
    )
    await userEvent.click(screen.getByText('Sign out'))
    expect(onLogout).toHaveBeenCalledTimes(1)
    expect(screen.getByText('Login page')).toBeInTheDocument()
  })

  it('toggles sidebar collapsed state', async () => {
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout user={{ email: 'a@b.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    const toggleBtn = screen.getByLabelText('Collapse sidebar')
    await userEvent.click(toggleBtn)
    expect(screen.getByLabelText('Expand sidebar')).toBeInTheDocument()
  })
})
