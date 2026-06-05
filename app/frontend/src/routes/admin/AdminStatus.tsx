import { useState } from 'react'
import {
  ShieldCheck,
  Users as UsersIcon,
  CalendarDays,
  Contact,
  CalendarClock,
  Database,
  DatabaseBackup,
  Wrench,
  Activity as ActivityIcon,
} from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { StatusPill } from '@/components/ui/StatusPill'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import { useAdminStatus, useSetMaintenance } from '@/api/admin'
import { ApiError } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { useLocale, useT } from '@/i18n/LocaleContext'

// Map ActivityLog action keys to translatable labels. Unknown keys fall back to a
// humanized form of the key itself, so new actions still read sensibly.
const ACTION_LABELS: Record<string, string> = {
  'admin.addressbook.delete': 'Address book deleted',
  'admin.app_password.create': 'App password created',
  'admin.app_password.revoke': 'App password revoked',
  'admin.backup.auto': 'Automatic backup',
  'admin.backup.config': 'Backup settings changed',
  'admin.backup.create': 'Backup created',
  'admin.backup.delete': 'Backup deleted',
  'admin.backup.download': 'Backup downloaded',
  'admin.calendar.delete': 'Calendar deleted',
  'admin.maintenance.on': 'Maintenance enabled',
  'admin.maintenance.off': 'Maintenance disabled',
  'admin.share.remove': 'Share removed',
  'admin.share.set': 'Share updated',
  'admin.tls.generate': 'Certificate generated',
  'admin.tls.generate_failed': 'Certificate generation failed',
  'admin.tls.http_mode': 'HTTP mode changed',
  'admin.tls.remove': 'Certificate removed',
  'admin.tls.upload': 'Certificate uploaded',
  'admin.tls.upload_failed': 'Certificate upload failed',
  'admin.tls.validate_failed': 'Certificate validation failed',
  'admin.user.change_password': 'User password changed',
  'admin.user.create': 'User created',
  'admin.user.delete': 'User deleted',
  'admin.user.set_active': 'User activation changed',
  'user.addressbook.create': 'Address book created',
  'user.addressbook.delete': 'Address book deleted',
  'user.addressbook.import': 'Address book imported',
  'user.calendar.create': 'Calendar created',
  'user.calendar.delete': 'Calendar deleted',
  'user.calendar.import': 'Calendar imported',
  'user.contact.create': 'Contact created',
  'user.contact.delete': 'Contact deleted',
  'user.contact.update': 'Contact updated',
  'user.event.create': 'Event created',
  'user.event.delete': 'Event deleted',
  'user.event.move': 'Event moved',
  'user.event.update': 'Event updated',
}

