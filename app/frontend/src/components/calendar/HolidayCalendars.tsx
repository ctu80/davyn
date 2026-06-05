import { useEffect, useMemo, useState } from 'react'
import { CalendarPlus, RefreshCw, Trash2, Globe, Sparkles, Lock, Check } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Dialog'
import { Select } from '@/components/ui/Select'
import { Field } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useToast } from '@/components/ui/Toast'
import {
  useHolidayCalendars,
  useAddHolidayCalendar,
  useDeleteHolidayCalendar,
  useRegenerateHolidayCalendar,
  usePreviewHolidayCalendar,
} from '@/api/user'
import type { HolidayCountry, HolidaySubscription } from '@/api/types'
import { ApiError } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

const LOCALE_NAMES: Record<string, string> = {
  de_DE: 'Deutsch', de_AT: 'Deutsch (AT)', de_CH: 'Deutsch (CH)',
  fr_FR: 'Français', fr_CH: 'Français (CH)', fr_CA: 'Français (CA)', fr_BE: 'Français (BE)',
  it_IT: 'Italiano', it_CH: 'Italiano (CH)',
  nl_NL: 'Nederlands', nl_BE: 'Nederlands (BE)',
  es_ES: 'Español', da_DK: 'Dansk', sv_SE: 'Svenska', nb_NO: 'Norsk', fi_FI: 'Suomi',
  pl_PL: 'Polski', cs_CZ: 'Čeština',
  en_US: 'English (US)', en_GB: 'English (UK)', en_IE: 'English (IE)', en_AU: 'English (AU)', en_CA: 'English (CA)',
}
function localeLabel(l: string): string {
  return LOCALE_NAMES[l] ?? l
}

/** Button + modal to add and manage per-user holiday calendar subscriptions. */
export function HolidayCalendarsButton() {
  const t = useT()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="subtle" size="sm" onClick={() => setOpen(true)}>
        <CalendarPlus className="size-4" /> {t('Holiday calendars')}
      </Button>
      <HolidayModal open={open} onOpenChange={setOpen} />
    </>
  )
}

