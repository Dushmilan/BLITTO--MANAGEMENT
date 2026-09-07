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
})
