import { useMemo, useState } from 'react'
import { Activity as ActivityIcon, Search, RotateCcw, ArrowRight, Globe } from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Input, Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { DatePicker } from '@/components/ui/DatePicker'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { useActivity } from '@/api/admin'
import { dateTime } from '@/lib/format'
import { activityMeta, activityText, ACTIVITY_CATEGORIES, type ActivityCategory } from '@/lib/activity'
import { useT, useLocale } from '@/i18n/LocaleContext'

const toneClass: Record<string, string> = {
  neutral: 'bg-foreground/10 text-muted-strong',
  accent: 'bg-accent/12 text-accent',
  success: 'bg-success/12 text-success',
  warning: 'bg-warning/12 text-warning',
  danger: 'bg-danger/12 text-danger',
  info: 'bg-info/12 text-info',
}

export default function Activity() {
  const t = useT()
  const { locale } = useLocale()
  const { data: entries, isLoading } = useActivity(300)

  const [category, setCategory] = useState<ActivityCategory | 'all'>('all')
  const [actor, setActor] = useState('all')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [q, setQ] = useState('')

  const actors = useMemo(() => {
    const set = new Set<string>()
    ;(entries ?? []).forEach((e) => { if (e.actor_username) set.add(e.actor_username) })
    return ['all', ...Array.from(set).sort()]
  }, [entries])

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase()
    return (entries ?? []).filter((e) => {
      if (category !== 'all' && activityMeta(e.action).category !== category) return false
      if (actor !== 'all' && e.actor_username !== actor) return false
      const day = (e.created_at || '').slice(0, 10)
      if (from && day < from) return false
      if (to && day > to) return false
      if (needle) {
        const hay = `${activityText(e)} ${e.action} ${e.actor_username ?? ''} ${e.target_id ?? ''} ${e.ip ?? ''}`.toLowerCase()
        if (!hay.includes(needle)) return false
      }
      return true
    })
  }, [entries, category, actor, from, to, q])

  const hasFilters = category !== 'all' || actor !== 'all' || from || to || q
  function reset() { setCategory('all'); setActor('all'); setFrom(''); setTo(''); setQ('') }

  return (
    <div className="space-y-6">
      <PageHeader title={t('Activity')} subtitle={t('Audit log of administrative and account events')} icon={ActivityIcon} />

      <Card className="p-4">
        <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
          <Field label={t('Type')}>
            <Select value={category} onValueChange={(v) => setCategory(v as ActivityCategory | 'all')} options={ACTIVITY_CATEGORIES.map((o) => ({ value: o.value, label: t(o.label) }))} />
          </Field>
          <Field label={t('User')}>
            <Select value={actor} onValueChange={setActor} options={actors.map((a) => ({ value: a, label: a === 'all' ? t('All users') : a }))} />
          </Field>
          <Field label={t('From')}>
            <DatePicker value={from} onChange={setFrom} placeholder={t('Any start')} />
          </Field>
          <Field label={t('To')}>
            <DatePicker value={to} onChange={setTo} placeholder={t('Any end')} />
          </Field>
        </div>
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <Field label={t('Search')} className="min-w-56 flex-1">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Search descriptions…')} className="pl-9" />
            </div>
          </Field>
          {hasFilters && (
            <Button variant="ghost" onClick={reset}><RotateCcw className="size-4" /> {t('Reset')}</Button>
          )}
        </div>
      </Card>

      {isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : filtered.length ? (
        <Card>
          <ul className="divide-y divide-foreground/8">
            {filtered.map((a, i) => {
              const meta = activityMeta(a.action)
              const Icon = meta.icon
              return (
                <motion.li
                  key={a.id ?? i}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  transition={{ delay: Math.min(i * 0.008, 0.25) }}
                  className="flex items-start gap-3.5 p-3.5"
                >
                  <div className={`grid size-9 shrink-0 place-items-center rounded-xl ${toneClass[meta.tone]}`}>
                    <Icon className="size-4" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-foreground">{activityText(a)}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                      <Badge tone={meta.tone as never}>{t(meta.label)}</Badge>
                      {a.actor_username && (
                        <span className="inline-flex items-center gap-1">
                          <span className="font-medium text-muted-strong">{a.actor_username}</span>
                          {a.target_id && (
                            <>
                              <ArrowRight className="size-3" />
                              <span className="truncate">{a.target_id}</span>
                            </>
                          )}
                        </span>
                      )}
                      {a.ip && (
                        <span className="inline-flex items-center gap-1 font-mono text-[0.7rem]">
                          <Globe className="size-3" />{a.ip}
                        </span>
                      )}
                    </div>
                  </div>
                  <span className="shrink-0 whitespace-nowrap text-xs text-muted">{dateTime(a.created_at, locale)}</span>
                </motion.li>
              )
            })}
          </ul>
        </Card>
      ) : (
        <EmptyState
          icon={ActivityIcon}
          title={hasFilters ? t('No matching activity') : t('No activity yet')}
          description={hasFilters ? t('Try adjusting or resetting the filters.') : undefined}
          action={hasFilters ? <Button variant="secondary" onClick={reset}><RotateCcw className="size-4" /> {t('Reset filters')}</Button> : undefined}
        />
      )}
    </div>
  )
}
