import { useMemo, useState } from 'react'
import { Share2, Trash2, UserPlus, CalendarDays, Contact, Info } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import {
  useAddressBooks,
  useCalendars,
  useRemoveUserShare,
  useSetUserShare,
  useShareTargets,
  useUserShares,
} from '@/api/user'
import type { AddressBook, Calendar, ShareTarget } from '@/api/types'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useT } from '@/i18n/LocaleContext'

type CollType = 'calendar' | 'addressbook'
type OwnedCollection = { id: number; display_name: string }

function Segmented({ value, onChange, disabled }: { value: string; onChange: (v: string) => void; disabled?: boolean }) {
  const t = useT()
  const opts = [
    { v: 'read_only', l: t('Read only') },
    { v: 'read_write', l: t('Read & write') },
  ]
  return (
    <div className="inline-flex rounded-lg bg-foreground/5 p-0.5 ring-1 ring-inset ring-foreground/10">
      {opts.map((o) => (
        <button
          key={o.v}
          type="button"
          disabled={disabled}
          onClick={() => onChange(o.v)}
          className={cn(
            'rounded-md px-2.5 py-1 text-xs font-medium transition disabled:opacity-50',
            value === o.v ? 'bg-accent text-accent-foreground shadow-glow' : 'text-muted hover:text-foreground',
          )}
        >
          {o.l}
        </button>
      ))}
    </div>
  )
}

function ShareCard({ col, type, targets, index }: { col: OwnedCollection; type: CollType; targets: ShareTarget[]; index: number }) {
  const toast = useToast()
  const t = useT()
  const { data, isLoading } = useUserShares(type, col.id, true)
  const setShare = useSetUserShare()
  const removeShare = useRemoveUserShare()
  const [addUser, setAddUser] = useState<string>()

  const shares = data?.shares ?? []
  const sharedNames = new Set(shares.map((s) => s.username))
  const available = targets.filter((u) => !sharedNames.has(u.username))

  async function set(username: string, permission: string) {
    try {
      await setShare.mutateAsync({ collection_type: type, collection_id: col.id, username, permission })
      toast.success(t('Share updated'), `${username} · ${permission.replace('_', ' ')}`)
      setAddUser(undefined)
    } catch (e) {
      toast.error(t('Could not set share'), e instanceof ApiError ? e.message : undefined)
    }
  }
  async function remove(username: string) {
    try {
      await removeShare.mutateAsync({ collection_type: type, collection_id: col.id, username })
      toast.success(t('Share removed'), username)
    } catch (e) {
      toast.error(t('Could not remove share'), e instanceof ApiError ? e.message : undefined)
    }
  }

  const Icon = type === 'calendar' ? CalendarDays : Contact

  return (
    <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(index * 0.03, 0.3) }}>
      <Card>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <div className="grid size-9 place-items-center rounded-xl bg-accent/10 text-accent"><Icon className="size-[1.1rem]" /></div>
            <h3 className="text-sm font-semibold">{col.display_name}</h3>
            <span className="ml-auto text-xs text-muted">
              {shares.length ? t('Shared with {n}', { n: shares.length }) : t('Not shared')}
            </span>
          </div>

          {isLoading ? (
            <Skeleton className="h-12 w-full" />
          ) : shares.length ? (
            <div className="space-y-2">
              {shares.map((s) => (
                <div key={s.username} className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-foreground/5 p-2.5 ring-1 ring-inset ring-foreground/10">
                  <p className="text-sm font-medium">{s.display_name} <span className="text-muted">· {s.username}</span></p>
                  <div className="flex items-center gap-2">
                    <Segmented value={s.permission} onChange={(p) => set(s.username, p)} disabled={setShare.isPending} />
                    <Button variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => remove(s.username)}><Trash2 className="size-4" /></Button>
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="rounded-xl bg-foreground/5 px-3 py-2.5 text-xs text-muted ring-1 ring-inset ring-foreground/10">{t('Not shared yet.')}</p>
          )}

          {available.length > 0 ? (
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <div className="flex-1">
                <Select
                  value={addUser}
                  onValueChange={setAddUser}
                  placeholder={t('Add a user…')}
                  options={available.map((u) => ({ value: u.username, label: `${u.display_name} · ${u.username}` }))}
                />
              </div>
              <Button onClick={() => addUser && set(addUser, 'read_only')} disabled={!addUser} loading={setShare.isPending}>
                <UserPlus className="size-4" /> {t('Share')}
              </Button>
            </div>
          ) : shares.length === 0 ? (
            <p className="text-xs text-muted">{t('No other users to share with yet.')}</p>
          ) : null}
        </CardContent>
      </Card>
    </motion.div>
  )
}

