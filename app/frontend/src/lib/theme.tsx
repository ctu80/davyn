import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

type Theme = 'dark' | 'light' | 'system'
const STORAGE_KEY = 'davyn-theme'
const ACCENT_KEY = 'davyn-accent'

interface ThemeCtx {
  theme: Theme
  resolved: 'dark' | 'light'
  setTheme: (t: Theme) => void
  toggle: () => void
  accent: string
  setAccent: (hex: string) => void
}

const Ctx = createContext<ThemeCtx | null>(null)

function systemPrefersDark() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function apply(theme: Theme): 'dark' | 'light' {
  const dark = theme === 'dark' || (theme === 'system' && systemPrefersDark())
  document.documentElement.classList.toggle('dark', dark)
  return dark ? 'dark' : 'light'
}

export function hexToRgbTriplet(hex: string): string | null {
  const m = /^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/.exec(hex)
  if (!m) return null
  return `${parseInt(m[1], 16)} ${parseInt(m[2], 16)} ${parseInt(m[3], 16)}`
}

function applyAccentToDOM(hex: string) {
  const rgb = hexToRgbTriplet(hex)
  if (rgb) document.documentElement.style.setProperty('--accent', rgb)
  else document.documentElement.style.removeProperty('--accent')
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(
    () => (localStorage.getItem(STORAGE_KEY) as Theme) || 'dark',
  )
  const [resolved, setResolved] = useState<'dark' | 'light'>(() => apply(theme))
  const [accent, setAccentState] = useState<string>(
    () => localStorage.getItem(ACCENT_KEY) ?? '',
  )

  useEffect(() => {
    setResolved(apply(theme))
    localStorage.setItem(STORAGE_KEY, theme)
    if (theme !== 'system') return
    const mq = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = () => setResolved(apply('system'))
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [theme])

  useEffect(() => {
    applyAccentToDOM(accent)
    if (accent) localStorage.setItem(ACCENT_KEY, accent)
    else localStorage.removeItem(ACCENT_KEY)
  }, [accent])

  const setTheme = (t: Theme) => setThemeState(t)
  const toggle = () => setThemeState(resolved === 'dark' ? 'light' : 'dark')
  const setAccent = (hex: string) => setAccentState(hex)

  return (
    <Ctx.Provider value={{ theme, resolved, setTheme, toggle, accent, setAccent }}>
      {children}
    </Ctx.Provider>
  )
}

export function useTheme() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
