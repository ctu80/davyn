import { useState } from 'react'
import { Cake, RefreshCw, Lock, Check } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Dialog'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { useBirthdayCalendar, useRegenerateBirthdays, useToggleBirthdays } from '@/api/user'
import { ApiError } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

/** Button + modal to manage the generated, read-only "Birthdays" calendar. */
export function BirthdayCalendarButton() {
  const t = useT()
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button variant="subtle" size="sm" onClick={() => setOpen(true)}>
        <Cake className="size-4" /> {t('Birthdays')}
      </Button>
      <BirthdayModal open={open} onOpenChange={setOpen} />
    </>
  )
}

function BirthdayModal({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const toast = useToast()
  const t = useT()
  const { data, isLoading } = useBirthdayCalendar()
  const regen = useRegenerateBirthdays()
  const toggle = useToggleBirthdays()

  async function onRegen() {
    try {
      const r = await regen.mutateAsync()
      toast.success(t('Regenerated'), t(r.generated === 1 ? '{n} birthday' : '{n} birthdays', { n: r.generated }))
    } catch (err) {
      toast.error(t('Could not regenerate'), err instanceof ApiError ? err.message : undefined)
    }
  }

  async function onToggle(enabled: boolean) {
    try {
      await toggle.mutateAsync(enabled)
      toast.success(enabled ? t('Birthday calendar enabled') : t('Birthday calendar disabled'))
    } catch (err) {
      toast.error(t('Could not update'), err instanceof ApiError ? err.message : undefined)
    }
  }

  const enabled = data?.enabled ?? true

  return (
    <Modal open={open} onOpenChange={onOpenChange} size="md" title={t('Birthday calendar')}>
      <div className="space-y-5">
        <Card className="space-y-4 p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Cake className="size-4 text-accent" /> {t('Birthdays from your contacts')}
          </div>

          {isLoading ? (
            <div className="grid place-items-center py-6 text-muted"><Spinner className="size-5" /></div>
          ) : (
            <div className="flex flex-wrap items-center gap-3 rounded-xl bg-foreground/[0.03] p-3 ring-1 ring-inset ring-foreground/8">
              <span className="size-2.5 shrink-0 rounded-full" style={{ background: '#e879f9' }} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{t('Birthdays')}</p>
                <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.7rem] text-muted">
                  <span>{t((data?.event_count ?? 0) === 1 ? '{n} birthday' : '{n} birthdays', { n: data?.event_count ?? 0 })}</span>
                  {data?.last_generated_at && <span>· {t('updated {date}', { date: data.last_generated_at.slice(0, 10) })}</span>}
                </p>
              </div>
              <Badge tone="info"><Lock className="size-3" /> {t('Read-only')}</Badge>
              {enabled ? <Badge tone="success"><Check className="size-3" /> {t('Active')}</Badge> : <Badge>{t('Disabled')}</Badge>}
              {enabled && (
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t('Regenerate')}
                  title={t('Regenerate')}
                  onClick={onRegen}
                  loading={regen.isPending}
                >
                  <RefreshCw className="size-4" />
                </Button>
              )}
            </div>
          )}

          <div className="flex items-center justify-between gap-3">
            <p className="text-[0.7rem] text-muted">
              {enabled
                ? t('Birthdays from contacts appear here automatically as all-day, yearly events. Read-only, synced over CalDAV.')
                : t('The birthday calendar is off. Enable it to generate birthdays from your contacts.')}
            </p>
            <Button
              size="sm"
              variant={enabled ? 'subtle' : 'primary'}
              onClick={() => onToggle(!enabled)}
              loading={toggle.isPending}
            >
              {enabled ? t('Disable') : t('Enable')}
            </Button>
          </div>
        </Card>
      </div>
    </Modal>
  )
}
