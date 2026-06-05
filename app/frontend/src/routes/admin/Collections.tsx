import { useState } from 'react'
import { FolderTree, CalendarDays, Contact, Plus, Pencil, Share2, Trash2 } from 'lucide-react'
import { motion } from 'motion/react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Field } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import {
  useCollections,
  useCreateAddressBook,
  useCreateCalendar,
  useDeleteAddressBook,
  useDeleteCalendar,
  useUpdateAddressBook,
  useUpdateCalendar,
} from '@/api/admin'
import type { AdminCollection } from '@/api/types'
import { ApiError } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

type Kind = 'calendar' | 'addressbook'

export default function Collections() {
  const toast = useToast()
  const t = useT()
  const { data: groups, isLoading } = useCollections()
  const createCal = useCreateCalendar()
  const createAb = useCreateAddressBook()
  const updateCal = useUpdateCalendar()
  const updateAb = useUpdateAddressBook()
  const deleteCal = useDeleteCalendar()
  const deleteAb = useDeleteAddressBook()

  const [create, setCreate] = useState<{ kind: Kind; username: string } | null>(null)
  const [edit, setEdit] = useState<{ kind: Kind; username: string; col: AdminCollection } | null>(null)
  const [del, setDel] = useState<{ kind: Kind; username: string; col: AdminCollection } | null>(null)
  const [form, setForm] = useState({ uri: '', display_name: '', color: '#6366f1' })

  function openCreate(kind: Kind, username: string) {
    setForm({ uri: '', display_name: '', color: '#6366f1' })
    setCreate({ kind, username })
  }
  function openEdit(kind: Kind, username: string, col: AdminCollection) {
    setForm({ uri: col.uri, display_name: col.display_name, color: '#6366f1' })
    setEdit({ kind, username, col })
  }

  async function submitCreate() {
    if (!create || !form.uri || !form.display_name) return toast.error(t('URI and name are required'))
    try {
      if (create.kind === 'calendar')
        await createCal.mutateAsync({ username: create.username, uri: form.uri, display_name: form.display_name, color: form.color })
      else await createAb.mutateAsync({ username: create.username, uri: form.uri, display_name: form.display_name })
      toast.success(t('Collection created'))
      setCreate(null)
    } catch (e) {
      toast.error(t('Could not create'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function submitEdit() {
    if (!edit) return
    try {
      if (edit.kind === 'calendar')
        await updateCal.mutateAsync({ username: edit.username, uri: edit.col.uri, display_name: form.display_name, color: form.color })
      else await updateAb.mutateAsync({ username: edit.username, uri: edit.col.uri, display_name: form.display_name })
      toast.success(t('Collection updated'))
      setEdit(null)
    } catch (e) {
      toast.error(t('Could not update'), e instanceof ApiError ? e.message : undefined)
    }
  }

  const renderItem = (kind: Kind, username: string, col: AdminCollection) => (
    <div key={col.id} className="flex items-center justify-between gap-3 rounded-xl bg-foreground/5 p-3 ring-1 ring-inset ring-foreground/10">
      <div className="flex min-w-0 items-center gap-3">
        <div className="grid size-9 place-items-center rounded-lg bg-accent/10 text-accent">
          {kind === 'calendar' ? <CalendarDays className="size-4" /> : <Contact className="size-4" />}
        </div>
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">{col.display_name}</p>
          <p className="text-xs text-muted"><code>{col.uri}</code> · {t('{n} items', { n: col.object_count })}</p>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-1.5">
        {col.shares_count > 0 && (
          <Link to="/admin/sharing"><Badge tone="info"><Share2 className="size-3" /> {col.shares_count}</Badge></Link>
        )}
        <Button variant="ghost" size="icon" onClick={() => openEdit(kind, username, col)} aria-label={t('Edit')}><Pencil className="size-4" /></Button>
        <Button variant="ghost" size="icon" onClick={() => setDel({ kind, username, col })} aria-label={t('Delete')} title={t('Delete')} className="hover:text-danger"><Trash2 className="size-4" /></Button>
      </div>
    </div>
  )

  return (
    <div className="space-y-6">
      <PageHeader title={t('Collections')} subtitle={t('Calendars and address books per user')} icon={FolderTree} />

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <div className="space-y-4">
          {groups?.map((g, i) => (
            <motion.div key={g.username} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
              <Card>
                <CardContent className="space-y-4">
                  <div className="flex items-center justify-between">
                    <h3 className="flex items-center gap-2 text-sm font-semibold">
                      <span className="grid size-7 place-items-center rounded-lg gradient-accent text-xs font-semibold text-white">
                        {g.username.slice(0, 2).toUpperCase()}
                      </span>
                      {g.username}
                    </h3>
                  </div>

                  <div className="grid gap-4 lg:grid-cols-2">
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('Calendars')}</p>
                        <Button variant="subtle" size="sm" onClick={() => openCreate('calendar', g.username)}><Plus className="size-3.5" /> {t('Add')}</Button>
                      </div>
                      {g.calendars.length ? g.calendars.map((c) => renderItem('calendar', g.username, c)) : <p className="text-xs text-muted">{t('None')}</p>}
                    </div>
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted">{t('Address books')}</p>
                        <Button variant="subtle" size="sm" onClick={() => openCreate('addressbook', g.username)}><Plus className="size-3.5" /> {t('Add')}</Button>
                      </div>
                      {g.addressbooks.length ? g.addressbooks.map((a) => renderItem('addressbook', g.username, a)) : <p className="text-xs text-muted">{t('None')}</p>}
                    </div>
                  </div>
                </CardContent>
              </Card>
            </motion.div>
          ))}
        </div>
      )}

      <Modal
        open={create !== null}
        onOpenChange={(o) => !o && setCreate(null)}
        title={`${create?.kind === 'calendar' ? t('New calendar') : t('New address book')} · ${create?.username ?? ''}`}
        footer={<><Button variant="ghost" onClick={() => setCreate(null)}>{t('Cancel')}</Button><Button onClick={submitCreate} loading={createCal.isPending || createAb.isPending}>{t('Create')}</Button></>}
      >
        <div className="space-y-4">
          <Field label="URI" hint={t('Lowercase identifier, e.g. work or personal.')}><Input value={form.uri} onChange={(e) => setForm({ ...form, uri: e.target.value })} autoFocus /></Field>
          <Field label={t('Display name')}><Input value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} /></Field>
          {create?.kind === 'calendar' && (
            <Field label={t('Color')}><input type="color" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} className="size-10 cursor-pointer rounded-lg bg-transparent ring-1 ring-inset ring-foreground/10" /></Field>
          )}
        </div>
      </Modal>

      <Modal
        open={edit !== null}
        onOpenChange={(o) => !o && setEdit(null)}
        title={`${t('Edit')} · ${edit?.col.uri ?? ''}`}
        footer={<><Button variant="ghost" onClick={() => setEdit(null)}>{t('Cancel')}</Button><Button onClick={submitEdit} loading={updateCal.isPending || updateAb.isPending}>{t('Save')}</Button></>}
      >
        <div className="space-y-4">
          <Field label={t('Display name')}><Input value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} autoFocus /></Field>
          {edit?.kind === 'calendar' && (
            <Field label={t('Color')}><input type="color" value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} className="size-10 cursor-pointer rounded-lg bg-transparent ring-1 ring-inset ring-foreground/10" /></Field>
          )}
        </div>
      </Modal>

      <ConfirmDialog
        open={del !== null}
        onOpenChange={(o) => !o && setDel(null)}
        title={del?.kind === 'calendar' ? t('Delete calendar?') : t('Delete address book?')}
        description={t('"{name}" ({count} items) belonging to {user} will be permanently removed, along with all its contents and shares. This cannot be undone.', { name: del?.col.display_name ?? '', count: del?.col.object_count ?? 0, user: del?.username ?? '' })}
        confirmLabel={t('Delete')}
        danger
        loading={deleteCal.isPending || deleteAb.isPending}
        onConfirm={async () => {
          if (!del) return
          try {
            if (del.kind === 'calendar') await deleteCal.mutateAsync({ username: del.username, uri: del.col.uri })
            else await deleteAb.mutateAsync({ username: del.username, uri: del.col.uri })
            toast.success(t('Collection deleted'))
          } catch (e) {
            toast.error(t('Could not delete'), e instanceof ApiError ? e.message : undefined)
          }
          setDel(null)
        }}
      />
    </div>
  )
}
