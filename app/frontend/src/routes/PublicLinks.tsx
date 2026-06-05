import { useMemo, useState } from 'react'
import { Link2, Plus, Trash2, Globe, X } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { CopyButton } from '@/components/ui/CopyButton'
import { useToast } from '@/components/ui/Toast'
import { useCalendars, useCreatePublicLink, usePublicLinks, useRevokePublicLink, useDeletePublicLink, useMe } from '@/api/user'
import type { PublicLink } from '@/api/types'
import { ApiError } from '@/lib/api'
import { shortDate } from '@/lib/format'
import { useT, useLocale } from '@/i18n/LocaleContext'

export default function PublicLinks() {
  const toast = useToast()
  const t = useT()
  const { locale } = useLocale()
  const { data: me } = useMe()
  // Prefer the admin-configured public/base URL; fall back to the browser origin.
  const origin = (me?.public_base_url || window.location.origin).replace(/\/+$/, '')
  const publicUrl = (token: string) => `${origin}/public/calendar/${token}.ics`
  const { data: links, isLoading } = usePublicLinks()
  const { data: calendars } = useCalendars()
  const create = useCreatePublicLink()
  const revoke = useRevokePublicLink()
  const del = useDeletePublicLink()

  const [open, setOpen] = useState(false)
  const [calUri, setCalUri] = useState<string>()
  const [createdUrl, setCreatedUrl] = useState<string | null>(null)
  const [toRevoke, setToRevoke] = useState<number | null>(null)
  const [toDelete, setToDelete] = useState<PublicLink | null>(null)
  const [showRevoked, setShowRevoked] = useState(false)

  const ownedCals = useMemo(
    () => (calendars ?? []).filter((c) => c.permission === 'owner').map((c) => ({ value: c.uri, label: c.display_name })),
    [calendars],
  )

  const active = useMemo(() => (links ?? []).filter((l) => !l.revoked_at), [links])
  const revoked = useMemo(() => (links ?? []).filter((l) => l.revoked_at), [links])
  const visible = showRevoked ? [...active, ...revoked] : active

  async function doCreate() {
    if (!calUri) return toast.error(t('Select a calendar'))
    // Client-side guard: if this calendar already has an active link, show it
    // instead of round-tripping. The server enforces uniqueness regardless.
    const already = (links ?? []).find((l) => l.calendar_uri === calUri && !l.revoked_at)
    if (already?.token) {
      setOpen(false)
      setCreatedUrl(publicUrl(already.token))
      toast.success(t('Public link already exists'), t('Showing the existing link.'))
      return
    }
    try {
      const res = await create.mutateAsync({ calendar_uri: calUri })
      setOpen(false)
      setCreatedUrl(publicUrl(res.token))
      if (res.existing) toast.success(t('Public link already exists'), t('Showing the existing link.'))
    } catch (err) {
      toast.error(t('Could not create link'), err instanceof ApiError ? err.message : undefined)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('Public Links')}
        subtitle={t('Share a read-only calendar feed via a secret link')}
        icon={Link2}
        actions={<Button onClick={() => setOpen(true)}><Plus className="size-4" /> {t('New link')}</Button>}
      />

      {revoked.length > 0 && (
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => setShowRevoked((s) => !s)}
            className="text-xs font-medium text-muted transition hover:text-foreground"
          >
            {showRevoked ? t('Hide') : t('Show')} {t('revoked')} ({revoked.length})
          </button>
        </div>
      )}

      {isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : visible.length ? (
        <div className="grid gap-3">
          {visible.map((l, i) => (
            <motion.div key={l.id} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
              <Card className={`flex items-center justify-between gap-4 p-4 ${l.revoked_at ? 'opacity-60' : ''}`}>
                <div className="flex items-center gap-3">
                  <div className="grid size-10 place-items-center rounded-xl bg-accent/10 text-accent"><Globe className="size-5" /></div>
                  <div>
                    <p className="text-sm font-medium">{l.name || l.display_name || t('Calendar feed')}</p>
                    <p className="text-xs text-muted">
                      <code>…{l.token_prefix}</code> · {t('created {date}', { date: shortDate(l.created_at, locale) })}
                    </p>
                  </div>
                </div>
                {l.revoked_at ? (
                  <div className="flex shrink-0 items-center gap-2">
                    <Badge tone="danger">{t('revoked')}</Badge>
                    <Button variant="ghost" size="sm" onClick={() => setToDelete(l)}><X className="size-4" /> {t('Delete')}</Button>
                  </div>
                ) : (
                  <div className="flex shrink-0 items-center gap-1">
                    {l.token && <CopyButton value={publicUrl(l.token)} />}
                    <Button variant="ghost" size="sm" onClick={() => setToRevoke(l.id)}><Trash2 className="size-4" /> {t('Revoke')}</Button>
                  </div>
                )}
              </Card>
            </motion.div>
          ))}
        </div>
      ) : (
        <EmptyState
          icon={Link2}
          title={t('No public links')}
          description={t('Create a secret, read-only link to share a calendar with anyone.')}
          action={<Button onClick={() => setOpen(true)}><Plus className="size-4" /> {t('New link')}</Button>}
        />
      )}

      <Modal
        open={open}
        onOpenChange={setOpen}
        title={t('New public link')}
        description={t('Anyone with the link can subscribe to a read-only feed of this calendar.')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
            <Button onClick={doCreate} loading={create.isPending}>{t('Create link')}</Button>
          </>
        }
      >
        <Field label={t('Calendar')}>
          <Select value={calUri} onValueChange={setCalUri} options={ownedCals} placeholder={t('Select an owned calendar')} />
        </Field>
      </Modal>

      <Modal
        open={createdUrl !== null}
        onOpenChange={(o) => !o && setCreatedUrl(null)}
        title={t('Public link created')}
        description={t('Copy it now — the secret token is shown only once.')}
      >
        <div className="flex items-center justify-between gap-3 rounded-xl bg-foreground/5 p-3 ring-1 ring-inset ring-foreground/10">
          <code className="break-all text-xs text-accent">{createdUrl}</code>
          <CopyButton value={createdUrl ?? ''} />
        </div>
      </Modal>

      <ConfirmDialog
        open={toRevoke !== null}
        onOpenChange={(o) => !o && setToRevoke(null)}
        title={t('Revoke public link?')}
        description={t('The link will stop working immediately for everyone.')}
        confirmLabel={t('Revoke')}
        danger
        loading={revoke.isPending}
        onConfirm={async () => {
          try {
            await revoke.mutateAsync(toRevoke!)
            toast.success(t('Link revoked'))
          } catch (err) {
            toast.error(t('Could not revoke'), err instanceof ApiError ? err.message : undefined)
          }
          setToRevoke(null)
        }}
      />

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={t('Delete public link?')}
        description={t('The revoked link for "{name}" will be permanently removed from the list. This cannot be undone.', { name: toDelete?.display_name || toDelete?.name || t('this calendar') })}
        confirmLabel={t('Delete')}
        danger
        loading={del.isPending}
        onConfirm={async () => {
          try {
            await del.mutateAsync(toDelete!.id)
            toast.success(t('Link deleted'))
          } catch (err) {
            toast.error(t('Could not delete'), err instanceof ApiError ? err.message : undefined)
          }
          setToDelete(null)
        }}
      />
    </div>
  )
}
