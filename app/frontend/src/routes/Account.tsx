import { useState } from 'react'
import {
  UserCircle,
  KeyRound,
  Smartphone,
  MonitorSmartphone,
  Server,
  Trash2,
  Plus,
  X,
  SlidersHorizontal,
} from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Tabs, TabPanel } from '@/components/ui/Tabs'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { CopyButton } from '@/components/ui/CopyButton'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import {
  useAppPasswords,
  useChangePassword,
  useClearRevokedSessions,
  useCreateAppPassword,
  useDeleteAppPassword,
  useMe,
  useRevokeAppPassword,
  useRevokeSession,
  useSessions,
  useUpdateProfile,
} from '@/api/user'
import type { SessionInfo } from '@/api/types'
import { ApiError } from '@/lib/api'
import { dateTime, relativeTime } from '@/lib/format'
import { useLocale, useT } from '@/i18n/LocaleContext'
import { useTheme } from '@/lib/theme'

export default function Account() {
  const [tab, setTab] = useState('profile')
  const t = useT()
  const tabs = [
    { value: 'profile', label: t('Profile & DAV'), icon: UserCircle },
    { value: 'preferences', label: t('Preferences'), icon: SlidersHorizontal },
    { value: 'password', label: t('Password'), icon: KeyRound },
    { value: 'devices', label: t('DAV Devices'), icon: Smartphone },
    { value: 'sessions', label: t('Web Sessions'), icon: MonitorSmartphone },
  ]
  return (
    <div className="space-y-6">
      <PageHeader title={t('Account')} subtitle={t('Identity, devices and security')} icon={UserCircle} />
      <Tabs tabs={tabs} value={tab} onValueChange={setTab}>
        <TabPanel value="profile"><ProfileTab /></TabPanel>
        <TabPanel value="preferences"><PreferencesTab /></TabPanel>
        <TabPanel value="password"><PasswordTab /></TabPanel>
        <TabPanel value="devices"><DevicesTab /></TabPanel>
        <TabPanel value="sessions"><SessionsTab /></TabPanel>
      </Tabs>
    </div>
  )
}

