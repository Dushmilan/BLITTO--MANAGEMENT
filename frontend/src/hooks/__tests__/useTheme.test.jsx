import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider, useTheme } from '../useTheme.jsx'

function ThemeProbe() {
  const { theme, toggle } = useTheme()
  return (
    <div>
      <span data-testid="theme">{theme}</span>
      <button onClick={toggle}>toggle</button>
    </div>
  )
}

describe('useTheme', () => {
  let matchMediaBackup

  beforeEach(() => {
    matchMediaBackup = window.matchMedia
    localStorage.clear()
    document.documentElement.classList.remove('dark')
  })

  afterEach(() => {
    window.matchMedia = matchMediaBackup
    localStorage.clear()
    document.documentElement.classList.remove('dark')
  })

  function mockColorScheme(dark) {
    window.matchMedia = vi.fn().mockReturnValue({ matches: dark })
  }

  it('defaults to the system preference', () => {
    mockColorScheme(true)
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    )
    expect(screen.getByTestId('theme')).toHaveTextContent('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('restores the stored preference over the system one', () => {
    mockColorScheme(true)
    localStorage.setItem('blitto-theme', 'light')
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    )
    expect(screen.getByTestId('theme')).toHaveTextContent('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('toggles and persists the theme', async () => {
    mockColorScheme(false)
    render(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    )
    expect(screen.getByTestId('theme')).toHaveTextContent('light')
    await userEvent.click(screen.getByRole('button', { name: 'toggle' }))
    expect(screen.getByTestId('theme')).toHaveTextContent('dark')
    expect(localStorage.getItem('blitto-theme')).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })
})
