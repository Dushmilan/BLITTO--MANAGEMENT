import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import Avatar from '../Avatar.jsx'

describe('Avatar', () => {
  it('renders initials from a two-word name', () => {
    render(<Avatar name="John Doe" />)
    expect(screen.getByText('JD')).toBeInTheDocument()
  })

  it('renders single-letter initial from a one-word name', () => {
    render(<Avatar name="Admin" />)
    expect(screen.getByText('A')).toBeInTheDocument()
  })

  it('shows "?" for empty name', () => {
    render(<Avatar name="" />)
    expect(screen.getByText('?')).toBeInTheDocument()
  })

  it('caps at two initials', () => {
    render(<Avatar name="John Michael Doe" />)
    expect(screen.getByText('JM')).toBeInTheDocument()
  })

  it('applies size classes', () => {
    const { rerender } = render(<Avatar name="Test" size="sm" />)
    expect(screen.getByText('T').className).toContain('w-7')

    rerender(<Avatar name="Test" size="lg" />)
    expect(screen.getByText('T').className).toContain('w-12')
  })

  it('merges custom className', () => {
    render(<Avatar name="Test" className="my-class" />)
    expect(screen.getByText('T').className).toContain('my-class')
  })

  it('sets title attribute to the name', () => {
    render(<Avatar name="Jane Roe" />)
    expect(screen.getByTitle('Jane Roe')).toBeInTheDocument()
  })
})
