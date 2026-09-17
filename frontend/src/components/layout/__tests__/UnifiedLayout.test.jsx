import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import UnifiedLayout from '../UnifiedLayout.jsx'

const ADMIN = { email: 'a@test.com', role: 'admin' }
const INVENTOR = { email: 'i@test.com', role: 'inventor' }

function setup(user, path = '/admin') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin" element={<UnifiedLayout user={user} onLogout={() => {}} />}>
          <Route index element={<span>OUTLET_CONTENT</span>} />
        </Route>
        <Route path="/user" element={<UnifiedLayout user={user} onLogout={() => {}} />}>
          <Route index element={<span>OUTLET_CONTENT</span>} />
        </Route>
      </Routes>
    </MemoryRouter>
  )
}

describe('UnifiedLayout', () => {
  it('shows staff nav for admin roles', () => {
    setup(ADMIN)
    expect(screen.getByText('Overview')).toBeInTheDocument()
    expect(screen.getByText('Patents')).toBeInTheDocument()
    expect(screen.getByText('Users')).toBeInTheDocument()
  })

  it('shows inventor nav for inventors', () => {
    setup(INVENTOR, '/user')
    expect(screen.getByText('Overview')).toBeInTheDocument()
    expect(screen.getByText('My Patents')).toBeInTheDocument()
    expect(screen.getByText('Notifications')).toBeInTheDocument()
    expect(screen.queryByText('Users')).not.toBeInTheDocument()
  })

  it('renders the nested route content', () => {
    setup(ADMIN)
    expect(screen.getByText('OUTLET_CONTENT')).toBeInTheDocument()
  })

  it('opens and closes the mobile drawer via hamburger and backdrop', async () => {
    setup(ADMIN)
    const toggle = screen.getByLabelText('Open navigation')
    await userEvent.click(toggle)
    expect(screen.getByLabelText('Close navigation')).toBeInTheDocument()
    await userEvent.click(screen.getByTestId('drawer-backdrop'))
    expect(screen.getByLabelText('Open navigation')).toBeInTheDocument()
  })

  it('signs out and shows the user email', async () => {
    const onLogout = vi.fn()
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<UnifiedLayout user={ADMIN} onLogout={onLogout} />}>
            <Route index element={<span>OUTLET_CONTENT</span>} />
          </Route>
        </Routes>
      </MemoryRouter>
    )
    expect(screen.getByText('a@test.com')).toBeInTheDocument()
    await userEvent.click(screen.getByText('Sign out'))
    expect(onLogout).toHaveBeenCalledTimes(1)
  })
})
