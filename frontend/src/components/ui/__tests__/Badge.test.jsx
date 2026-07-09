import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import Badge from '../Badge.jsx'

describe('Badge', () => {
  it('renders children', () => {
    render(<Badge>GRANTED</Badge>)
    expect(screen.getByText('GRANTED')).toBeInTheDocument()
  })

  it('normalizes a known status string to its status variant', () => {
    render(<Badge>GRANTED</Badge>)
    expect(screen.getByText('GRANTED').className).toContain('status-granted')
  })

  it('normalizes DRAFT to the draft variant', () => {
    render(<Badge>DRAFT</Badge>)
    expect(screen.getByText('DRAFT').className).toContain('status-draft')
  })

  it('falls back to the given variant for non-status text', () => {
    render(<Badge variant="type">code</Badge>)
    expect(screen.getByText('code').className).toContain('text-steel')
  })

  it('always applies the shared rounded-sm shape', () => {
    render(<Badge>X</Badge>)
    expect(screen.getByText('X').className).toContain('rounded-sm')
  })

  it('merges a custom className', () => {
    render(<Badge className="extra">Y</Badge>)
    expect(screen.getByText('Y').className).toContain('extra')
  })
})
