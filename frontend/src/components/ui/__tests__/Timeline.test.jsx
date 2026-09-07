import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import Timeline from '../Timeline.jsx'

describe('Timeline', () => {
  it('shows empty state when items is empty', () => {
    render(<Timeline items={[]} />)
    expect(screen.getByText('No recent activity.')).toBeInTheDocument()
  })

  it('shows empty state when items is undefined', () => {
    render(<Timeline />)
    expect(screen.getByText('No recent activity.')).toBeInTheDocument()
  })

  it('renders items with text and time', () => {
    const items = [
      { id: '1', text: 'App created', time: '2024-01-01' },
      { id: '2', text: 'Patent filed', time: '2024-01-02' },
    ]
    render(<Timeline items={items} />)
    expect(screen.getByText('App created')).toBeInTheDocument()
    expect(screen.getByText('2024-01-01')).toBeInTheDocument()
    expect(screen.getByText('Patent filed')).toBeInTheDocument()
    expect(screen.getByText('2024-01-02')).toBeInTheDocument()
  })

  it('applies staggered animation delay to items', () => {
    const items = [
      { id: '1', text: 'First', time: '2024' },
      { id: '2', text: 'Second', time: '2024' },
    ]
    render(<Timeline items={items} />)
    const listItems = screen.getByText('First').closest('li')
    expect(listItems.style.animationDelay).toBe('0s')
    const secondItem = screen.getByText('Second').closest('li')
    expect(secondItem.style.animationDelay).toBe('0.06s')
  })

  it('uses index as fallback key when item has no id', () => {
    const items = [
      { text: 'No id', time: '2024' },
      { text: 'Still no id', time: '2024' },
    ]
    render(<Timeline items={items} />)
    expect(screen.getByText('No id')).toBeInTheDocument()
    expect(screen.getByText('Still no id')).toBeInTheDocument()
  })
})
