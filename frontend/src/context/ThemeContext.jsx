import { createContext, useContext, useEffect, useState } from 'react'

const ThemeContext = createContext({ theme: 'light', toggleTheme: () => {} })

function readStoredTheme() {
  try {
    return localStorage.getItem('bm-theme') || 'light'
  } catch {
    // Private-mode storage denial: fall back to the default theme.
    return 'light'
  }
}

export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(readStoredTheme)

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    try {
      localStorage.setItem('bm-theme', theme)
    } catch {
      // Storage denial must never break theming.
    }
    const icon = document.querySelector('link[rel="icon"]')
    if (icon) {
      icon.setAttribute('href', theme === 'dark' ? '/favicon-dark.svg' : '/favicon.svg')
    }
  }, [theme])

  const toggleTheme = () => setTheme((t) => (t === 'light' ? 'dark' : 'light'))

  return <ThemeContext.Provider value={{ theme, toggleTheme }}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  return useContext(ThemeContext)
}
