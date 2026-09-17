import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, fireEvent, act } from '@testing-library/react'
import ToastProvider from '../ToastProvider.jsx'
import { useToast } from '../../hooks/useToast.js'

function Trigger({ kind = 'success', message = 'hello' }) {
  const toast = useToast()
  return <button onClick={() => toast[kind](message)}>fire</button>
}

describe('Toast system', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it.each(['success', 'error', 'info'])('renders a %s toast', (kind) => {
    render(
      <ToastProvider>
        <Trigger kind={kind} message={`${kind}-msg`} />
      </ToastProvider>
    )
    fireEvent.click(screen.getByRole('button', { name: 'fire' }))
    expect(screen.getByText(`${kind}-msg`)).toBeInTheDocument()
  })

  it('auto-dismisses after 5s', () => {
    render(
      <ToastProvider>
        <Trigger message="bye-soon" />
      </ToastProvider>
    )
    fireEvent.click(screen.getByRole('button', { name: 'fire' }))
    expect(screen.getByText('bye-soon')).toBeInTheDocument()
    act(() => { vi.advanceTimersByTime(5000) })
    expect(screen.queryByText('bye-soon')).not.toBeInTheDocument()
  })

  it('stacks multiple toasts', () => {
    function Multi() {
      const toast = useToast()
      return <button onClick={() => { toast.success('one'); toast.error('two') }}>fire</button>
    }
    render(
      <ToastProvider>
        <Multi />
      </ToastProvider>
    )
    fireEvent.click(screen.getByRole('button', { name: 'fire' }))
    expect(screen.getByText('one')).toBeInTheDocument()
    expect(screen.getByText('two')).toBeInTheDocument()
  })

  it('announces toasts to screen readers', () => {
    render(
      <ToastProvider>
        <Trigger kind="error" message="loud-failure" />
      </ToastProvider>
    )
    fireEvent.click(screen.getByRole('button', { name: 'fire' }))
    expect(screen.getByRole('alert')).toHaveTextContent('loud-failure')
  })

  it('is safe to use without a provider (no-op)', () => {
    function Bare() {
      const toast = useToast()
      toast.error('silent')
      return <span>bare</span>
    }
    render(<Bare />)
    expect(screen.getByText('bare')).toBeInTheDocument()
  })
})