function HolidayModal({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const toast = useToast()
  const t = useT()
  const { data, isLoading } = useHolidayCalendars()
  const add = useAddHolidayCalendar()
  const del = useDeleteHolidayCalendar()
  const regen = useRegenerateHolidayCalendar()
  const preview = usePreviewHolidayCalendar()

  const countries = data?.catalog.countries ?? []
  const subscriptions = data?.subscriptions ?? []

  const [countryCode, setCountryCode] = useState('')
  const [providerKey, setProviderKey] = useState('')
  const [locale, setLocale] = useState('')
  const [toDelete, setToDelete] = useState<HolidaySubscription | null>(null)

  const country: HolidayCountry | undefined = useMemo(
    () => countries.find((c) => c.country_code === countryCode),
    [countries, countryCode],
  )

  // Default the country once the catalog arrives.
  useEffect(() => {
    if (!countryCode && countries.length) {
      setCountryCode(countries[0].country_code)
      setProviderKey(countries[0].national_provider_key)
      setLocale(countries[0].default_locale)
    }
  }, [countries, countryCode])

  // Region options: nationwide first, then each subdivision.
  const regionOptions = useMemo(() => {
    if (!country) return []
    const opts = [{ value: country.national_provider_key, label: t('Nationwide ({country})', { country: country.label }) }]
    for (const r of country.regions) opts.push({ value: r.provider_key, label: r.label })
    return opts
  }, [country])

  // Live preview of holiday counts whenever the chosen provider/locale changes.
  useEffect(() => {
    if (open && providerKey) preview.mutate({ provider_key: providerKey, locale: locale || undefined })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [providerKey, locale, open])

  function onCountry(code: string) {
    setCountryCode(code)
    const c = countries.find((x) => x.country_code === code)
    setProviderKey(c?.national_provider_key ?? code)
    setLocale(c?.default_locale ?? '')
  }

  async function onAdd() {
    if (!providerKey) return
    try {
      await add.mutateAsync({ provider_key: providerKey, locale: locale || undefined })
      toast.success(t('Holiday calendar added'))
    } catch (err) {
      toast.error(t('Could not add holiday calendar'), err instanceof ApiError ? err.message : undefined)
    }
  }

  async function onRegen(s: HolidaySubscription) {
    try {
      const r = await regen.mutateAsync(s.id)
      toast.success(t('Regenerated'), t('{n} events written', { n: r.generated }))
    } catch (err) {
      toast.error(t('Could not regenerate'), err instanceof ApiError ? err.message : undefined)
    }
  }

  const previewData = preview.data
  const alreadyAdded = subscriptions.some((s) => s.provider_key === providerKey)

  return (
    <>
      <Modal open={open} onOpenChange={onOpenChange} size="lg" title={t('Holiday calendars')}>
        <div className="space-y-6">
          {/* Add new */}
          <Card className="space-y-4 p-4">
            <div className="flex items-center gap-2 text-sm font-semibold">
              <Globe className="size-4 text-accent" /> {t('Add a holiday calendar')}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('Country')}>
                <Select
                  value={countryCode}
                  onValueChange={onCountry}
                  options={countries.map((c) => ({ value: c.country_code, label: c.label, group: c.group }))}
                  placeholder={t('Select country')}
                />
              </Field>
              {country?.has_regions && (
                <Field label={t('Region / state')}>
                  <Select value={providerKey} onValueChange={setProviderKey} options={regionOptions} />
                </Field>
              )}
              {country && country.supported_locales.length > 1 && (
                <Field label={t('Language')}>
                  <Select
                    value={locale}
                    onValueChange={setLocale}
                    options={country.supported_locales.map((l) => ({ value: l, label: localeLabel(l) }))}
                  />
                </Field>
              )}
            </div>

            <div className="flex items-center justify-between gap-3 rounded-xl bg-foreground/[0.03] px-3 py-2.5 text-xs ring-1 ring-inset ring-foreground/8">
              <span className="inline-flex items-center gap-1.5 text-muted">
                <Sparkles className="size-3.5 text-accent" />
                {preview.isPending ? (
                  <Spinner className="size-3.5" />
                ) : previewData ? (
                  t('{count} holidays in {year}, {count2} in {year2}', {
                    count: previewData.this_year.count,
                    year: previewData.this_year.year,
                    count2: previewData.next_year.count,
                    year2: previewData.next_year.year,
                  })
                ) : (
                  t('Pick a country to preview')
                )}
              </span>
              <Button size="sm" onClick={onAdd} loading={add.isPending} disabled={!providerKey}>
                {alreadyAdded ? t('Re-generate') : t('Add calendar')}
              </Button>
            </div>
            <p className="text-[0.7rem] text-muted">
              {t('Read-only calendar, synced over CalDAV. Future years are added automatically.')}
            </p>
          </Card>

          {/* Existing subscriptions */}
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted">{t('Your holiday calendars')}</p>
            {isLoading ? (
              <div className="grid place-items-center py-6 text-muted"><Spinner className="size-5" /></div>
            ) : subscriptions.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted">{t('No holiday calendars yet.')}</p>
            ) : (
              subscriptions.map((s) => (
                <div
                  key={s.id}
                  className="flex flex-wrap items-center gap-3 rounded-xl bg-foreground/[0.03] p-3 ring-1 ring-inset ring-foreground/8"
                >
                  <span className="size-2.5 shrink-0 rounded-full" style={{ background: s.color || '#16a34a' }} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{s.label}</p>
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.7rem] text-muted">
                      <span>{t('{n} events', { n: s.event_count })}</span>
                      {s.generated_years.length > 0 && (
                        <span>· {s.generated_years[0]}–{s.generated_years[s.generated_years.length - 1]}</span>
                      )}
                      {s.last_generated_at && <span>· {t('updated {date}', { date: s.last_generated_at.slice(0, 10) })}</span>}
                    </p>
                  </div>
                  <Badge tone="info"><Lock className="size-3" /> {t('Read-only')}</Badge>
                  {s.enabled ? <Badge tone="success"><Check className="size-3" /> {t('Active')}</Badge> : <Badge>{t('Disabled')}</Badge>}
                  <div className="flex shrink-0 gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={t('Regenerate')}
                      title={t('Regenerate')}
                      onClick={() => onRegen(s)}
                      loading={regen.isPending && regen.variables === s.id}
                    >
                      <RefreshCw className="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon" aria-label={t('Remove')} title={t('Remove')} onClick={() => setToDelete(s)}>
                      <Trash2 className="size-4 text-danger" />
                    </Button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={t('Remove holiday calendar?')}
        description={t('"{name}" and its generated events will be removed. You can add it again anytime.', { name: toDelete?.label ?? t('This calendar') })}
        confirmLabel={t('Remove')}
        danger
        loading={del.isPending}
        onConfirm={async () => {
          try {
            await del.mutateAsync(toDelete!.id)
            toast.success(t('Holiday calendar removed'))
          } catch (err) {
            toast.error(t('Could not remove'), err instanceof ApiError ? err.message : undefined)
          }
          setToDelete(null)
        }}
      />
    </>
  )
}
