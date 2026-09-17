import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import ThemeToggle from '../ThemeToggle.jsx'
import { ThemeProvider } from '../../../hooks/useTheme.jsx'

describe('ThemeToggle', () => {
  afterEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark')
  })

  it('toggles the theme on click with an accessible label', async () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false })
    render(
      <ThemeProvider>
        <ThemeToggle />
      </ThemeProvider>
    )
    const button = screen.getByRole('button', { name: /switch to dark mode/i })
    await userEvent.click(button)
    expect(screen.getByRole('button', { name: /switch to light mode/i })).toBeInTheDocument()
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })
})
