import { useMemo, useState } from 'react'
import { Share2, Trash2, UserPlus, CalendarDays, Contact, Crown, Info } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import { useReauth } from '@/components/ReauthProvider'
import { useCollections, useRemoveShare, useSetShare, useShares, useUsers } from '@/api/admin'
import type { AdminCollection, AdminUser } from '@/api/types'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useT } from '@/i18n/LocaleContext'

type CollType = 'calendar' | 'addressbook'

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

function CollectionShareCard({ col, type, users, index }: { col: AdminCollection; type: CollType; users: AdminUser[]; index: number }) {
  const toast = useToast()
  const t = useT()
  const reauth = useReauth()
  const { data, isLoading } = useShares(type, col.id, true)
  const setShare = useSetShare()
  const removeShare = useRemoveShare()
  const [addUser, setAddUser] = useState<string>()

  const shares = data?.shares ?? []
  const sharedNames = new Set(shares.map((s) => s.username))
  const available = users.filter((u) => u.username !== col.owner_username && !sharedNames.has(u.username))

  async function set(username: string, permission: string) {
    try {
      await reauth.run(() => setShare.mutateAsync({ collection_type: type, collection_id: col.id, username, permission }))
      toast.success(t('Share updated'), `${username} · ${permission.replace('_', ' ')}`)
      setAddUser(undefined)
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not set share'), e instanceof ApiError ? e.message : undefined)
    }
  }
  async function remove(username: string) {
    try {
      await reauth.run(() => removeShare.mutateAsync({ collection_type: type, collection_id: col.id, username }))
      toast.success(t('Share removed'), username)
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
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
            <Badge tone="neutral"><Crown className="mr-1 inline size-3" />{col.owner_username}</Badge>
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

          {available.length > 0 && (
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
          )}
        </CardContent>
      </Card>
    </motion.div>
  )
}

const ALL_USERS = '__all__'

export default function Sharing() {
  const t = useT()
  const { data: groups, isLoading } = useCollections()
  const { data: users } = useUsers()
  const [ownerFilter, setOwnerFilter] = useState<string>(ALL_USERS)

  const allCalendars = useMemo(
    () => (groups ?? []).flatMap((g) => g.calendars),
    [groups],
  )
  const allAddressbooks = useMemo(
    () => (groups ?? []).flatMap((g) => g.addressbooks),
    [groups],
  )
  const userList = users ?? []

  // Owners that actually have at least one collection — drives the filter options.
  const owners = useMemo(() => {
    const set = new Set<string>()
    for (const c of allCalendars) set.add(c.owner_username)
    for (const a of allAddressbooks) set.add(a.owner_username)
    return Array.from(set).sort()
  }, [allCalendars, allAddressbooks])

  const showAll = ownerFilter === ALL_USERS
  const calendars = showAll ? allCalendars : allCalendars.filter((c) => c.owner_username === ownerFilter)
  const addressbooks = showAll ? allAddressbooks : allAddressbooks.filter((a) => a.owner_username === ownerFilter)

  const empty = !isLoading && allCalendars.length === 0 && allAddressbooks.length === 0
  const filteredEmpty = !empty && calendars.length === 0 && addressbooks.length === 0

  const ownerOptions = useMemo(() => {
    const byName = new Map(userList.map((u) => [u.username, u.display_name]))
    return [
      { value: ALL_USERS, label: t('All users') },
      ...owners.map((o) => ({ value: o, label: byName.get(o) ? `${byName.get(o)} · ${o}` : o })),
    ]
  }, [owners, userList, t])

  return (
    <div className="space-y-6">
      <PageHeader title={t('Sharing')} subtitle={t('Grant users access to collections')} icon={Share2} />

      <Card>
        <CardContent className="flex gap-3 py-3 text-xs text-muted">
          <Info className="size-4 shrink-0 text-accent" />
          <p>
            <strong className="text-muted-strong">{t('Read only')}</strong> {t('lets a user view the collection.')}{' '}
            <strong className="text-muted-strong">{t('Read & write')}</strong> {t('additionally lets them add, edit and delete items. The owner always keeps full control.')}
          </p>
        </CardContent>
      </Card>

      {isLoading ? (
        <div className="space-y-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-32 w-full" />)}</div>
      ) : empty ? (
        <EmptyState icon={Share2} title={t('No collections yet')} description={t('Create calendars or address books first, then share them here.')} />
      ) : (
        <>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label className="text-xs font-medium text-muted">{t('Owner')}</label>
            <div className="w-full sm:w-72">
              <Select value={ownerFilter} onValueChange={setOwnerFilter} options={ownerOptions} placeholder={t('All users')} />
            </div>
          </div>

          {filteredEmpty ? (
            <EmptyState icon={Share2} title={t('Nothing for this user')} description={t('This user owns no calendars or address books.')} />
          ) : (
            <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
              <section className="space-y-3">
                <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
                  <CalendarDays className="size-4 text-accent" /> {t('Calendars')}
                  <span className="text-muted/70">({calendars.length})</span>
                </h2>
                {calendars.length > 0 ? (
                  calendars.map((c, i) => (
                    <CollectionShareCard key={`cal-${c.id}`} col={c} type="calendar" users={userList} index={i} />
                  ))
                ) : (
                  <p className="rounded-xl bg-foreground/5 px-3 py-2.5 text-xs text-muted ring-1 ring-inset ring-foreground/10">{t('No calendars.')}</p>
                )}
              </section>
              <section className="space-y-3">
                <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
                  <Contact className="size-4 text-accent" /> {t('Address books')}
                  <span className="text-muted/70">({addressbooks.length})</span>
                </h2>
                {addressbooks.length > 0 ? (
                  addressbooks.map((a, i) => (
                    <CollectionShareCard key={`ab-${a.id}`} col={a} type="addressbook" users={userList} index={i} />
                  ))
                ) : (
                  <p className="rounded-xl bg-foreground/5 px-3 py-2.5 text-xs text-muted ring-1 ring-inset ring-foreground/10">{t('No address books.')}</p>
                )}
              </section>
            </div>
          )}
        </>
      )}
    </div>
  )
}
