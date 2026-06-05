import { Component, type ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useT } from '@/i18n/LocaleContext'

/** Translated fallback UI. A function component so it can use hooks. */
function ErrorFallback() {
  const t = useT()
  return (
    <div className="grid min-h-[60vh] place-items-center p-6">
      <div className="max-w-sm space-y-4 text-center">
        <div className="mx-auto grid size-12 place-items-center rounded-2xl bg-danger/12 text-danger ring-1 ring-inset ring-danger/20">
          <AlertTriangle className="size-6" />
        </div>
        <div className="space-y-1">
          <h2 className="text-base font-semibold">{t('Something went wrong')}</h2>
          <p className="text-sm text-muted">{t('This view failed to load. Try reloading the page.')}</p>
        </div>
        <Button onClick={() => window.location.reload()}>{t('Reload')}</Button>
      </div>
    </div>
  )
}

interface State { hasError: boolean }

/**
 * Catches render/lazy-chunk errors below it so a single failing route shows a
 * recoverable fallback instead of a blank white screen.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { hasError: false }

  static getDerivedStateFromError(): State {
    return { hasError: true }
  }

  componentDidCatch(error: unknown) {
    // Surface to the console for debugging; no remote logging by design.
    console.error('[davyn] render error:', error)
  }

  render() {
    return this.state.hasError ? <ErrorFallback /> : this.props.children
  }
}
