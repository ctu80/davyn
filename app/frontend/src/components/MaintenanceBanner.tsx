import { AnimatePresence, motion } from 'motion/react'
import { Wrench, PowerOff } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useSetMaintenance } from '@/api/admin'
import { useToast } from '@/components/ui/Toast'
import { useT } from '@/i18n/LocaleContext'
import { ApiError } from '@/lib/api'

/**
 * Persistent fly-in banner shown to admins while maintenance mode is active.
 * Non-admins get the full MaintenanceScreen instead, so this is admin-only and
 * doubles as a one-click "turn it back off" (disabling needs only CSRF, no
 * reauth). Mount it inside a sticky header group so it stays pinned to the top.
 */
export function MaintenanceBanner({ active, reason }: { active: boolean; reason?: string | null }) {
  const t = useT()
  const toast = useToast()
  const qc = useQueryClient()
  const set = useSetMaintenance()

  async function disable() {
    try {
      await set.mutateAsync({ enabled: false })
      // The banner reads from the `me` query — refresh it so it flies back out.
      await qc.invalidateQueries({ queryKey: ['me'] })
      toast.success(t('Maintenance disabled'))
    } catch (e) {
      toast.error(t('Could not change maintenance'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <AnimatePresence>
      {active && (
        <motion.div
          initial={{ height: 0, opacity: 0 }}
          animate={{ height: 'auto', opacity: 1 }}
          exit={{ height: 0, opacity: 0 }}
          transition={{ type: 'spring', stiffness: 280, damping: 30 }}
          className="overflow-hidden"
        >
          <div className="flex items-center gap-3 border-b border-warning/30 bg-warning/12 px-4 py-2.5 text-warning lg:px-6">
            <motion.span
              initial={{ rotate: -25, scale: 0.7 }}
              animate={{ rotate: 0, scale: 1 }}
              transition={{ type: 'spring', stiffness: 320, damping: 14, delay: 0.08 }}
              className="grid size-7 shrink-0 place-items-center rounded-lg bg-warning/15 ring-1 ring-inset ring-warning/25"
            >
              <Wrench className="size-4" />
            </motion.span>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold leading-tight">{t('Maintenance mode is active')}</p>
              <p className="truncate text-xs text-warning/80">
                {reason?.trim() || t('Sync clients (CalDAV/CardDAV) receive HTTP 503 until you turn it off.')}
              </p>
            </div>
            <button
              onClick={disable}
              disabled={set.isPending}
              className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-warning/15 px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-warning/30 transition hover:bg-warning/25 disabled:opacity-60"
            >
              <PowerOff className="size-3.5" /> {t('Disable maintenance')}
            </button>
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  )
}
