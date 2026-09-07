import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import Modal from '../Modal.jsx'

describe('Modal', () => {
  it('renders nothing when closed', () => {
    render(<Modal open={false} onClose={vi.fn()} title="Test"><p>Content</p></Modal>)
    expect(screen.queryByText('Test')).not.toBeInTheDocument()
    expect(screen.queryByText('Content')).not.toBeInTheDocument()
  })

  it('renders title and children when open', () => {
    render(<Modal open={true} onClose={vi.fn()} title="My Modal"><p>Hello</p></Modal>)
    expect(screen.getByText('My Modal')).toBeInTheDocument()
    expect(screen.getByText('Hello')).toBeInTheDocument()
  })

  it('calls onClose when Escape is pressed', async () => {
    const onClose = vi.fn()
    render(<Modal open={true} onClose={onClose} title="X"><p>Y</p></Modal>)
    await userEvent.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('calls onClose when overlay backdrop is clicked', async () => {
    const onClose = vi.fn()
    render(<Modal open={true} onClose={onClose} title="X"><p>Y</p></Modal>)
    const backdrop = screen.getByText('Y').parentElement.parentElement.querySelector('.bg-navy\\/60')
    // Click the overlay div directly
    const overlay = screen.getByText('Y').parentElement.parentElement.parentElement
    await userEvent.click(overlay)
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('locks body scroll when open', () => {
    const { rerender } = render(<Modal open={false} onClose={vi.fn()} title="X"><p>Y</p></Modal>)
    expect(document.body.style.overflow).toBe('')
    rerender(<Modal open={true} onClose={vi.fn()} title="X"><p>Y</p></Modal>)
    expect(document.body.style.overflow).toBe('hidden')
  })

  it('restores body scroll on unmount', () => {
    const { unmount } = render(<Modal open={true} onClose={vi.fn()} title="X"><p>Y</p></Modal>)
    expect(document.body.style.overflow).toBe('hidden')
    unmount()
    expect(document.body.style.overflow).toBe('')
  })
})
