import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Input from '../Input.jsx'

describe('Input', () => {
  it('renders a label when provided', () => {
    render(<Input id="email" label="Email" />)
    expect(screen.getByText('Email')).toBeInTheDocument()
  })

  it('associates the label with the input via htmlFor/id', () => {
    render(<Input id="email" label="Email" />)
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })

  it('shows a required marker when required', () => {
    render(<Input id="pw" label="Password" required />)
    expect(screen.getByText('*')).toBeInTheDocument()
  })

  it('omits the required marker by default', () => {
    render(<Input id="pw" label="Password" />)
    expect(screen.queryByText('*')).not.toBeInTheDocument()
  })

  it('forwards value and onChange', async () => {
    const onChange = vi.fn()
    render(<Input id="email" label="Email" value="" onChange={onChange} />)
    await userEvent.type(screen.getByLabelText('Email'), 'a')
    expect(onChange).toHaveBeenCalled()
  })

  it('passes through the type attribute', () => {
    render(<Input id="pw" label="Password" type="password" />)
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password')
  })

  it('merges a custom className onto the wrapper', () => {
    render(<Input id="x" label="X" className="my-input" />)
    // The component spreads className onto the outer wrapper div.
    const wrapper = screen.getByLabelText('X').parentElement
    expect(wrapper.className).toContain('my-input')
  })
})
