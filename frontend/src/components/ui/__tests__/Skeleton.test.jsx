import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import Skeleton from '../Skeleton.jsx'

describe('Skeleton', () => {
  it('renders pulse-animated placeholder blocks', () => {
    const { container } = render(<Skeleton rows={3} />)
    expect(container.querySelectorAll('[data-skeleton]').length).toBe(3)
    expect(container.querySelector('.animate-pulse')).toBeInTheDocument()
  })

  it('is announced as busy loading content', () => {
    render(<Skeleton rows={1} label="Loading patents" />)
    expect(screen.getByRole('status')).toHaveTextContent('Loading patents')
  })

  it('renders a single block by default', () => {
    const { container } = render(<Skeleton />)
    expect(container.querySelectorAll('[data-skeleton]').length).toBe(1)
  })
})
