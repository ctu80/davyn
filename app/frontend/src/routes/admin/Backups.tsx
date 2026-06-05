import { useEffect, useState } from 'react'
import { DatabaseBackup, Plus, Download, HardDrive, Trash2, Clock } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Select } from '@/components/ui/Select'
import { Input, Field } from '@/components/ui/Input'
import { EmptyState } from '@/components/ui/EmptyState'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import { useReauth } from '@/components/ReauthProvider'
import {
  useBackups,
  useCreateBackup,
  useDeleteBackup,
  useBackupConfig,
  useSaveBackupConfig,
} from '@/api/admin'
import type { BackupFrequency } from '@/api/types'
import { ApiError } from '@/lib/api'
import { bytes, dateTime, relativeTime } from '@/lib/format'
import { useT, useLocale } from '@/i18n/LocaleContext'

export default function Backups() {
  const toast = useToast()
  const t = useT()
  const { locale } = useLocale()
  const reauth = useReauth()
  const { data: backups, isLoading } = useBackups()
  const create = useCreateBackup()
  const del = useDeleteBackup()

  // Filenames queued for deletion (one row, or a whole multi-selection).
  const [pendingDelete, setPendingDelete] = useState<string[] | null>(null)
  const [selected, setSelected] = useState<Set<string>>(new Set())

  async function doCreate() {
    try {
      await create.mutateAsync()
      toast.success(t('Backup created'))
    } catch (e) {
      toast.error(t('Backup failed'), e instanceof ApiError ? e.message : undefined)
    }
  }

  function toggle(filename: string) {
    setSelected((prev) => {
      const next = new Set(prev)
      next.has(filename) ? next.delete(filename) : next.add(filename)
      return next
    })
  }

  const allFilenames = backups?.map((b) => b.filename) ?? []
  const allSelected = allFilenames.length > 0 && allFilenames.every((f) => selected.has(f))
  function toggleAll() {
    setSelected(allSelected ? new Set() : new Set(allFilenames))
  }

  async function confirmDelete() {
    const names = pendingDelete ?? []
    try {
      await reauth.run(() => del.mutateAsync(names))
      toast.success(names.length > 1 ? t('Backups deleted') : t('Backup deleted'))
      setSelected((prev) => {
        const next = new Set(prev)
        names.forEach((n) => next.delete(n))
        return next
      })
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) {
        setPendingDelete(null)
        return
      }
      toast.error(t('Could not delete backup'), e instanceof ApiError ? e.message : undefined)
    }
    setPendingDelete(null)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('Backups')}
        subtitle={t('Point-in-time SQLite snapshots')}
        icon={DatabaseBackup}
        actions={
          <Button onClick={doCreate} loading={create.isPending}>
            <Plus className="size-4" /> {t('Create backup now')}
          </Button>
        }
      />

      <AutomaticBackups />

      {isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : backups?.length ? (
        <div className="space-y-3">
          <div className="flex items-center justify-between px-1">
            <label className="flex cursor-pointer items-center gap-2 text-sm text-muted-strong">
              <input
                type="checkbox"
                checked={allSelected}
                onChange={toggleAll}
                className="size-4 cursor-pointer rounded border-foreground/20 bg-transparent text-accent accent-accent"
              />
              {selected.size > 0 ? t('{n} selected', { n: selected.size }) : t('Select all')}
            </label>
            {selected.size > 0 && (
              <Button
                variant="danger"
                size="sm"
                onClick={() => setPendingDelete([...selected])}
              >
                <Trash2 className="size-4" /> {t('Delete selected')} ({selected.size})
              </Button>
            )}
          </div>

          <div className="grid gap-3">
            {backups.map((b, i) => (
              <motion.div key={b.filename} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03 }}>
                <Card className="flex items-center justify-between gap-4 p-4">
                  <div className="flex min-w-0 items-center gap-3">
                    <input
                      type="checkbox"
                      checked={selected.has(b.filename)}
                      onChange={() => toggle(b.filename)}
                      className="size-4 shrink-0 cursor-pointer rounded border-foreground/20 bg-transparent text-accent accent-accent"
                    />
                    <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-info/10 text-info"><HardDrive className="size-5" /></div>
                    <div className="min-w-0">
                      <p className="truncate font-mono text-sm">{b.filename}</p>
                      <p className="text-xs text-muted">{b.size_human ?? bytes(b.size)} · {dateTime(b.modified_at, locale)}</p>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-1.5">
                    <a
                      href={`/api/admin/backups/download?file=${encodeURIComponent(b.filename)}`}
                      className="inline-flex h-9 items-center gap-1.5 rounded-xl bg-foreground/5 px-3 text-sm text-muted-strong ring-1 ring-inset ring-foreground/10 transition hover:bg-foreground/10 hover:text-foreground"
                    >
                      <Download className="size-4" /> {t('Download')}
                    </a>
                    <Button variant="ghost" size="sm" onClick={() => setPendingDelete([b.filename])}>
                      <Trash2 className="size-4" /> {t('Delete')}
                    </Button>
                  </div>
                </Card>
              </motion.div>
            ))}
          </div>
        </div>
      ) : (
        <EmptyState
          icon={DatabaseBackup}
          title={t('No backups yet')}
          description={t('Create your first snapshot. Backups capture the full database.')}
          action={<Button onClick={doCreate} loading={create.isPending}><Plus className="size-4" /> {t('Create backup now')}</Button>}
        />
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(o) => !o && setPendingDelete(null)}
        title={(pendingDelete?.length ?? 0) > 1 ? t('Delete backups?') : t('Delete backup?')}
        description={
          (pendingDelete?.length ?? 0) > 1
            ? t('{n} backups will be permanently removed from the server. This cannot be undone.', { n: pendingDelete?.length ?? 0 })
            : t('"{name}" will be permanently removed from the server. This cannot be undone.', { name: pendingDelete?.[0] ?? '' })
        }
        confirmLabel={(pendingDelete?.length ?? 0) > 1 ? t('Delete selected') : t('Delete backup')}
        danger
        loading={del.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  )
}

