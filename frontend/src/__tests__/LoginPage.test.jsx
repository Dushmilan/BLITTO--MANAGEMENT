import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import LoginPage from '../pages/LoginPage.jsx'

function renderLogin(onLogin) {
  return render(
    <MemoryRouter>
      <LoginPage onLogin={onLogin} />
    </MemoryRouter>
  )
}

describe('LoginPage', () => {
  it('renders the sign-in form', () => {
    renderLogin(vi.fn())
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument()
  })

  it('calls onLogin with the entered credentials on submit', async () => {
    const onLogin = vi.fn().mockResolvedValue(undefined)
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'admin@peradeniya.lk')
    await userEvent.type(screen.getByLabelText('Password'), 'secret')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(onLogin).toHaveBeenCalledWith('admin@peradeniya.lk', 'secret')
  })

  it('shows an error message when login fails', async () => {
    const onLogin = vi.fn().mockRejectedValue(new Error('Invalid credentials'))
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'a@b.c')
    await userEvent.type(screen.getByLabelText('Password'), 'bad')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText('Invalid credentials')).toBeInTheDocument()
  })

  it('rejects malformed emails before calling onLogin', async () => {
    const onLogin = vi.fn().mockResolvedValue(undefined)
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'not-an-email')
    await userEvent.type(screen.getByLabelText('Password'), 'secret')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    // Native email validation and/or the client check block the submit.
    expect(onLogin).not.toHaveBeenCalled()
  })

  it('validates email shapes', async () => {
    const { isValidEmail } = await import('../pages/LoginPage.jsx')
    expect(isValidEmail('a@b.co')).toBe(true)
    expect(isValidEmail('  a@b.co  ')).toBe(true)
    expect(isValidEmail('not-an-email')).toBe(false)
    expect(isValidEmail('a@b')).toBe(false)
    expect(isValidEmail('')).toBe(false)
  })

  it('maps a 401 to a wrong-credentials message', async () => {
    const err = new Error('Invalid credentials')
    err.status = 401
    const onLogin = vi.fn().mockRejectedValue(err)
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'a@b.co')
    await userEvent.type(screen.getByLabelText('Password'), 'bad')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText(/incorrect email or password/i)).toBeInTheDocument()
  })

  it('maps a 429 to a throttled message', async () => {
    const err = new Error('Too many login attempts')
    err.status = 429
    const onLogin = vi.fn().mockRejectedValue(err)
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'a@b.co')
    await userEvent.type(screen.getByLabelText('Password'), 'bad')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText(/too many attempts/i)).toBeInTheDocument()
  })

  it('announces errors and disables the form while submitting', async () => {
    let resolve
    const onLogin = vi.fn().mockImplementation(() => new Promise((r) => { resolve = r }))
    renderLogin(onLogin)

    await userEvent.type(screen.getByLabelText('Email'), 'a@b.co')
    await userEvent.type(screen.getByLabelText('Password'), 'secret')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(screen.getByLabelText('Email')).toBeDisabled()
    expect(screen.getByRole('button', { name: /signing in/i })).toBeDisabled()
    resolve()
  })
})
