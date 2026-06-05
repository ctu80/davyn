import { Wrench, LogOut } from 'lucide-react'
import { getCsrfToken } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

async function logout() {
  const csrf = await getCsrfToken()
  const f = document.createElement('form')
  f.method = 'POST'
  f.action = '/logout'
  const i = document.createElement('input')
  i.type = 'hidden'
  i.name = 'csrf_token'
  i.value = csrf
  f.appendChild(i)
  document.body.appendChild(f)
  f.submit()
}

/**
 * Shown to non-admin users while maintenance mode is active. Their data API is
 * paused (503), so this replaces the app shell entirely; only sign-out works.
 */
export function MaintenanceScreen({ reason }: { reason?: string | null }) {
  const t = useT()
  return (
    <div className="grid min-h-screen place-items-center p-6">
      <div className="w-full max-w-md rounded-2xl border border-foreground/10 bg-foreground/[0.03] p-8 text-center">
        <div className="mx-auto grid size-14 place-items-center rounded-2xl bg-warning/12 text-warning ring-1 ring-inset ring-warning/20">
          <Wrench className="size-7" />
        </div>
        <h1 className="mt-5 text-lg font-semibold">{t('Under maintenance')}</h1>
        <p className="mt-2 text-sm text-muted">
          {t('Davyn is temporarily unavailable while maintenance is in progress. Please check back soon.')}
        </p>
        {reason && (
          <p className="mt-3 rounded-xl bg-foreground/5 px-3 py-2 text-sm text-muted-strong ring-1 ring-inset ring-foreground/10">
            {reason}
          </p>
        )}
        <button
          onClick={() => logout()}
          className="mt-6 inline-flex items-center gap-2 rounded-xl bg-foreground/5 px-4 py-2 text-sm text-muted-strong ring-1 ring-inset ring-foreground/10 transition hover:bg-foreground/10 hover:text-foreground"
        >
          <LogOut className="size-4" /> {t('Sign out')}
        </button>
      </div>
    </div>
  )
}