function AutomaticBackups() {
  const t = useT()
  const { locale } = useLocale()
  const toast = useToast()
  const { data: config } = useBackupConfig()
  const save = useSaveBackupConfig()

  const [freq, setFreq] = useState<BackupFrequency>('weekly')
  const [retention, setRetention] = useState(30)
  const [keepForever, setKeepForever] = useState(false)
  const [minKeep, setMinKeep] = useState(5)

  useEffect(() => {
    if (!config) return
    setFreq(config.frequency)
    setKeepForever(config.retention_days === 0)
    setRetention(config.retention_days === 0 ? 30 : config.retention_days)
    setMinKeep(config.min_keep)
  }, [config])

  async function submit() {
    const retention_days = keepForever ? 0 : Math.min(3650, Math.max(1, retention || 1))
    const min_keep = Math.min(100, Math.max(1, minKeep || 1))
    try {
      await save.mutateAsync({ frequency: freq, retention_days, min_keep })
      toast.success(t('Backup schedule saved'))
    } catch (e) {
      toast.error(t('Could not save'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <Card className="space-y-5 p-5">
      <div className="flex items-center gap-3">
        <div className="grid size-10 place-items-center rounded-xl bg-accent/10 text-accent"><Clock className="size-5" /></div>
        <div>
          <p className="text-sm font-semibold">{t('Automatic backups')}</p>
          <p className="text-xs text-muted">{t('Runs on server activity — no extra setup needed.')}</p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <Field label={t('Frequency')}>
          <Select
            value={freq}
            onValueChange={(v) => setFreq(v as BackupFrequency)}
            options={[
              { value: 'off', label: t('Off') },
              { value: 'daily', label: t('Daily') },
              { value: 'weekly', label: t('Weekly (recommended)') },
              { value: 'monthly', label: t('Monthly') },
            ]}
          />
        </Field>

        <Field label={t('Keep backups for')} hint={t('Days before older backups are pruned.')}>
          <Input
            type="number"
            min={1}
            max={3650}
            value={keepForever ? '' : retention}
            disabled={keepForever}
            placeholder={keepForever ? t('Forever') : undefined}
            onChange={(e) => setRetention(parseInt(e.target.value, 10) || 0)}
          />
          <label className="mt-2 flex cursor-pointer items-center gap-2 text-xs text-muted">
            <input
              type="checkbox"
              checked={keepForever}
              onChange={(e) => setKeepForever(e.target.checked)}
              className="size-3.5 cursor-pointer rounded border-foreground/20 bg-transparent text-accent accent-accent"
            />
            {t('Keep forever')}
          </label>
        </Field>

        <Field label={t('Always keep at least')} hint={t('Most recent backups, never pruned.')}>
          <Input
            type="number"
            min={1}
            max={100}
            value={minKeep}
            onChange={(e) => setMinKeep(parseInt(e.target.value, 10) || 1)}
          />
        </Field>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="text-xs text-muted">
          {config?.auto_active ? (
            <>
              <Badge tone="success">{t('On')}</Badge>{' '}
              {config.last_run_at
                ? t('Last automatic backup {when}.', { when: relativeTime(config.last_run_at, locale) })
                : t('No automatic backup yet.')}
              {config.next_due_at && <> · {t('Next {when}.', { when: relativeTime(config.next_due_at, locale) })}</>}
            </>
          ) : (
            <Badge tone="neutral">{t('Off')}</Badge>
          )}
        </div>
        <Button onClick={submit} loading={save.isPending}>{t('Save schedule')}</Button>
      </div>
    </Card>
  )
}
