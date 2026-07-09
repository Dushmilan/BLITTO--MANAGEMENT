import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import Card from '../Card.jsx'

describe('Card', () => {
  it('renders children', () => {
    render(<Card>Body content</Card>)
    expect(screen.getByText('Body content')).toBeInTheDocument()
  })

  it('applies the base variant by default', () => {
    render(<Card>Base</Card>)
    expect(screen.getByText('Base').className).toContain('border-hairline')
  })

  it('applies a named variant', () => {
    render(<Card variant="mockup">Mock</Card>)
    expect(screen.getByText('Mock').className).toContain('shadow-editorial-lg')
  })

  it('merges a custom className', () => {
    render(<Card className="extra-class">X</Card>)
    expect(screen.getByText('X').className).toContain('extra-class')
  })

  it('forwards extra props to the container', () => {
    render(<Card data-testid="card-el">X</Card>)
    expect(screen.getByTestId('card-el')).toBeInTheDocument()
  })
})
