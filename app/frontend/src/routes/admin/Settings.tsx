import { useEffect, useState } from 'react'
import { Settings as SettingsIcon, Check } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/Skeleton'
import { useToast } from '@/components/ui/Toast'
import { ColorPopover } from '@/components/ui/ColorPopover'
import { useTheme } from '@/lib/theme'
import { useSaveSettings, useSettings } from '@/api/admin'
import type { AppSettings } from '@/api/types'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useLocale, useT } from '@/i18n/LocaleContext'

const ACCENT_PRESETS = [
  { label: 'Indigo',   hex: '#7c6cf6' },
  { label: 'Cyan',     hex: '#06b6d4' },
  { label: 'Violet',   hex: '#8b5cf6' },
  { label: 'Teal',     hex: '#14b8a6' },
  { label: 'Emerald',  hex: '#10b981' },
  { label: 'Rose',     hex: '#f43f5e' },
  { label: 'Amber',    hex: '#f59e0b' },
]

export default function Settings() {
  const toast = useToast()
  const { theme, setTheme, setAccent } = useTheme()
  const { data, isLoading } = useSettings()
  const save = useSaveSettings()
  const [form, setForm] = useState<AppSettings | null>(null)
  const { setLocale } = useLocale()
  const t = useT()

  useEffect(() => {
    if (data) {
      setForm(data)
      // Sync accent from DB → DOM + localStorage when settings load so the
      // chosen accent is applied on any device that visits this page.
      if (data.accent_color) setAccent(data.accent_color)
    }
  }, [data])

  function handleAccentChange(hex: string) {
    setAccent(hex)
    setForm((f) => (f ? { ...f, accent_color: hex } : f))
  }

  async function submit() {
    if (!form) return
    try {
      await save.mutateAsync(form)
      toast.success(t('Settings saved'))
    } catch (e) {
      toast.error(t('Could not save settings'), e instanceof ApiError ? e.message : undefined)
    }
  }

  const currentAccent = form?.accent_color?.toLowerCase() ?? ''
  const isPreset = ACCENT_PRESETS.some((p) => p.hex === currentAccent)

  return (
    <div className="space-y-6">
      <PageHeader title={t('Settings')} subtitle={t('Instance branding and defaults')} icon={SettingsIcon} />
      {isLoading || !form ? (
        <Skeleton className="h-64 w-full max-w-lg" />
      ) : (
        <Card className="max-w-lg">
          <CardContent className="space-y-4">
            <Field label={t('Instance name')} hint={t('Shown across the app and in branding.')}>
              <Input value={form.instance_name} onChange={(e) => setForm({ ...form, instance_name: e.target.value })} maxLength={64} />
            </Field>
            <Field label={t('Default language')}>
              <Select
                value={form.default_locale}
                onValueChange={(v) => {
                  setForm({ ...form, default_locale: v })
                  setLocale(v)
                }}
                options={[{ value: 'en', label: t('English') }, { value: 'de', label: 'Deutsch' }]}
              />
            </Field>
            <Field label={t('Theme')} hint={t('Applies immediately and is remembered on this device.')}>
              <Select
                value={theme}
                onValueChange={(v) => {
                  setTheme(v as 'system' | 'dark' | 'light')
                  setForm((f) => (f ? { ...f, default_theme: v } : f))
                }}
                options={[
                  { value: 'system', label: t('System') },
                  { value: 'dark', label: t('Dark') },
                  { value: 'light', label: t('Light') },
                ]}
              />
            </Field>

            <Field label={t('Accent color')} hint={t('Applies immediately. Save to persist for all sessions on this device.')}>
              <div className="mt-1 space-y-3">
                {/* Preset swatches */}
                <div className="flex flex-wrap gap-2">
                  {ACCENT_PRESETS.map((p) => (
                    <button
                      key={p.hex}
                      type="button"
                      title={p.label}
                      onClick={() => handleAccentChange(p.hex)}
                      style={{ background: p.hex }}
                      className={cn(
                        'relative size-8 rounded-full transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60',
                        currentAccent === p.hex
                          ? 'scale-110 ring-2 ring-white/60 ring-offset-1 ring-offset-2'
                          : 'opacity-70 hover:opacity-100 hover:scale-110',
                      )}
                    >
                      {currentAccent === p.hex && (
                        <span className="absolute inset-0 flex items-center justify-center">
                          <Check className="size-[0.9rem] text-white drop-shadow" />
                        </span>
                      )}
                    </button>
                  ))}

                  {/* Custom color — Davyn-styled popover (palette + hex), never the
                      generic browser color dialog. */}
                  <ColorPopover
                    value={currentAccent}
                    onChange={handleAccentChange}
                    trigger={
                      <button
                        type="button"
                        title={t('Custom color')}
                        style={
                          !isPreset && currentAccent
                            ? { background: currentAccent }
                            : { background: 'conic-gradient(from 0deg, #f43f5e, #f59e0b, #10b981, #06b6d4, #7c6cf6, #f43f5e)' }
                        }
                        className={cn(
                          'relative size-8 rounded-full transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60',
                          !isPreset && currentAccent
                            ? 'scale-110 ring-2 ring-white/60 ring-offset-1 ring-offset-2'
                            : 'opacity-70 hover:opacity-100 hover:scale-110',
                        )}
                      >
                        {!isPreset && currentAccent && (
                          <span className="absolute inset-0 flex items-center justify-center">
                            <Check className="size-[0.9rem] text-white drop-shadow" />
                          </span>
                        )}
                      </button>
                    }
                  />
                </div>

                {/* Hex display */}
                <p className="font-mono text-[0.7rem] text-muted">{currentAccent || '—'}</p>
              </div>
            </Field>

            <Button onClick={submit} loading={save.isPending}>{t('Save settings')}</Button>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
