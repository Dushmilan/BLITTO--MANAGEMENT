import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import ProtectedRoute from '../ProtectedRoute.jsx'

const ADMIN = { email: 'a@test.com', role: 'admin' }
const INVENTOR = { email: 'i@test.com', role: 'inventor' }

function setup(user, allowedRoles, path = '/secret') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route
          path="/secret"
          element={
            <ProtectedRoute user={user} allowedRoles={allowedRoles}>
              <span>SECRET</span>
            </ProtectedRoute>
          }
        />
        <Route path="/login" element={<span>LOGIN</span>} />
        <Route path="/admin" element={<span>ADMIN_HOME</span>} />
        <Route path="/user" element={<span>USER_HOME</span>} />
      </Routes>
    </MemoryRouter>
  )
}

describe('ProtectedRoute', () => {
  it('renders children for an allowed role', () => {
    setup(ADMIN, ['admin', 'attorney'])
    expect(screen.getByText('SECRET')).toBeInTheDocument()
  })

  it('redirects to login when unauthenticated', () => {
    setup(null, ['admin'])
    expect(screen.getByText('LOGIN')).toBeInTheDocument()
  })

  it('redirects an inventor away from staff routes', () => {
    setup(INVENTOR, ['admin', 'attorney', 'paralegal'])
    expect(screen.getByText('USER_HOME')).toBeInTheDocument()
    expect(screen.queryByText('SECRET')).not.toBeInTheDocument()
  })

  it('redirects staff away from inventor routes', () => {
    setup(ADMIN, ['inventor'])
    expect(screen.getByText('ADMIN_HOME')).toBeInTheDocument()
    expect(screen.queryByText('SECRET')).not.toBeInTheDocument()
  })
})
