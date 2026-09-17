import { createContext, useCallback, useContext, useEffect, useState } from 'react'

const STORAGE_KEY = 'blitto-theme'

function systemPrefersDark() {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

const ThemeContext = createContext({ theme: 'light', toggle: () => {} })

// App-wide light/dark theme (issue #39). Preference persists in
// localStorage; first visit follows the OS setting. The `dark` class on
// <html> drives Tailwind's class strategy (darkMode: 'class').
export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(() => {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'light' || stored === 'dark') return stored
    return systemPrefersDark() ? 'dark' : 'light'
  })

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
    localStorage.setItem(STORAGE_KEY, theme)
  }, [theme])

  const toggle = useCallback(() => {
    setTheme((t) => (t === 'dark' ? 'light' : 'dark'))
  }, [])

  return (
    <ThemeContext.Provider value={{ theme, toggle }}>
      {children}
    </ThemeContext.Provider>
  )
}

export function useTheme() {
  return useContext(ThemeContext)
}
