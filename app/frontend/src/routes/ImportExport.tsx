import { useEffect, useMemo, useRef, useState } from 'react'
import { ArrowLeftRight, UploadCloud, Download } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { useToast } from '@/components/ui/Toast'
import { useAddressBooks, useCalendars, useImportUpload } from '@/api/user'
import { ApiError } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

export default function ImportExport() {
  const toast = useToast()
  const t = useT()
  const [type, setType] = useState<'calendar' | 'addressbook'>('calendar')
  const [collection, setCollection] = useState<string>()
  const fileRef = useRef<HTMLInputElement>(null)
  const upload = useImportUpload()

  const { data: calendars } = useCalendars()
  const { data: books } = useAddressBooks()

  // Import targets: collections you can write to (own or read-write shared),
  // excluding read-only/generated ones.
  const owned = useMemo(() => {
    const src = type === 'calendar' ? calendars : books
    return (src ?? [])
      .filter((c) => (c.permission === 'owner' || c.permission === 'read_write') && !('read_only' in c && c.read_only))
      .map((c) => ({ value: c.uri, label: c.display_name }))
  }, [type, calendars, books])

  useEffect(() => {
    setCollection(owned[0]?.value)
  }, [owned]) // owned is memoized, so this re-selects when the writable set changes (not just its length)

  async function doImport() {
    const files = Array.from(fileRef.current?.files ?? [])
    if (!collection) return toast.error(t('Select a collection'))
    if (files.length === 0) return toast.error(t('Choose a file'))
    try {
      const res = await upload.mutateAsync({ type, collection, files })
      const r = res.result
      const detail =
        files.length > 1
          ? t('{n} files · created {created}, updated {updated}, skipped {skipped}, errors {errors}', {
              n: files.length, created: r.created, updated: r.updated, skipped: r.skipped, errors: r.errors.length,
            })
          : t('Created {created}, updated {updated}, skipped {skipped}, errors {errors}', {
              created: r.created, updated: r.updated, skipped: r.skipped, errors: r.errors.length,
            })
      toast.success(t('Import complete'), detail)
      if (fileRef.current) fileRef.current.value = ''
    } catch (err) {
      toast.error(t('Import failed'), err instanceof ApiError ? err.message : undefined)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('Import / Export')} subtitle={t('Move your data in and out of Davyn')} icon={ArrowLeftRight} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-2">
              <UploadCloud className="size-4 text-accent" />
              <h3 className="text-sm font-semibold">{t('Upload & import')}</h3>
            </div>
            <p className="text-xs text-muted">{t('Upload one or more .ics / .vcf files (max 25 MB each). Existing entries with the same UID are updated; others are added.')}</p>
            <Field label={t('Type')}>
              <Select
                value={type}
                onValueChange={(v) => setType(v as 'calendar' | 'addressbook')}
                options={[
                  { value: 'calendar', label: t('Calendar (.ics)') },
                  { value: 'addressbook', label: t('Address book (.vcf)') },
                ]}
              />
            </Field>
            <Field label={t('Collection')}>
              <Select value={collection} onValueChange={setCollection} options={owned} placeholder={t('Select collection')} />
            </Field>
            <Field label={t('Files')}>
              <input
                ref={fileRef}
                type="file"
                multiple
                accept={type === 'calendar' ? '.ics' : '.vcf'}
                className="block w-full rounded-xl bg-foreground/5 text-sm text-muted ring-1 ring-inset ring-foreground/10 file:mr-3 file:border-0 file:bg-accent/15 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-accent hover:file:bg-accent/25"
              />
            </Field>
            <Button onClick={doImport} loading={upload.isPending}>
              <UploadCloud className="size-4" /> {t('Import')}
            </Button>
          </CardContent>
        </Card>

        <ExportCard />
      </div>
    </div>
  )
}

function ExportCard() {
  const t = useT()
  const [type, setType] = useState<'calendar' | 'addressbook'>('calendar')
  const [collection, setCollection] = useState<string>()
  const { data: calendars } = useCalendars()
  const { data: books } = useAddressBooks()

  // Export sources: any accessible collection (read-only is fine to export).
  const owned = useMemo(() => {
    const src = type === 'calendar' ? calendars : books
    return (src ?? []).map((c) => ({ value: c.uri, label: c.display_name }))
  }, [type, calendars, books])

  useEffect(() => {
    setCollection(owned[0]?.value)
  }, [owned]) // owned is memoized, so this re-selects when the accessible set changes

  const href = collection
    ? type === 'calendar'
      ? `/api/user/export/calendar?cal=${encodeURIComponent(collection)}`
      : `/api/user/export/addressbook?ab=${encodeURIComponent(collection)}`
    : undefined

  return (
    <Card>
      <CardContent className="space-y-4">
        <div className="flex items-center gap-2">
          <Download className="size-4 text-accent" />
          <h3 className="text-sm font-semibold">{t('Export')}</h3>
        </div>
        <p className="text-xs text-muted">{t('Download a whole calendar (.ics) or address book (.vcf). Individual events and contacts can be exported from their detail view.')}</p>
        <Field label={t('Type')}>
          <Select
            value={type}
            onValueChange={(v) => setType(v as 'calendar' | 'addressbook')}
            options={[
              { value: 'calendar', label: t('Calendar (.ics)') },
              { value: 'addressbook', label: t('Address book (.vcf)') },
            ]}
          />
        </Field>
        <Field label={t('Collection')}>
          <Select value={collection} onValueChange={setCollection} options={owned} placeholder={t('Select collection')} />
        </Field>
        <a
          href={href}
          download
          aria-disabled={!href}
          className={`inline-flex h-10 items-center gap-2 rounded-xl bg-accent px-4 text-sm font-medium text-white transition hover:bg-accent/90 ${href ? '' : 'pointer-events-none opacity-50'}`}
        >
          <Download className="size-4" /> {t('Download')}
        </a>
      </CardContent>
    </Card>
  )
}
