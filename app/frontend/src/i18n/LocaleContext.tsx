import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { translate } from './translations'

type Vars = Record<string, string | number>

interface LocaleContextValue {
  locale: string
  setLocale: (locale: string) => void
  t: (key: string, vars?: Vars) => string
}

const LS_LOCALE = 'davyn.locale'

// Seed synchronously from localStorage so a reload paints in the right language
// immediately, instead of flashing English until the backend settings arrive.
function initialLocale(): string {
  try {
    const v = localStorage.getItem(LS_LOCALE)
    if (v === 'en' || v === 'de') return v
  } catch { /* ignore */ }
  return 'en'
}

const LocaleContext = createContext<LocaleContextValue>({
  locale: 'en',
  setLocale: () => {},
  t: (key) => key,
})

export function LocaleProvider({ children }: { children: React.ReactNode }) {
  const [locale, setLocaleState] = useState(initialLocale)

  const setLocale = useCallback((next: string) => {
    setLocaleState(next)
    try { localStorage.setItem(LS_LOCALE, next) } catch { /* ignore */ }
  }, [])

  const t = useCallback(
    (key: string, vars?: Vars): string => translate(locale, key, vars),
    [locale],
  )
  const value = useMemo(() => ({ locale, setLocale, t }), [locale, setLocale, t])
  return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export function useLocale() {
  return useContext(LocaleContext)
}

export function useT() {
  return useContext(LocaleContext).t
}
