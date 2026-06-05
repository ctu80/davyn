import { useState } from 'react'
import { motion, AnimatePresence } from 'motion/react'
import {
  CalendarDays,
  Contact,
  Smartphone,
  MonitorSmartphone,
  CalendarClock,
  Activity as ActivityIcon,
  ShieldCheck,
  DatabaseBackup,
  ArrowRight,
  MapPin,
  AlignLeft,
  X,
  ExternalLink,
  Clock,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import { LogoMark } from '@/components/layout/LogoMark'
import { useAddressBooks, useAppPasswords, useCalendars, useDashboard, useInstanceSettings, useMe, useSessions } from '@/api/user'
import { useAdminStatus } from '@/api/admin'
import type { UpcomingEvent } from '@/api/types'
import { Card, CardContent } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { StatusPill } from '@/components/ui/StatusPill'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { relativeTime, bcp47 as toBcp47 } from '@/lib/format'
import { activityText } from '@/lib/activity'
import { useLocale, useT } from '@/i18n/LocaleContext'

function parseLocalDate(iso: string): Date {
  return new Date(iso + 'T00:00:00')
}

function formatEventDate(iso: string, locale: string): string {
  const d = parseLocalDate(iso)
  const bcp47 = toBcp47(locale)
  const weekday = d.toLocaleString(bcp47, { weekday: 'short' })
  const month = d.toLocaleString(bcp47, { month: 'short' })
  return `${weekday}, ${d.getDate()} ${month} ${d.getFullYear()}`
}

// ── Event detail sheet ────────────────────────────────────────────────────
function EventDetailSheet({ event, onClose }: { event: UpcomingEvent; onClose: () => void }) {
  const { locale } = useLocale()
  const t = useT()
  const color = event.color ?? '#7c6cf6'
  const timeLabel = event.all_day
    ? t('All day')
    : event.time_end
      ? `${event.time} – ${event.time_end}`
      : event.time || '—'

  return (
    <>
      {/* Backdrop */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm"
        onClick={onClose}
      />
      {/* Sheet */}
      <motion.div
        initial={{ opacity: 0, scale: 0.96, y: 8 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        exit={{ opacity: 0, scale: 0.96, y: 8 }}
        transition={{ type: 'spring', stiffness: 380, damping: 30 }}
        className="fixed left-1/2 top-1/2 z-50 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-foreground/10 bg-background shadow-2xl"
      >
        {/* Color strip header */}
        <div
          className="relative flex items-start justify-between rounded-t-2xl px-5 py-4"
          style={{ background: color + '18' }}
        >
          <div className="flex items-center gap-3">
            <div
              className="grid size-10 shrink-0 place-items-center rounded-xl"
              style={{ background: color + '30', color }}
            >
              <CalendarDays className="size-5" />
            </div>
            <div>
              <p className="text-[0.7rem] font-semibold uppercase tracking-widest" style={{ color }}>
                {event.calendar_name}
              </p>
              <p className="text-[0.75rem] text-muted">{formatEventDate(event.date, locale)}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-muted transition hover:bg-foreground/8 hover:text-foreground"
          >
            <X className="size-4" />
          </button>
        </div>

        {/* Body */}
        <div className="space-y-4 px-5 py-5">
          <h2 className="text-xl font-semibold leading-snug tracking-tight">{event.summary}</h2>

          {/* Time */}
          <div className="flex items-center gap-2.5 text-sm text-muted">
            <Clock className="size-4 shrink-0" style={{ color }} />
            <span className="font-medium text-foreground">{timeLabel}</span>
          </div>

          {/* Location */}
          {event.location && (
            <div className="flex items-start gap-2.5 text-sm">
              <MapPin className="mt-0.5 size-4 shrink-0 text-muted" />
              <span className="text-muted">{event.location}</span>
            </div>
          )}

          {/* Description */}
          {event.description && (
            <div className="flex items-start gap-2.5 text-sm">
              <AlignLeft className="mt-0.5 size-4 shrink-0 text-muted" />
              <span className="whitespace-pre-line text-muted">{event.description}</span>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end border-t border-foreground/8 px-5 py-3">
          <Link
            to={`/calendar?date=${encodeURIComponent(event.date)}`}
            onClick={onClose}
            className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-accent ring-1 ring-accent/30 transition hover:bg-accent/8"
          >
            <ExternalLink className="size-3.5" />
            {t('Open in calendar')}
          </Link>
        </div>
      </motion.div>
    </>
  )
}

// ── Event row ─────────────────────────────────────────────────────────────
function EventRow({ event, isToday, delay, onClick }: {
  event: UpcomingEvent
  isToday: boolean
  delay: number
  onClick: () => void
}) {
  const { locale } = useLocale()
  const t = useT()
  const color = event.color ?? '#7c6cf6'
  const d = parseLocalDate(event.date)
  const dayNum = d.getDate()
  const bcp47 = toBcp47(locale)
  const monthAbbr = d.toLocaleString(bcp47, { month: 'short' })
  const weekdayAbbr = d.toLocaleString(bcp47, { weekday: 'short' })

  const timeDisplay = event.all_day
    ? null
    : event.time_end
      ? `${event.time} – ${event.time_end}`
      : event.time || null

  return (
    <motion.li
      initial={{ opacity: 0, y: 6 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay, ease: 'easeOut' }}
    >
      <button
        type="button"
        onClick={onClick}
        className="group flex w-full items-center gap-3.5 rounded-xl px-3 py-2.5 text-left transition hover:bg-foreground/[0.045] active:scale-[0.99]"
      >
        {/* Date badge */}
        <div
          className="flex h-11 w-10 shrink-0 flex-col items-center justify-center rounded-xl transition group-hover:scale-105"
          style={{ background: color + '20', color }}
        >
          <span className="text-[1rem] font-bold leading-none tabular-nums">{dayNum}</span>
          <span className="mt-0.5 text-[0.6rem] font-semibold uppercase tracking-wider opacity-75">{monthAbbr}</span>
        </div>

        {/* Content */}
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold">{event.summary}</p>
          <div className="mt-0.5 flex items-center gap-1.5">
            <span className="size-1.5 shrink-0 rounded-full" style={{ background: color }} />
            <span className="truncate text-xs text-muted">{event.calendar_name}</span>
          </div>
        </div>

        {/* Right: time + badge */}
        <div className="flex shrink-0 flex-col items-end gap-1">
          {timeDisplay ? (
            <span className="text-xs font-medium tabular-nums text-foreground/80">{timeDisplay}</span>
          ) : (
            <span className="text-[0.7rem] font-medium uppercase tracking-wide text-muted/60">{t('All day')}</span>
          )}
          {isToday
            ? <Badge tone="accent">{t('Today')}</Badge>
            : <span className="text-[0.7rem] font-medium uppercase tracking-wider text-muted/50">{weekdayAbbr}</span>
          }
        </div>
      </button>
    </motion.li>
  )
}

// ── Dashboard ─────────────────────────────────────────────────────────────
export default function Dashboard() {
  const { data: me } = useMe()
  const { data: settings } = useInstanceSettings()
  const { data: dash, isLoading: dashLoading } = useDashboard()
  const { data: calendars } = useCalendars()
  const { data: addressbooks } = useAddressBooks()
  const { data: appPasswords } = useAppPasswords()
  const { data: sessions } = useSessions()
  const isAdmin = me?.role === 'admin'
  const { data: status } = useAdminStatus(isAdmin)
  const [selectedEvent, setSelectedEvent] = useState<UpcomingEvent | null>(null)
  const { locale } = useLocale()
  const t = useT()

  const activeDevices = appPasswords?.filter((a) => !a.revoked_at).length ?? 0
  const activeSessions = sessions?.filter((s) => s.recently_active).length ?? 0

  // Real instance health for the hero pill: maintenance (everyone sees it) and,
  // for admins, database reachability. Anything else reads as operational.
  const heroHealth: { status: 'ok' | 'warn' | 'error'; label: string } = me?.maintenance
    ? { status: 'warn', label: t('Maintenance active') }
    : isAdmin && status?.database.ok === false
      ? { status: 'error', label: t('Database problem') }
      : { status: 'ok', label: t('Operational') }

  const n = appPasswords?.length ?? 0
  const pwSub = t(n === 1 ? '{n} app password' : '{n} app passwords', { n })

  return (
    <div className="space-y-6">
      {/* Hero */}
      <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}>
        <Card className="relative overflow-hidden">
          <div className="bg-grid absolute inset-0 opacity-70" />
          <div className="orb -right-16 -top-24 size-72 animate-float bg-accent/30" />
          <div className="orb -bottom-28 right-24 size-60 animate-float-slow bg-accent-2/20" />
          <div className="orb -left-24 top-8 size-56 animate-float-slow bg-accent-3/20" />
          <CardContent className="relative z-10 flex flex-wrap items-center justify-between gap-6 p-7 sm:p-9">
            <div className="flex items-center gap-4 sm:gap-5">
              <motion.div
                initial={{ rotate: -8, scale: 0.9, opacity: 0 }}
                animate={{ rotate: 0, scale: 1, opacity: 1 }}
                transition={{ type: 'spring', stiffness: 240, damping: 18 }}
                className="relative size-16 shrink-0 overflow-hidden rounded-2xl shadow-glow sm:size-20"
              >
                <LogoMark className="size-full object-cover" />
                <span className="absolute inset-0 rounded-2xl ring-1 ring-inset ring-white/15" />
              </motion.div>
              <div className="space-y-3.5">
                <div className="flex items-center gap-3">
                  <StatusPill status={heroHealth.status} label={heroHealth.label} />
                  {settings?.instance_name && (
                    <span className="text-xs font-medium uppercase tracking-wide text-muted">{settings.instance_name}</span>
                  )}
                </div>
                <h1 className="text-[2rem] font-semibold leading-tight tracking-tight sm:text-[2.4rem]">
                  {t('Welcome back,')}
                  <br className="hidden sm:block" />{' '}
                  <span className="gradient-text">{me?.display_name ?? '—'}</span>
                </h1>
                <p className="max-w-md text-sm leading-relaxed text-muted">
                  {t('Your private cloud is humming along. Calendars, contacts and devices stay in sync, end-to-end on your own server.')}
                </p>
              </div>
            </div>
            <div className="sheen-top relative w-full max-w-xs space-y-2 overflow-hidden rounded-2xl bg-foreground/5 p-4 ring-1 ring-inset ring-foreground/10 backdrop-blur-sm sm:w-auto">
              <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('CalDAV / CardDAV base')}</p>
              <code className="block break-all rounded-lg bg-foreground/[0.07] px-2.5 py-1.5 text-xs text-accent ring-1 ring-inset ring-accent/15">
                {me?.dav_base ?? '…'}
              </code>
              <Link to="/account" className="group inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
                {t('Device setup')} <ArrowRight className="size-3 transition-transform group-hover:translate-x-0.5" />
              </Link>
            </div>
          </CardContent>
        </Card>
      </motion.div>

      {/* Stats */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard index={0} label={t('Calendars')} value={calendars?.length ?? '—'} icon={CalendarDays} accent="accent" to="/calendar" />
        <StatCard index={1} label={t('Address books')} value={addressbooks?.length ?? '—'} icon={Contact} accent="info" to="/contacts" />
        <StatCard index={2} label={t('DAV Access')} value={activeDevices} icon={Smartphone} accent="success"
          sub={pwSub} to="/account" />
        <StatCard index={3} label={t('Sessions')} value={activeSessions} icon={MonitorSmartphone} accent="warning"
          sub={t('active in last 2 h')} to="/account" />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* Upcoming */}
        <Card className="lg:col-span-2">
          <div className="flex items-center justify-between px-5 pb-2 pt-5">
            <div className="flex items-center gap-2.5">
              <CalendarClock className="size-4 text-accent" />
              <h2 className="text-sm font-semibold">{t('Upcoming events')}</h2>
            </div>
            <Link to="/calendar" className="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
              {t('Open calendar')} <ExternalLink className="size-3" />
            </Link>
          </div>
          <CardContent className="pt-1">
            {dashLoading ? (
              <div className="space-y-2 px-1">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-14 w-full" />
                ))}
              </div>
            ) : dash?.upcoming.length ? (
              <ul className="space-y-0.5">
                {dash.upcoming.slice(0, 8).map((e, i) => (
                  <EventRow
                    key={e.uri + e.date + i}
                    event={e}
                    isToday={e.date === dash.today}
                    delay={i * 0.035}
                    onClick={() => setSelectedEvent(e)}
                  />
                ))}
              </ul>
            ) : (
              <EmptyState icon={CalendarClock} title={t('Nothing on the horizon')} description={t('No events in the next 7 days. Enjoy the calm.')} />
            )}
          </CardContent>
        </Card>

        {/* Activity */}
        <Card>
          <div className="flex items-center gap-2.5 p-5 pb-3">
            <ActivityIcon className="size-4 text-accent" />
            <h2 className="text-sm font-semibold">{t('Recent activity')}</h2>
          </div>
          <CardContent className="pt-0">
            {dash?.recent_activity?.length ? (
              <ul className="space-y-3">
                {dash.recent_activity.slice(0, 6).map((a, i) => (
                  <li key={i} className="flex gap-3">
                    <div className="mt-1.5 size-1.5 shrink-0 rounded-full bg-accent/70" />
                    <div className="min-w-0">
                      <p className="truncate text-sm">{activityText(a)}</p>
                      <p className="text-xs text-muted">{relativeTime(a.created_at, locale)}</p>
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="py-6 text-center text-sm text-muted">{t('No recent activity.')}</p>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Admin health row */}
      {isAdmin && (
        <div className="grid gap-4 sm:grid-cols-2">
          <Card hover>
            <CardContent className="flex items-center gap-4">
              <div className="grid size-12 place-items-center rounded-2xl bg-success/12 text-success ring-1 ring-inset ring-success/20">
                <ShieldCheck className="size-6" />
              </div>
              <div className="flex-1">
                <p className="text-sm font-semibold">{t('System health')}</p>
                <p className="text-xs text-muted">
                  {t('Database')} {status?.database.ok ? t('connected') : t('unavailable')} &middot;{' '}
                  {status?.maintenance.enabled ? t('maintenance on') : t('operational')}
                </p>
              </div>
              <StatusPill status={status?.database.ok && !status?.maintenance.enabled ? 'ok' : 'warn'} label="" />
            </CardContent>
          </Card>
          <Card hover>
            <CardContent className="flex items-center gap-4">
              <div className="grid size-12 place-items-center rounded-2xl bg-info/12 text-info ring-1 ring-inset ring-info/20">
                <DatabaseBackup className="size-6" />
              </div>
              <div className="flex-1">
                <p className="text-sm font-semibold">{t('Latest backup')}</p>
                <p className="text-xs text-muted">
                  {status?.latest_backup ? relativeTime(status.latest_backup.modified_at, locale) : t('No backups yet')}
                </p>
                {status?.backup_auto_frequency && status.backup_auto_frequency !== 'off' ? (
                  <p className="mt-0.5 text-xs text-success">
                    {t('Automatic: {freq}', {
                      freq: t(status.backup_auto_frequency === 'daily' ? 'Daily' : status.backup_auto_frequency === 'weekly' ? 'Weekly' : 'Monthly'),
                    })}
                  </p>
                ) : (
                  <p className="mt-0.5 text-xs text-muted/70">{t('Manual only')}</p>
                )}
              </div>
              <Link to="/admin/backups" className="text-xs font-medium text-accent hover:underline">
                {t('Manage')}
              </Link>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Event detail sheet (portal-free, z-index layered) */}
      <AnimatePresence>
        {selectedEvent && (
          <EventDetailSheet event={selectedEvent} onClose={() => setSelectedEvent(null)} />
        )}
      </AnimatePresence>
    </div>
  )
}