function PreferencesTab() {
  const t = useT()
  const toast = useToast()
  const { data: me } = useMe()
  const update = useUpdateProfile()
  const { locale, setLocale } = useLocale()
  const { theme, setTheme } = useTheme()

  const [name, setName] = useState('')
  // Seed the editable name once me has loaded.
  const currentName = me?.display_name ?? ''
  const nameValue = name || currentName

  // Show the CURRENT live state from the theme/locale contexts (which reflect
  // quick-toggles and per-device state), not just the last value saved to the
  // account — otherwise the selector can disagree with what's actually applied.
  const localeValue = locale
  const themeValue = theme

  async function saveName() {
    const next = nameValue.trim()
    if (!next || next === currentName) return
    try {
      await update.mutateAsync({ display_name: next })
      toast.success(t('Display name updated'))
      setName('')
    } catch (e) {
      toast.error(t('Could not update display name'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function changeLocale(next: string) {
    setLocale(next) // apply immediately
    try {
      await update.mutateAsync({ locale: next })
    } catch (e) {
      toast.error(t('Could not save preference'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function changeTheme(next: string) {
    setTheme(next as 'light' | 'dark' | 'system') // apply immediately
    try {
      await update.mutateAsync({ theme: next })
    } catch (e) {
      toast.error(t('Could not save preference'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardContent className="space-y-4">
          <h3 className="text-sm font-semibold">{t('Display name')}</h3>
          <p className="text-xs text-muted">{t('The name shown across the app. Your username stays the same.')}</p>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <Field label={t('Display name')} className="flex-1">
              <Input value={nameValue} onChange={(e) => setName(e.target.value)} maxLength={64} />
            </Field>
            <Button onClick={saveName} loading={update.isPending} disabled={!nameValue.trim() || nameValue.trim() === currentName}>
              {t('Save')}
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-4">
          <h3 className="text-sm font-semibold">{t('Language & appearance')}</h3>
          <p className="text-xs text-muted">{t('These preferences apply to your account on every device.')}</p>
          <Field label={t('Language')}>
            <Select
              value={localeValue}
              onValueChange={changeLocale}
              options={[
                { value: 'en', label: 'English' },
                { value: 'de', label: 'Deutsch' },
              ]}
            />
          </Field>
          <Field label={t('Theme')}>
            <Select
              value={themeValue}
              onValueChange={changeTheme}
              options={[
                { value: 'system', label: t('System') },
                { value: 'light', label: t('Light') },
                { value: 'dark', label: t('Dark') },
              ]}
            />
          </Field>
        </CardContent>
      </Card>
    </div>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1 border-b border-foreground/8 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between">
      <span className="text-sm text-muted">{label}</span>
      <span className="text-sm font-medium">{children}</span>
    </div>
  )
}

// The backend emits DAV URLs relative to BASE_URL, which is empty by default
// (so they come back as "/dav/…"). DAV clients need a full URL — prefix the
// current origin when the value isn't already absolute.
function absoluteUrl(u?: string | null): string {
  if (!u) return ''
  return /^https?:\/\//i.test(u) ? u : window.location.origin + u
}

function ProfileTab() {
  const { data: me } = useMe()
  const t = useT()
  const dav = [
    { label: 'DAV base', value: absoluteUrl(me?.dav_base) },
    { label: 'CalDAV', value: absoluteUrl(me?.caldav_url) },
    { label: 'CardDAV', value: absoluteUrl(me?.carddav_url) },
  ]
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardContent>
          <h3 className="mb-2 text-sm font-semibold">{t('Profile')}</h3>
          <Row label={t('Display name')}>{me?.display_name ?? '—'}</Row>
          <Row label={t('Username')}>{me?.username ?? '—'}</Row>
          <Row label={t('Role')}>
            <Badge tone={me?.role === 'admin' ? 'accent' : 'neutral'}>{me?.role?.replace('_', ' ')}</Badge>
          </Row>
        </CardContent>
      </Card>
      <Card>
        <CardContent>
          <div className="mb-3 flex items-center gap-2">
            <Server className="size-4 text-accent" />
            <h3 className="text-sm font-semibold">{t('Device setup')}</h3>
          </div>
          <p className="mb-3 text-xs text-muted">
            {t('Add these URLs in DAVx5, Apple Calendar/Contacts or Thunderbird. Sign in with your username and an app password.')}
          </p>
          <div className="space-y-2.5">
            {dav.map((d) => (
              <div key={d.label} className="rounded-xl bg-foreground/5 p-2.5 ring-1 ring-inset ring-foreground/10">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium text-muted">{d.label}</span>
                  <CopyButton value={d.value} />
                </div>
                <code className="mt-1 block break-all text-xs text-accent">{d.value || '—'}</code>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function PasswordTab() {
  const toast = useToast()
  const change = useChangePassword()
  const t = useT()
  const [cur, setCur] = useState('')
  const [next, setNext] = useState('')

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (next.length < 8) return toast.error(t('Password too short'), t('Use at least 8 characters.'))
    try {
      await change.mutateAsync({ current_password: cur, new_password: next })
      setCur('')
      setNext('')
      toast.success(t('Password changed'), t('Other sessions were signed out.'))
    } catch (err) {
      toast.error(t('Could not change password'), err instanceof ApiError ? err.message : undefined)
    }
  }

  return (
    <Card className="max-w-lg">
      <CardContent>
        <form onSubmit={submit} className="space-y-4">
          <Field label={t('Current password')}>
            <Input type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} required />
          </Field>
          <Field label={t('New password')} hint={t('At least 8 characters. Signs out your other sessions.')}>
            <Input type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} required />
          </Field>
          <Button type="submit" loading={change.isPending}>{t('Update password')}</Button>
        </form>
      </CardContent>
    </Card>
  )
}

function DevicesTab() {
  const toast = useToast()
  const { data: list, isLoading } = useAppPasswords()
  const create = useCreateAppPassword()
  const revoke = useRevokeAppPassword()
  const del = useDeleteAppPassword()
  const t = useT()
  const { locale } = useLocale()
  const [name, setName] = useState('')
  const [created, setCreated] = useState<string | null>(null)
  const [toRevoke, setToRevoke] = useState<string | null>(null)
  const [toDelete, setToDelete] = useState<{ name: string; active: boolean } | null>(null)
  const [showRevoked, setShowRevoked] = useState(false)

  const active = (list ?? []).filter((ap) => !ap.revoked_at)
  const revoked = (list ?? []).filter((ap) => ap.revoked_at)
  const visible = showRevoked ? [...active, ...revoked] : active

  async function add(e: React.FormEvent) {
    e.preventDefault()
    if (!name.trim()) return
    try {
      const res = await create.mutateAsync(name.trim())
      setCreated(res.password)
      setName('')
    } catch (err) {
      toast.error(t('Could not create app password'), err instanceof ApiError ? err.message : undefined)
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardContent>
          <p className="mb-3 text-xs text-muted">
            {t('Each DAV device (phone, DAVx5, Apple Calendar…) connects with its own app password. The name you give it below is shown as the device name, along with when it was last used.')}
          </p>
          <form onSubmit={add} className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <Field label={t('New device / app password')} className="flex-1">
              <Input placeholder={t('e.g. iPhone · DAVx5')} value={name} onChange={(e) => setName(e.target.value)} />
            </Field>
            <Button type="submit" loading={create.isPending}>
              <Plus className="size-4" /> {t('Generate')}
            </Button>
          </form>
        </CardContent>
      </Card>

      {revoked.length > 0 && (
        <div className="flex justify-end">
          <button type="button" onClick={() => setShowRevoked((s) => !s)} className="text-xs font-medium text-muted transition hover:text-foreground">
            {showRevoked ? t('Hide') : t('Show')} revoked ({revoked.length})
          </button>
        </div>
      )}

      {isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : visible.length ? (
        <div className="grid gap-3">
          {visible.map((ap, i) => (
            <motion.div key={ap.name} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
              <Card className={`flex items-center justify-between gap-4 p-4 ${ap.revoked_at ? 'opacity-60' : ''}`}>
                <div className="flex items-center gap-3">
                  <div className="grid size-10 place-items-center rounded-xl bg-accent/10 text-accent">
                    <Smartphone className="size-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{ap.name}</p>
                    <p className="text-xs text-muted">
                      {ap.revoked_at
                        ? t('Revoked')
                        : ap.last_used_at
                          ? `${t('Last used')} ${relativeTime(ap.last_used_at, locale)}`
                          : `${t('Added')} ${relativeTime(ap.created_at, locale)} · ${t('never used')}`}
                      {ap.last_ip && !ap.revoked_at && <span> · {ap.last_ip}</span>}
                    </p>
                    {ap.last_user_agent && !ap.revoked_at && (
                      <p className="truncate text-[0.7rem] text-muted/80">{ap.last_user_agent}</p>
                    )}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  {ap.revoked_at ? <Badge tone="danger">{t('revoked')}</Badge> : (
                    <Button variant="ghost" size="sm" onClick={() => setToRevoke(ap.name)}>
                      <Trash2 className="size-4" /> {t('Revoke')}
                    </Button>
                  )}
                  <Button variant="ghost" size="sm" className="text-danger hover:bg-danger/10" onClick={() => setToDelete({ name: ap.name, active: !ap.revoked_at })}>
                    <X className="size-4" /> {t('Delete')}
                  </Button>
                </div>
              </Card>
            </motion.div>
          ))}
        </div>
      ) : (
        <EmptyState icon={Smartphone} title={t('No DAV devices yet')} description={t('Generate an app password to connect a device or DAV client.')} />
      )}

      <Modal
        open={created !== null}
        onOpenChange={(o) => !o && setCreated(null)}
        title={t('App password created')}
        description={t('Copy it now — it will not be shown again.')}
      >
        <div className="flex items-center justify-between gap-3 rounded-xl bg-foreground/5 p-3 ring-1 ring-inset ring-foreground/10">
          <code className="break-all text-sm text-accent">{created}</code>
          <CopyButton value={created ?? ''} />
        </div>
      </Modal>

      <ConfirmDialog
        open={toRevoke !== null}
        onOpenChange={(o) => !o && setToRevoke(null)}
        title={t('Revoke app password?')}
        description={t('"{name}" will stop working immediately on all devices using it.', { name: toRevoke ?? '' })}
        confirmLabel={t('Revoke')}
        danger
        loading={revoke.isPending}
        onConfirm={async () => {
          try {
            await revoke.mutateAsync(toRevoke!)
            toast.success(t('App password revoked'))
          } catch (err) {
            toast.error(t('Could not revoke'), err instanceof ApiError ? err.message : undefined)
          }
          setToRevoke(null)
        }}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={t('Delete app password?')}
        description={toDelete?.active
          ? t('"{name}" is still active. Deleting it removes it permanently and the device using it will no longer be able to sync.', { name: toDelete?.name ?? '' })
          : t('"{name}" will be permanently removed from the list.', { name: toDelete?.name ?? '' })}
        confirmLabel={t('Delete')}
        danger
        loading={del.isPending}
        onConfirm={async () => {
          try {
            await del.mutateAsync(toDelete!.name)
            toast.success(t('App password deleted'))
          } catch (err) {
            toast.error(t('Could not delete'), err instanceof ApiError ? err.message : undefined)
          }
          setToDelete(null)
        }}
      />
    </div>
  )
}

function SessionsTab() {
  const toast = useToast()
  const { data: sessions, isLoading } = useSessions()
  const revoke = useRevokeSession()
  const clearRevoked = useClearRevokedSessions()
  const t = useT()
  const { locale } = useLocale()
  const [target, setTarget] = useState<{ id: number; current: boolean } | null>(null)
  const [showOld, setShowOld] = useState(false)

  const all        = sessions ?? []
  const recent     = all.filter((s) => s.recently_active)
  const idle       = all.filter((s) => !s.revoked && !s.recently_active)
  const revokedSessions = all.filter((s) => s.revoked)
  const hasOld     = idle.length > 0 || revokedSessions.length > 0

  const visible = showOld ? [...recent, ...idle, ...revokedSessions] : recent

  return (
    <div className="space-y-3">
      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-3 py-3">
          <p className="text-xs text-muted">
            {t('Browser logins to the web UI — separate from DAV clients under "DAV Access". Active means seen in the last 2 h.')}
          </p>
          <div className="flex items-center gap-3">
            {hasOld && (
              <button type="button" onClick={() => setShowOld((v) => !v)} className="text-xs font-medium text-muted transition hover:text-foreground">
                {showOld ? t('Hide') : t('Show')} {t('idle / revoked')} ({idle.length + revokedSessions.length})
              </button>
            )}
            {hasOld && (
              <Button variant="ghost" size="sm" onClick={async () => {
                try {
                  const res = await clearRevoked.mutateAsync()
                  const msg = t(res.deleted === 1 ? '{n} old session removed' : '{n} old sessions removed', { n: res.deleted })
                  toast.success(t('Clean up'), msg)
                } catch (err) {
                  toast.error(t('Could not clean up'), err instanceof ApiError ? err.message : undefined)
                }
              }} loading={clearRevoked.isPending}>
                <Trash2 className="size-4" /> {t('Clean up')}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : recent.length === 0 && !showOld ? (
        <EmptyState icon={MonitorSmartphone} title={t('No active sessions')} description={t('No browser logins in the last 2 hours.')} />
      ) : (
        <div className="space-y-2">
          {visible.map((s, i) => {
            const iconBg = s.recently_active
              ? 'bg-success/10 text-success'
              : s.revoked
                ? 'bg-danger/10 text-danger'
                : 'bg-foreground/8 text-muted'
            const subText = s.revoked
              ? t('Revoked · signed in ') + dateTime(s.created_at, locale)
              : t('Last seen ') + relativeTime(s.last_seen_at, locale) + (s.ip ? ' · ' + s.ip : '')
            return (
              <motion.div key={s.id} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
                <Card className="flex items-center justify-between gap-4 p-4">
                  <div className="flex items-center gap-3">
                    <div className={`grid size-10 place-items-center rounded-xl ${iconBg}`}>
                      <MonitorSmartphone className="size-5" />
                    </div>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{s.user_agent || t('Unknown')}</p>
                      <p className="text-xs text-muted">{subText}</p>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {s.current && <Badge tone="success">{t('this device')}</Badge>}
                    {s.recently_active && !s.current && <Badge tone="success">{t('active')}</Badge>}
                    {!s.recently_active && !s.revoked && <Badge tone="neutral">{t('idle')}</Badge>}
                    {s.revoked
                      ? <Badge tone="danger">{t('revoked')}</Badge>
                      : <Button variant="ghost" size="sm" onClick={() => setTarget({ id: s.id, current: s.current })}>{t('Revoke')}</Button>
                    }
                  </div>
                </Card>
              </motion.div>
            )
          })}
        </div>
      )}

      <ConfirmDialog
        open={target !== null}
        onOpenChange={(o) => !o && setTarget(null)}
        title={target?.current ? t('Sign out this device?') : t('Revoke session?')}
        description={target?.current ? t('You will be returned to the login screen.') : t('That browser session will be signed out.')}
        confirmLabel={target?.current ? t('Sign out') : t('Revoke')}
        danger
        loading={revoke.isPending}
        onConfirm={async () => {
          try {
            const res = await revoke.mutateAsync(target!.id)
            if (res.logged_out) {
              window.location.href = '/login'
              return
            }
            toast.success(t('Session revoked'))
          } catch (err) {
            toast.error(t('Could not revoke session'), err instanceof ApiError ? err.message : undefined)
          }
          setTarget(null)
        }}
      />
    </div>
  )
}
