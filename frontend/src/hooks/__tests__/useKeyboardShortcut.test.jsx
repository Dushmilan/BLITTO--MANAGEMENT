import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { useKeyboardShortcut } from '../useKeyboardShortcut.js'

function Harness({ shortcut, handler, options }) {
  useKeyboardShortcut(shortcut, handler, options)
  return <span>harness</span>
}

function press(key, init = {}) {
  fireEvent.keyDown(window, { key, ...init })
}

describe('useKeyboardShortcut', () => {
  it('calls the handler on plain key match', () => {
    const handler = vi.fn()
    render(<Harness shortcut="Escape" handler={handler} />)
    press('Escape')
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('ignores other keys', () => {
    const handler = vi.fn()
    render(<Harness shortcut="Escape" handler={handler} />)
    press('Enter')
    expect(handler).not.toHaveBeenCalled()
  })

  it('matches ctrl+k case-insensitively', () => {
    const handler = vi.fn()
    render(<Harness shortcut="ctrl+k" handler={handler} />)
    press('K', { ctrlKey: true })
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('requires the modifier when specified', () => {
    const handler = vi.fn()
    render(<Harness shortcut="ctrl+k" handler={handler} />)
    press('k')
    expect(handler).not.toHaveBeenCalled()
  })

  it('does not fire when typing in an input unless allowed', () => {
    const handler = vi.fn()
    render(
      <div>
        <Harness shortcut="?" handler={handler} />
        <input aria-label="typing-box" />
      </div>
    )
    screen.getByLabelText('typing-box').focus()
    // Dispatch on the input itself so the event bubbles to window with
    // the input as its target — like a real keystroke while typing.
    fireEvent.keyDown(screen.getByLabelText('typing-box'), { key: '?' })
    expect(handler).not.toHaveBeenCalled()
  })

  it('unregisters on unmount', () => {
    const handler = vi.fn()
    const { unmount } = render(<Harness shortcut="Escape" handler={handler} />)
    unmount()
    press('Escape')
    expect(handler).not.toHaveBeenCalled()
  })
})
