import { Suspense, useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import { Sidebar, SidebarContent } from './Sidebar'
import { Topbar } from './Topbar'
import { CommandPalette } from '@/components/CommandPalette'
import { MaintenanceScreen } from '@/components/MaintenanceScreen'
import { MaintenanceBanner } from '@/components/MaintenanceBanner'
import { useInstanceSettings, useMe } from '@/api/user'
import { Spinner } from '@/components/ui/Spinner'
import { cn } from '@/lib/cn'
import { useLocale } from '@/i18n/LocaleContext'
import { useTheme } from '@/lib/theme'

// Grid/visual pages use the full width on large screens; text-, form- and
// list-heavy pages stay centered at a comfortable reading width so they don't
// look stretched on 4K.
const FULL_BLEED = ['/calendar', '/contacts']

export function AppShell() {
  const { data: me } = useMe()
  const { data: settings } = useInstanceSettings()
  const { setLocale } = useLocale()
  const { setTheme } = useTheme()
  const isAdmin = me?.role === 'admin'
  const brandName = settings?.instance_name?.trim() || 'Davyn'
  const [drawer, setDrawer] = useState(false)
  const [cmdOpen, setCmdOpen] = useState(false)
  const location = useLocation()

  useEffect(() => {
    document.title = brandName
  }, [brandName])

  // Locale precedence: the user's own choice wins, else the instance default.
  useEffect(() => {
    const loc = me?.locale || settings?.default_locale
    if (loc) setLocale(loc)
  }, [me?.locale, settings?.default_locale])

  // Apply the user's saved theme on load (e.g. when signing in on a new device).
  // If they never chose one, the client/localStorage default is kept untouched.
  useEffect(() => {
    if (me?.theme === 'light' || me?.theme === 'dark' || me?.theme === 'system') {
      setTheme(me.theme)
    }
  }, [me?.theme])

  // Maintenance mode pauses the app for non-admins; admins keep full access.
  if (me && me.role !== 'admin' && me.maintenance) {
    return <MaintenanceScreen reason={me.maintenance_reason} />
  }

  return (
    <div className="flex min-h-screen">
      <Sidebar isAdmin={!!isAdmin} brandName={brandName} />

      {/* Mobile drawer */}
      <AnimatePresence>
        {drawer && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setDrawer(false)}
              className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm lg:hidden"
            />
            <motion.aside
              initial={{ x: -300 }}
              animate={{ x: 0 }}
              exit={{ x: -300 }}
              transition={{ type: 'spring', stiffness: 320, damping: 34 }}
              className="glass-strong fixed inset-y-0 left-0 z-50 w-72 border-r border-foreground/10 lg:hidden"
            >
              <SidebarContent isAdmin={!!isAdmin} brandName={brandName} onNavigate={() => setDrawer(false)} />
            </motion.aside>
          </>
        )}
      </AnimatePresence>

      <CommandPalette open={cmdOpen} onOpenChange={setCmdOpen} isAdmin={!!isAdmin} />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Sticky header group: the maintenance banner flies in above the Topbar
            and both stay pinned to the top while scrolling. */}
        <div className="sticky top-0 z-30">
          {isAdmin && <MaintenanceBanner active={!!me?.maintenance} reason={me?.maintenance_reason} />}
          <Topbar me={me} onMenuClick={() => setDrawer(true)} onSearchClick={() => setCmdOpen(true)} />
        </div>
        <main
          className={cn(
            'mx-auto flex w-full flex-1 flex-col px-4 py-6 lg:px-8 lg:py-8',
            FULL_BLEED.includes(location.pathname)
              ? 'max-w-none 2xl:px-10 min-[1920px]:px-14'
              : 'max-w-6xl xl:max-w-[80rem] 2xl:max-w-[88rem]',
          )}
        >
          <AnimatePresence mode="wait">
            <motion.div
              key={location.pathname}
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -8 }}
              transition={{ duration: 0.25 }}
              className="flex flex-1 flex-col"
            >
              <Suspense fallback={<div className="grid place-items-center py-24 text-muted"><Spinner className="size-6" /></div>}>
                <Outlet />
              </Suspense>
            </motion.div>
          </AnimatePresence>
        </main>
      </div>
    </div>
  )
}
