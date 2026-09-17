import { useTheme } from '../../hooks/useTheme.jsx'

// Sun/moon theme switch for the layout header (issue #39).
export default function ThemeToggle() {
  const { theme, toggle } = useTheme()
  const dark = theme === 'dark'

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
      className="touch-target inline-flex items-center justify-center rounded-md p-xs text-steel hover:text-ink hover:bg-ivory-200 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition-colors focus-visible:ring-2 focus-visible:ring-copper"
    >
      {dark ? (
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
          <circle cx="9" cy="9" r="4" />
          <path d="M9 1.5v2M9 14.5v2M1.5 9h2M14.5 9h2M3.7 3.7l1.4 1.4M12.9 12.9l1.4 1.4M14.3 3.7l-1.4 1.4M5.1 12.9l-1.4 1.4" />
        </svg>
      ) : (
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="M15 11A6.5 6.5 0 017 3a6.5 6.5 0 108 8z" />
        </svg>
      )}
    </button>
  )
}
