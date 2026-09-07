import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import StatCard from '../StatCard.jsx'

describe('StatCard', () => {
  it('renders label and value', () => {
    render(<StatCard label="Total Patents" value="42" />)
    expect(screen.getByText('Total Patents')).toBeInTheDocument()
    expect(screen.getByText('42')).toBeInTheDocument()
  })

  it('shows positive trend in green', () => {
    render(<StatCard label="Filed" value="10" trend={12} />)
    expect(screen.getByText('↑ 12%')).toBeInTheDocument()
    expect(screen.getByText('↑ 12%').className).toContain('text-status-granted')
  })

  it('shows negative trend in red', () => {
    render(<StatCard label="Filed" value="10" trend={-5} />)
    expect(screen.getByText('↓ 5%')).toBeInTheDocument()
    expect(screen.getByText('↓ 5%').className).toContain('text-status-rejected')
  })

  it('shows trendLabel', () => {
    render(<StatCard label="Filed" value="10" trend={5} trendLabel="vs last month" />)
    expect(screen.getByText('vs last month')).toBeInTheDocument()
  })

  it('does not render trend section when both trend and trendLabel are omitted', () => {
    render(<StatCard label="Total" value="5" />)
    expect(screen.queryByText('%')).not.toBeInTheDocument()
  })

  it('renders icon when provided', () => {
    render(<StatCard label="Total" value="5" icon={<span data-testid="icon">🔍</span>} />)
    expect(screen.getByTestId('icon')).toBeInTheDocument()
  })

  it('merges custom className', () => {
    render(<StatCard label="L" value="V" className="my-card" />)
    const card = screen.getByText('L').closest('div').parentElement
    expect(card.className).toContain('my-card')
  })
})
