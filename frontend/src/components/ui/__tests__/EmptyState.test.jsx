import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import EmptyState from '../EmptyState.jsx'

describe('EmptyState', () => {
  it('renders icon, title and description', () => {
    render(<EmptyState title="No patents" description="Submit your first disclosure." />)
    expect(screen.getByRole('heading', { name: 'No patents' })).toBeInTheDocument()
    expect(screen.getByText('Submit your first disclosure.')).toBeInTheDocument()
  })

  it('renders an optional CTA button', async () => {
    const onAction = vi.fn()
    render(
      <EmptyState title="T" description="D" actionLabel="Create" onAction={onAction} />
    )
    await userEvent.click(screen.getByRole('button', { name: 'Create' }))
    expect(onAction).toHaveBeenCalledTimes(1)
  })

  it('omits the CTA when no action is given', () => {
    render(<EmptyState title="T" description="D" />)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
