import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Button from '../Button.jsx'

describe('Button', () => {
  it('renders its children', () => {
    render(<Button>Click me</Button>)
    expect(screen.getByRole('button', { name: 'Click me' })).toBeInTheDocument()
  })

  it('defaults to type="button"', () => {
    render(<Button>Go</Button>)
    expect(screen.getByRole('button')).toHaveAttribute('type', 'button')
  })

  it('honours an explicit type', () => {
    render(<Button type="submit">Submit</Button>)
    expect(screen.getByRole('button')).toHaveAttribute('type', 'submit')
  })

  it('calls onClick when clicked', async () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>Tap</Button>)
    await userEvent.click(screen.getByRole('button'))
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('respects the disabled prop', () => {
    render(<Button disabled>Nope</Button>)
    expect(screen.getByRole('button')).toBeDisabled()
  })

  it('applies variant classes', () => {
    render(<Button variant="primary-dark">Dark</Button>)
    expect(screen.getByRole('button').className).toContain('bg-navy')
  })

  it('falls back to primary for an unknown variant', () => {
    render(<Button variant="does-not-exist">X</Button>)
    expect(screen.getByRole('button').className).toContain('bg-copper')
  })

  it('merges a custom className', () => {
    render(<Button className="my-custom">X</Button>)
    expect(screen.getByRole('button').className).toContain('my-custom')
  })
})
