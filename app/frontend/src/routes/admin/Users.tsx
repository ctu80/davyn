import { useState } from 'react'
import { Users as UsersIcon, Plus, KeyRound, UserCheck, UserX, Trash2, AlertTriangle, Pencil } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Dialog'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import { useReauth } from '@/components/ReauthProvider'
import { useChangeUserPassword, useCreateUser, useDeleteUser, useRenameUser, useSetUserActive, useUsers } from '@/api/admin'
import { useMe } from '@/api/user'
import type { AdminUser, Role } from '@/api/types'
import { ApiError } from '@/lib/api'
import { shortDate } from '@/lib/format'
import { useT, useLocale } from '@/i18n/LocaleContext'

const roleTone = { admin: 'accent', user: 'info', read_only: 'neutral' } as const
const roleLabelKey: Record<string, string> = { admin: 'Admin', user: 'User', read_only: 'Read only' }

export default function Users() {
  const toast = useToast()
  const t = useT()
  const { locale } = useLocale()
  const reauth = useReauth()
  const { data: me } = useMe()
  const { data: users, isLoading } = useUsers()
  const createUser = useCreateUser()
  const setActive = useSetUserActive()
  const changePw = useChangeUserPassword()
  const deleteUser = useDeleteUser()
  const renameUser = useRenameUser()

  const [createOpen, setCreateOpen] = useState(false)
  const [form, setForm] = useState({ username: '', display_name: '', role: 'user' as Role, password: '' })
  const [pwTarget, setPwTarget] = useState<AdminUser | null>(null)
  const [newPw, setNewPw] = useState('')
  const [renameTarget, setRenameTarget] = useState<AdminUser | null>(null)
  const [renameValue, setRenameValue] = useState('')
  const [delTarget, setDelTarget] = useState<AdminUser | null>(null)
  const [delConfirm, setDelConfirm] = useState('')

  async function doRename() {
    if (!renameTarget) return
    const next = renameValue.trim()
    if (!next || next === renameTarget.display_name) { setRenameTarget(null); return }
    try {
      await reauth.run(() => renameUser.mutateAsync({ username: renameTarget.username, display_name: next }))
      toast.success(t('Display name updated'), renameTarget.username)
      setRenameTarget(null)
      setRenameValue('')
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not update display name'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function doDelete() {
    if (!delTarget || delConfirm !== delTarget.username) return
    try {
      await reauth.run(() => deleteUser.mutateAsync({ username: delTarget.username, confirm_username: delConfirm }))
      toast.success(t('User deleted'), delTarget.username)
      setDelTarget(null)
      setDelConfirm('')
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not delete user'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function doCreate() {
    if (!form.username || !form.display_name || !form.password) return toast.error(t('All fields are required'))
    try {
      await reauth.run(() => createUser.mutateAsync(form))
      toast.success(t('User created'), form.username)
      setCreateOpen(false)
      setForm({ username: '', display_name: '', role: 'user', password: '' })
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not create user'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function toggleActive(u: AdminUser) {
    try {
      await reauth.run(() => setActive.mutateAsync({ username: u.username, active: !u.active }))
      toast.success(u.active ? t('User deactivated') : t('User activated'), u.username)
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not update user'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function doChangePw() {
    if (newPw.length < 8) return toast.error(t('Password too short'), t('At least 8 characters.'))
    try {
      await reauth.run(() => changePw.mutateAsync({ username: pwTarget!.username, password: newPw }))
      toast.success(t('Password updated'), pwTarget!.username)
      setPwTarget(null)
      setNewPw('')
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) return
      toast.error(t('Could not change password'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('Users')}
        subtitle={t('Users and access')}
        icon={UsersIcon}
        actions={<Button onClick={() => setCreateOpen(true)}><Plus className="size-4" /> {t('New user')}</Button>}
      />

      {isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="grid gap-3">
          {users?.map((u, i) => (
            <motion.div key={u.username} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}>
              <Card className="flex flex-wrap items-center justify-between gap-4 p-4">
                <div className="flex items-center gap-3">
                  <div className="grid size-11 place-items-center rounded-xl gradient-accent text-sm font-semibold text-white">
                    {u.display_name.slice(0, 2).toUpperCase()}
                  </div>
                  <div>
                    <p className="text-sm font-medium">
                      {u.display_name} <span className="text-muted">· {u.username}</span>
                    </p>
                    <p className="text-xs text-muted">{t('Joined {date}', { date: shortDate(u.created_at, locale) })}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={roleTone[u.role]}>{t(roleLabelKey[u.role] ?? u.role)}</Badge>
                  <Badge tone={u.active ? 'success' : 'danger'}>{u.active ? t('active') : t('inactive')}</Badge>
                  <Button variant="ghost" size="sm" onClick={() => { setRenameTarget(u); setRenameValue(u.display_name) }}>
                    <Pencil className="size-4" /> {t('Rename')}
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => setPwTarget(u)}>
                    <KeyRound className="size-4" /> {t('Password')}
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => toggleActive(u)}>
                    {u.active ? <UserX className="size-4" /> : <UserCheck className="size-4" />}
                    {u.active ? t('Deactivate') : t('Activate')}
                  </Button>
                  {u.username !== me?.username && (
                    <Button variant="ghost" size="sm" className="text-danger hover:bg-danger/10" onClick={() => { setDelTarget(u); setDelConfirm('') }}>
                      <Trash2 className="size-4" /> {t('Delete')}
                    </Button>
                  )}
                </div>
              </Card>
            </motion.div>
          ))}
        </div>
      )}

      <Modal
        open={createOpen}
        onOpenChange={setCreateOpen}
        title={t('Create user')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>{t('Cancel')}</Button>
            <Button onClick={doCreate} loading={createUser.isPending}>{t('Create')}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label={t('Username')}><Input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoFocus /></Field>
          <Field label={t('Display name')}><Input value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} /></Field>
          <Field label={t('Role')}>
            <Select
              value={form.role}
              onValueChange={(v) => setForm({ ...form, role: v as Role })}
              options={[
                { value: 'user', label: t('User') },
                { value: 'admin', label: t('Admin') },
                { value: 'read_only', label: t('Read only') },
              ]}
            />
          </Field>
          <Field label={t('Password')}><Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></Field>
        </div>
      </Modal>

      <Modal
        open={renameTarget !== null}
        onOpenChange={(o) => { if (!o) { setRenameTarget(null); setRenameValue('') } }}
        title={`${t('Rename')} · ${renameTarget?.username ?? ''}`}
        footer={
          <>
            <Button variant="ghost" onClick={() => { setRenameTarget(null); setRenameValue('') }}>{t('Cancel')}</Button>
            <Button onClick={doRename} loading={renameUser.isPending}>{t('Save')}</Button>
          </>
        }
      >
        <Field label={t('Display name')} hint={t('The username stays the same.')}>
          <Input value={renameValue} onChange={(e) => setRenameValue(e.target.value)} maxLength={64} autoFocus onKeyDown={(e) => e.key === 'Enter' && doRename()} />
        </Field>
      </Modal>

      <Modal
        open={pwTarget !== null}
        onOpenChange={(o) => !o && setPwTarget(null)}
        title={`${t('Set password')} · ${pwTarget?.username ?? ''}`}
        footer={
          <>
            <Button variant="ghost" onClick={() => setPwTarget(null)}>{t('Cancel')}</Button>
            <Button onClick={doChangePw} loading={changePw.isPending}>{t('Update')}</Button>
          </>
        }
      >
        <Field label={t('New password')} hint={t('At least 8 characters.')}>
          <Input type="password" value={newPw} onChange={(e) => setNewPw(e.target.value)} autoFocus />
        </Field>
      </Modal>

      <Modal
        open={delTarget !== null}
        onOpenChange={(o) => { if (!o) { setDelTarget(null); setDelConfirm('') } }}
        title={`${t('Delete user')} · ${delTarget?.username ?? ''}`}
        footer={
          <>
            <Button variant="ghost" onClick={() => { setDelTarget(null); setDelConfirm('') }}>{t('Cancel')}</Button>
            <Button
              variant="danger"
              onClick={doDelete}
              loading={deleteUser.isPending}
              disabled={delConfirm !== delTarget?.username}
            >
              <Trash2 className="size-4" /> {t('Delete permanently')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="flex gap-3 rounded-xl bg-danger/10 p-3 text-sm text-danger ring-1 ring-inset ring-danger/20">
            <AlertTriangle className="size-5 shrink-0" />
            <div>
              {t('This permanently deletes {name} and all of their data: calendars, contacts, events, app passwords, sessions, shares and public links. This cannot be undone.', { name: delTarget?.display_name ?? '' })}
            </div>
          </div>
          <Field label={t('Type "{name}" to confirm', { name: delTarget?.username ?? '' })}>
            <Input
              value={delConfirm}
              onChange={(e) => setDelConfirm(e.target.value)}
              placeholder={delTarget?.username}
              autoFocus
              onKeyDown={(e) => e.key === 'Enter' && doDelete()}
            />
          </Field>
        </div>
      </Modal>
    </div>
  )
}
