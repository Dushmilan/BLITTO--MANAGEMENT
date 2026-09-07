import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import AppLayout from '../AppLayout.jsx'

vi.mock('../../ui/Sidebar.jsx', () => ({
  default: function MockSidebar({ collapsed, onToggle }) {
    return (
      <aside data-testid="sidebar">
        <button onClick={onToggle} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
          Toggle
        </button>
        <span data-testid="collapsed-state">{String(collapsed)}</span>
      </aside>
    )
  },
}))

describe('AppLayout', () => {
  it('renders the Outlet content', () => {
    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route element={<AppLayout user={{ email: 'admin@test.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Page content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('Page content')).toBeInTheDocument()
  })

  it('shows user email and role', () => {
    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route element={<AppLayout user={{ email: 'admin@test.com', role: 'admin' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('admin@test.com')).toBeInTheDocument()
    expect(screen.getByText('admin')).toBeInTheDocument()
  })

  it('calls onLogout and navigates to /login on sign out', async () => {
    const onLogout = vi.fn()
    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route element={<AppLayout user={{ email: 'admin@test.com', role: 'admin' }} onLogout={onLogout} />}>
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

  it('passes sidebar collapsed state', () => {
    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route element={<AppLayout user={{ email: 'a@b.com', role: 'inventor' }} onLogout={vi.fn()} />}>
            <Route index element={<p>Content</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByTestId('collapsed-state').textContent).toBe('false')
  })
})