export default function Sharing() {
  const t = useT()
  const { data: calendars, isLoading: calLoading } = useCalendars()
  const { data: addressbooks, isLoading: abLoading } = useAddressBooks()
  const { data: targets } = useShareTargets()

  // Only collections the user OWNS can be shared; generated calendars (holidays,
  // birthdays) are excluded — they are read-only system collections.
  const ownedCalendars = useMemo<OwnedCollection[]>(
    () => (calendars ?? [])
      .filter((c: Calendar) => c.permission === 'owner' && !c.generated_type)
      .map((c) => ({ id: c.id, display_name: c.display_name })),
    [calendars],
  )
  const ownedBooks = useMemo<OwnedCollection[]>(
    () => (addressbooks ?? [])
      .filter((a: AddressBook) => a.permission === 'owner')
      .map((a) => ({ id: a.id, display_name: a.display_name })),
    [addressbooks],
  )

  const isLoading = calLoading || abLoading
  const empty = !isLoading && ownedCalendars.length === 0 && ownedBooks.length === 0
  const targetList = targets ?? []

  return (
    <div className="space-y-6">
      <PageHeader title={t('Sharing')} subtitle={t('Share your calendars and address books with other users')} icon={Share2} />

      <Card>
        <CardContent className="flex gap-3 py-3 text-xs text-muted">
          <Info className="size-4 shrink-0 text-accent" />
          <p>
            {t('Share collections you own with other users.')}{' '}
            <strong className="text-muted-strong">{t('Read only')}</strong> {t('lets a user view the collection.')}{' '}
            <strong className="text-muted-strong">{t('Read & write')}</strong> {t('additionally lets them add, edit and delete items. You always keep full control.')}
          </p>
        </CardContent>
      </Card>

      {isLoading ? (
        <div className="space-y-3">{Array.from({ length: 2 }).map((_, i) => <Skeleton key={i} className="h-32 w-full" />)}</div>
      ) : empty ? (
        <EmptyState icon={Share2} title={t('Nothing to share yet')} description={t('Create a calendar or address book first, then share it here.')} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
          <section className="space-y-3">
            <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
              <CalendarDays className="size-4 text-accent" /> {t('Calendars')}
              <span className="text-muted/70">({ownedCalendars.length})</span>
            </h2>
            {ownedCalendars.length > 0 ? (
              ownedCalendars.map((c, i) => <ShareCard key={`cal-${c.id}`} col={c} type="calendar" targets={targetList} index={i} />)
            ) : (
              <p className="rounded-xl bg-foreground/5 px-3 py-2.5 text-xs text-muted ring-1 ring-inset ring-foreground/10">{t('No calendars.')}</p>
            )}
          </section>
          <section className="space-y-3">
            <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
              <Contact className="size-4 text-accent" /> {t('Address books')}
              <span className="text-muted/70">({ownedBooks.length})</span>
            </h2>
            {ownedBooks.length > 0 ? (
              ownedBooks.map((a, i) => <ShareCard key={`ab-${a.id}`} col={a} type="addressbook" targets={targetList} index={i} />)
            ) : (
              <p className="rounded-xl bg-foreground/5 px-3 py-2.5 text-xs text-muted ring-1 ring-inset ring-foreground/10">{t('No address books.')}</p>
            )}
          </section>
        </div>
      )}
    </div>
  )
}