export default function AdminStatus() {
  const { data: s, isLoading } = useAdminStatus()
  const t = useT()
  const { locale } = useLocale()
  const c = s?.counts ?? {}
  const healthy = s?.database.ok && !s?.maintenance.enabled

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('System status')}
        subtitle={t('Health and footprint of your Davyn instance')}
        icon={ShieldCheck}
        actions={s && <StatusPill status={healthy ? 'ok' : 'warn'} label={healthy ? t('Operational') : t('Attention')} />}
      />

      {isLoading ? (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-24 w-full" />)}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          <StatCard index={0} label={t('Users')} value={c.users ?? 0} icon={UsersIcon} accent="accent" />
          <StatCard index={1} label={t('Calendars')} value={c.calendars ?? 0} icon={CalendarDays} accent="info" />
          <StatCard index={2} label={t('Address books')} value={c.addressbooks ?? 0} icon={Contact} accent="success" />
          <StatCard index={3} label={t('Events')} value={c.calendar_objects ?? 0} icon={CalendarClock} accent="accent" />
          <StatCard index={4} label={t('Contacts')} value={c.addressbook_objects ?? 0} icon={Contact} accent="warning" />
          <StatCard index={5} label={t('Backups')} value={c.backups ?? 0} icon={DatabaseBackup} accent="info" />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card hover>
          <CardContent className="flex items-center gap-4">
            <div className="grid size-12 place-items-center rounded-2xl bg-success/12 text-success ring-1 ring-inset ring-success/20">
              <Database className="size-6" />
            </div>
            <div>
              <p className="text-sm font-semibold">{t('Database')}</p>
              <p className="text-xs text-muted">{s?.database.ok ? t('Connected') : t('Unavailable')}</p>
            </div>
          </CardContent>
        </Card>
        <MaintenanceCard enabled={!!s?.maintenance.enabled} reason={s?.maintenance.reason ?? null} />
        <Card hover>
          <CardContent className="flex items-center gap-4">
            <div className="grid size-12 place-items-center rounded-2xl bg-info/12 text-info ring-1 ring-inset ring-info/20">
              <DatabaseBackup className="size-6" />
            </div>
            <div>
              <p className="text-sm font-semibold">{t('Latest backup')}</p>
              <p className="text-xs text-muted">
                {s?.latest_backup ? relativeTime(s.latest_backup.modified_at, locale) : t('None yet')}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <div className="flex items-center gap-2.5 p-5 pb-2">
          <ActivityIcon className="size-4 text-accent" />
          <h2 className="text-sm font-semibold">{t('Recent activity')}</h2>
        </div>
        <CardContent className="pt-0">
          {s?.recent_activity?.length ? (
            <ul className="space-y-3">
              {s.recent_activity.map((a, i) => (
                <li key={i} className="flex items-center justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="size-1.5 shrink-0 rounded-full bg-accent/70" />
                    <span className="truncate text-sm">{t(ACTION_LABELS[a.action] ?? a.action.replace(/[._]/g, ' '))}</span>
                  </div>
                  <Badge tone="neutral">{relativeTime(a.created_at, locale)}</Badge>
                </li>
              ))}
            </ul>
          ) : (
            <p className="py-4 text-center text-sm text-muted">{t('No activity recorded.')}</p>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function MaintenanceCard({ enabled, reason }: { enabled: boolean; reason: string | null }) {
  const t = useT()
  const toast = useToast()
  const set = useSetMaintenance()
  const [reasonInput, setReasonInput] = useState('')
  const [confirmEnable, setConfirmEnable] = useState(false)

  async function apply(next: boolean, why?: string) {
    try {
      await set.mutateAsync({ enabled: next, reason: why })
      toast.success(next ? t('Maintenance enabled') : t('Maintenance disabled'))
      setConfirmEnable(false)
      setReasonInput('')
    } catch (e) {
      toast.error(t('Could not change maintenance'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <Card>
      <CardContent className="space-y-3">
        <div className="flex items-center gap-4">
          <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-warning/12 text-warning ring-1 ring-inset ring-warning/20">
            <Wrench className="size-6" />
          </div>
          <div className="min-w-0">
            <p className="flex items-center gap-2 text-sm font-semibold">
              {t('Maintenance')}
              {enabled && <Badge tone="warning">{t('On')}</Badge>}
            </p>
            <p className="truncate text-xs text-muted">
              {enabled ? reason || t('Enabled') : t('Off')}
            </p>
          </div>
        </div>

        {enabled ? (
          <Button variant="ghost" size="sm" className="w-full" loading={set.isPending} onClick={() => apply(false)}>
            {t('Disable maintenance')}
          </Button>
        ) : (
          <div className="space-y-2">
            <Input
              value={reasonInput}
              onChange={(e) => setReasonInput(e.target.value)}
              placeholder={t('Reason (optional)')}
              maxLength={200}
            />
            <Button variant="danger" size="sm" className="w-full" onClick={() => setConfirmEnable(true)}>
              {t('Enable maintenance')}
            </Button>
            <p className="text-[11px] leading-tight text-muted/80">
              {t('Sync clients receive 503 while on; this admin UI stays available.')}
            </p>
          </div>
        )}
      </CardContent>

      <ConfirmDialog
        open={confirmEnable}
        onOpenChange={setConfirmEnable}
        title={t('Pause sync for all clients?')}
        description={t('While maintenance is on, CalDAV/CardDAV clients receive HTTP 503. You can keep using this admin UI to turn it back off.')}
        confirmLabel={t('Enable maintenance')}
        danger
        loading={set.isPending}
        onConfirm={() => apply(true, reasonInput.trim() || undefined)}
      />
    </Card>
  )
}
