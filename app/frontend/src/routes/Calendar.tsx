import { Fragment, useEffect, useMemo, useRef, useState } from 'react'
import {
  CalendarDays, Plus, Pencil, Clock, ChevronLeft, ChevronRight, List,
  MapPin, AlignLeft, Repeat, Bell, Tag, Info, X, Lock, Trash2, Download,
} from 'lucide-react'
import { motion } from 'motion/react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Textarea, Field } from '@/components/ui/Input'
import { DatePicker, DateTimePicker } from '@/components/ui/DatePicker'
import { Select } from '@/components/ui/Select'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { FormSection } from '@/components/ui/FormSection'
import { TagInput } from '@/components/ui/TagInput'
import { HolidayCalendarsButton } from '@/components/calendar/HolidayCalendars'
import { BirthdayCalendarButton } from '@/components/calendar/BirthdayCalendar'
import { useToast } from '@/components/ui/Toast'
import { ColorPopover } from '@/components/ui/ColorPopover'
import { useCalendars, useMultiEvents, useDeleteEvent, useSaveEvent, useDeleteCalendar, useCreateCalendar } from '@/api/user'
import type { CalEvent, Calendar as Cal, RecurFreq, RecurEndType, EventStatus } from '@/api/types'
import { ApiError } from '@/lib/api'
import { dateTime, shortDate, bcp47 } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useT, useLocale } from '@/i18n/LocaleContext'
import { addDays, addMonths, dayKey, isoWeek, monthMatrix, parseEventDate, sameDay, startOfWeek } from '@/lib/caldate'

const DEFAULT_COLOR = '#7c6cf6'

// ── Persisted view preferences (survive reloads) ──
const LS_HIDDEN = 'davyn.calendar.hidden'
const LS_WEEKS = 'davyn.calendar.weeks'
function loadHidden(): Set<string> {
  try {
    const raw = localStorage.getItem(LS_HIDDEN)
    if (raw) return new Set<string>(JSON.parse(raw))
  } catch { /* ignore */ }
  return new Set()
}
function loadBool(key: string, fallback: boolean): boolean {
  try {
    const raw = localStorage.getItem(key)
    if (raw !== null) return raw === '1'
  } catch { /* ignore */ }
  return fallback
}

type ViewMode = 'month' | 'week' | 'workweek' | 'day' | 'list'
const VIEWS: { value: ViewMode; label: string }[] = [
  { value: 'month', label: 'Month' },
  { value: 'week', label: 'Week' },
  { value: 'workweek', label: 'Work week' },
  { value: 'day', label: 'Day' },
  { value: 'list', label: 'List' },
]

const FREQ_OPTIONS: { value: RecurFreq; label: string; unit: string }[] = [
  { value: 'DAILY', label: 'Daily', unit: 'day' },
  { value: 'WEEKLY', label: 'Weekly', unit: 'week' },
  { value: 'MONTHLY', label: 'Monthly', unit: 'month' },
  { value: 'YEARLY', label: 'Yearly', unit: 'year' },
]
// Radix Select forbids an empty-string value, so "None" uses a sentinel that
// maps back to '' (no STATUS) in the form state.
const STATUS_NONE = 'NONE'
const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: STATUS_NONE, label: 'None' },
  { value: 'CONFIRMED', label: 'Confirmed' },
  { value: 'TENTATIVE', label: 'Tentative' },
  { value: 'CANCELLED', label: 'Cancelled' },
]
const REMINDER_PRESETS = [5, 15, 60, 1440]

type Translate = (key: string, vars?: Record<string, string | number>) => string

function reminderLabel(m: number, t: Translate): string {
  if (m === 0) return t('At start')
  if (m % 1440 === 0) { const d = m / 1440; return t(d > 1 ? '{n} days before' : '{n} day before', { n: d }) }
  if (m % 60 === 0) { const h = m / 60; return t(h > 1 ? '{n} hours before' : '{n} hour before', { n: h }) }
  return t('{n} min before', { n: m })
}

interface AnnEvent extends CalEvent {
  calUri: string
  calName: string
  color: string
  writable: boolean
}

function isWritable(c?: Cal) {
  // Generated calendars (holidays, birthdays) are owned but read-only.
  return (c?.permission === 'owner' || c?.permission === 'read_write') && !c?.read_only
}

/**
 * Expand a (possibly recurring) event into the day-keys (YYYY-MM-DD) it occupies
 * within [ws, we]. Handles FREQ + INTERVAL + UNTIL/COUNT for DAILY/WEEKLY/MONTHLY/
 * YEARLY (BY* refinements are ignored — good enough for birthdays, holidays and
 * simple repeats). Non-recurring events resolve to their single start day. Without
 * this, a recurring event (e.g. a birthday with DTSTART in a past year) would only
 * ever render on its original date and never in the current view.
 */
function occurrenceDayKeys(ev: AnnEvent, ws: Date, we: Date): string[] {
  const base = parseEventDate(ev.dtstart)
  if (isNaN(base.getTime())) return []
  if (!ev.recurring) return [dayKey(base)]

  let freq: string | undefined = ev.recurrence?.freq
  let interval = ev.recurrence?.interval || 1
  let until: Date | null = null
  let count: number | null = null
  const end = ev.recurrence?.end
  if (end?.type === 'until' && end.until) until = parseEventDate(end.until)
  if (end?.type === 'count' && end.count) count = end.count

  // Fall back to the raw RRULE when the structured form isn't populated.
  if (!freq && ev.rrule_raw) {
    const parts: Record<string, string> = {}
    for (const p of ev.rrule_raw.replace(/^RRULE:/i, '').split(';')) {
      const [k, v] = p.split('=')
      if (k && v) parts[k.toUpperCase()] = v
    }
    freq = parts.FREQ
    interval = Math.max(1, parseInt(parts.INTERVAL || '1', 10) || 1)
    const mu = parts.UNTIL && /^(\d{4})(\d{2})(\d{2})/.exec(parts.UNTIL)
    if (mu) until = new Date(+mu[1], +mu[2] - 1, +mu[3])
    if (parts.COUNT) count = parseInt(parts.COUNT, 10) || null
  }

  if (!freq || !['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].includes(freq)) return [dayKey(base)]
  interval = Math.max(1, interval)

  const step = (n: number): Date => {
    const d = new Date(base)
    if (freq === 'YEARLY') d.setFullYear(base.getFullYear() + n * interval)
    else if (freq === 'MONTHLY') d.setMonth(base.getMonth() + n * interval)
    else if (freq === 'WEEKLY') d.setDate(base.getDate() + n * interval * 7)
    else d.setDate(base.getDate() + n * interval)
    return d
  }

  // Fast-forward near the window so an old start date stays cheap to expand.
  const msDay = 86400000
  let n0 = 0
  if (freq === 'YEARLY') n0 = Math.floor((ws.getFullYear() - base.getFullYear()) / interval)
  else if (freq === 'MONTHLY') n0 = Math.floor(((ws.getFullYear() - base.getFullYear()) * 12 + (ws.getMonth() - base.getMonth())) / interval)
  else if (freq === 'WEEKLY') n0 = Math.floor((ws.getTime() - base.getTime()) / (msDay * 7 * interval))
  else n0 = Math.floor((ws.getTime() - base.getTime()) / (msDay * interval))
  n0 = Math.max(0, n0 - 2)

  const keys: string[] = []
  for (let n = n0, guard = 0; guard < 1200; n++, guard++) {
    if (count !== null && n >= count) break
    const occ = step(n)
    if (occ > we) break
    if (until && occ > until) break
    if (occ >= ws) keys.push(dayKey(occ))
    if (n > n0 + 1000) break
  }
  return keys
}

interface EventForm {
  cal: string
  summary: string
  allDay: boolean
  dtstart: string
  dtend: string
  location: string
  description: string
  status: EventStatus
  categories: string[]
  recurring: boolean
  freq: RecurFreq
  interval: number
  endType: RecurEndType
  until: string
  count: number
  recurrenceSupported: boolean
  rruleRaw: string
  reminders: number[]
  remindersSupported: boolean
}

const EMPTY_FORM: EventForm = {
  cal: '', summary: '', allDay: false, dtstart: '', dtend: '',
  location: '', description: '', status: '', categories: [],
  recurring: false, freq: 'WEEKLY', interval: 1, endType: 'never', until: '', count: 10,
  recurrenceSupported: true, rruleRaw: '',
  reminders: [], remindersSupported: true,
}

export default function Calendar() {
  const toast = useToast()
  const t = useT()
  const { locale } = useLocale()
  const loc = bcp47(locale)
  const { data: calendars } = useCalendars()
  const save = useSaveEvent()
  const del = useDeleteEvent()
  const delCal = useDeleteCalendar()
  const createCal = useCreateCalendar()
  const [toDeleteCal, setToDeleteCal] = useState<Cal | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [newCal, setNewCal] = useState({ name: '', color: DEFAULT_COLOR })

  // We persist the *hidden* set (not the visible one) so newly added calendars
  // appear by default, and the user's show/hide choices survive reloads.
  const [hidden, setHidden] = useState<Set<string>>(loadHidden)
  const [showWeeks, setShowWeeks] = useState<boolean>(() => loadBool(LS_WEEKS, false))
  const [view, setView] = useState<ViewMode>('month')
  const [anchor, setAnchor] = useState<Date>(() => new Date())
  const [searchParams, setSearchParams] = useSearchParams()

  // Jump to a date from ?date=YYYY-MM-DD (dashboard "Open in calendar"), then
  // consume the param so it can't override later manual navigation.
  const dateParam = searchParams.get('date')
  useEffect(() => {
    if (!dateParam) return
    const d = new Date(dateParam + 'T00:00:00')
    if (!Number.isNaN(d.getTime())) setAnchor(d)
    setSearchParams((p) => { p.delete('date'); return p }, { replace: true })
  }, [dateParam]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    try { localStorage.setItem(LS_HIDDEN, JSON.stringify([...hidden])) } catch { /* ignore */ }
  }, [hidden])
  useEffect(() => {
    try { localStorage.setItem(LS_WEEKS, showWeeks ? '1' : '0') } catch { /* ignore */ }
  }, [showWeeks])

  const isShown = (uri: string) => !hidden.has(uri)
  const toggleCal = (uri: string) =>
    setHidden((h) => { const n = new Set(h); n.has(uri) ? n.delete(uri) : n.add(uri); return n })

  const selectedUris = useMemo(() => (calendars ?? []).filter((c) => isShown(c.uri)).map((c) => c.uri), [calendars, hidden])
  const { byUri, isLoading } = useMultiEvents(selectedUris)

  const events = useMemo<AnnEvent[]>(() => {
    const out: AnnEvent[] = []
    for (const c of calendars ?? []) {
      if (!isShown(c.uri)) continue
      for (const ev of byUri[c.uri] ?? []) {
        out.push({ ...ev, calUri: c.uri, calName: c.display_name, color: c.color || DEFAULT_COLOR, writable: isWritable(c) })
      }
    }
    return out
  }, [calendars, hidden, byUri])

  // Open a specific event from ?ecal=&euri= (global search): make its calendar
  // visible, center the view on it, and open the editor once it has loaded. The
  // params are cleared when the editor closes (see setEventModalOpen), which
  // also lets the same event be reopened from a later search. Reading from the
  // URL (not ephemeral router state) makes this robust to the route mounting.
  const ecal = searchParams.get('ecal')
  const euri = searchParams.get('euri')
  const openedEvRef = useRef<string | null>(null)
  useEffect(() => {
    if (!ecal || !euri) { openedEvRef.current = null; return }
    const key = `${ecal} ${euri}`
    if (openedEvRef.current === key) return
    setHidden((h) => { if (!h.has(ecal)) return h; const n = new Set(h); n.delete(ecal); return n })
    const ev = events.find((e) => e.calUri === ecal && e.uri === euri)
    if (ev) {
      openedEvRef.current = key
      const start = parseEventDate(ev.dtstart)
      if (!Number.isNaN(start.getTime())) setAnchor(start)
      if (ev.writable) openEdit(ev)
      // Read-only events have no editor; revealing them on their date is the most
      // we can do, so drop the params instead of waiting for a modal close.
      else setSearchParams((p) => { p.delete('ecal'); p.delete('euri'); return p }, { replace: true })
    }
  }, [ecal, euri, events]) // eslint-disable-line react-hooks/exhaustive-deps

  const byDay = useMemo(() => {
    const m = new Map<string, AnnEvent[]>()
    // Expand recurrences across a window covering the month grid plus a week of
    // slack each side (enough for the month/week/day views).
    const monthFirst = new Date(anchor.getFullYear(), anchor.getMonth(), 1)
    const ws = addDays(startOfWeek(monthFirst), -7)
    const we = addDays(ws, 55)
    for (const ev of events) {
      for (const k of occurrenceDayKeys(ev, ws, we)) {
        if (!m.has(k)) m.set(k, [])
        m.get(k)!.push(ev)
      }
    }
    for (const list of m.values()) list.sort((a, b) => a.dtstart.localeCompare(b.dtstart))
    return m
  }, [events, anchor])

  const writableCals = useMemo(() => (calendars ?? []).filter(isWritable), [calendars])
  const anyWritable = writableCals.length > 0

  // ── Event form ──
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<AnnEvent | null>(null)
  const [toDelete, setToDelete] = useState<AnnEvent | null>(null)
  const [form, setForm] = useState<EventForm>(EMPTY_FORM)
  const set = <K extends keyof EventForm>(k: K, v: EventForm[K]) => setForm((f) => ({ ...f, [k]: v }))

  function openNew(day?: Date) {
    if (!anyWritable) return
    const d = day ? dayKey(day) : ''
    setEditing(null)
    setForm({ ...EMPTY_FORM, cal: writableCals[0]?.uri ?? '', dtstart: d ? `${d}T09:00` : '', dtend: d ? `${d}T10:00` : '' })
    setOpen(true)
  }
  function openEdit(ev: AnnEvent) {
    if (!ev.writable) return
    setEditing(ev)
    setForm({
      cal: ev.calUri,
      summary: ev.summary,
      allDay: ev.all_day,
      dtstart: ev.dtstart,
      dtend: ev.dtend,
      location: ev.location ?? '',
      description: ev.description ?? '',
      status: ev.status ?? '',
      categories: ev.categories ?? [],
      recurring: ev.recurring,
      freq: ev.recurrence?.freq || 'WEEKLY',
      interval: ev.recurrence?.interval || 1,
      endType: ev.recurrence?.end?.type || 'never',
      until: ev.recurrence?.end?.until || '',
      count: ev.recurrence?.end?.count || 10,
      recurrenceSupported: ev.recurrence_supported,
      rruleRaw: ev.rrule_raw ?? '',
      reminders: (ev.reminders ?? []).map((r) => r.minutes),
      remindersSupported: ev.reminders_supported,
    })
    setOpen(true)
  }

  // Every close path goes through here so the deep-link params from a search are
  // dropped when the editor closes (and the same event can be reopened later).
  function setEventModalOpen(o: boolean) {
    setOpen(o)
    if (!o && (searchParams.has('ecal') || searchParams.has('euri'))) {
      setSearchParams((p) => { p.delete('ecal'); p.delete('euri'); return p }, { replace: true })
    }
  }

  function setAllDay(allDay: boolean) {
    setForm((f) => {
      if (allDay) {
        const start = f.dtstart ? f.dtstart.slice(0, 10) : ''
        const end = f.dtend ? f.dtend.slice(0, 10) : start
        return { ...f, allDay, dtstart: start, dtend: end }
      }
      const startDate = f.dtstart ? f.dtstart.slice(0, 10) : ''
      const endDate = f.dtend ? f.dtend.slice(0, 10) : startDate
      const start = startDate ? `${startDate}T09:00` : ''
      const end = endDate ? (endDate === startDate ? `${endDate}T10:00` : `${endDate}T09:00`) : ''
      return { ...f, allDay, dtstart: start, dtend: end }
    })
  }

  function toggleReminder(m: number) {
    setForm((f) => ({
      ...f,
      reminders: f.reminders.includes(m) ? f.reminders.filter((x) => x !== m) : [...f.reminders, m].sort((a, b) => a - b),
    }))
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!form.cal || !form.summary.trim() || !form.dtstart || !form.dtend) {
      return toast.error(t('Missing fields'), t('Calendar, title, start and end are required.'))
    }
    if (form.allDay ? form.dtend < form.dtstart : form.dtend <= form.dtstart) {
      return toast.error(t('Invalid range'), form.allDay ? t('End must be on or after start.') : t('End must be after start.'))
    }
    try {
      await save.mutateAsync({
        cal: form.cal,
        summary: form.summary.trim(),
        all_day: form.allDay,
        dtstart: form.dtstart,
        dtend: form.dtend,
        location: form.location.trim(),
        description: form.description.trim(),
        status: form.status,
        categories: form.categories,
        // Only manage recurrence/reminders the WebUI fully understands; otherwise
        // leave the original RRULE / VALARM untouched (lossless).
        patch_recurrence: form.recurrenceSupported,
        recurrence: {
          freq: form.recurring ? form.freq : '',
          interval: form.interval,
          end: { type: form.endType, until: form.until, count: form.count },
        },
        patch_reminders: form.remindersSupported,
        reminders: form.reminders.map((m) => ({ minutes: m })),
        uri: editing?.uri,
        expected_etag: editing?.etag,
        from_cal: editing?.calUri,
      })
      toast.success(editing ? t('Event updated') : t('Event created'))
      setEventModalOpen(false)
    } catch (err) {
      toast.error(t('Could not save event'), err instanceof ApiError ? err.message : undefined)
    }
  }

  const freqUnit = FREQ_OPTIONS.find((o) => o.value === form.freq)?.unit ?? 'week'

  // ── Navigation ──
  function step(dir: number) {
    if (view === 'month') setAnchor((a) => addMonths(a, dir))
    else if (view === 'day') setAnchor((a) => addDays(a, dir))
    else setAnchor((a) => addDays(a, dir * 7))
  }
  const periodLabel = useMemo(() => {
    if (view === 'month') return anchor.toLocaleDateString(loc, { month: 'long', year: 'numeric' })
    if (view === 'day') return anchor.toLocaleDateString(loc, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
    if (view === 'list') return t('All events')
    const start = startOfWeek(anchor)
    const len = view === 'workweek' ? 4 : 6
    const end = addDays(start, len)
    const range = `${start.toLocaleDateString(loc, { month: 'short', day: 'numeric' })} – ${end.toLocaleDateString(loc, { month: 'short', day: 'numeric', year: 'numeric' })}`
    return showWeeks ? `${range} · KW ${isoWeek(start)}` : range
  }, [view, anchor, showWeeks, loc, t])

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('Calendar')}
        subtitle={t('Events synced over CalDAV')}
        icon={CalendarDays}
        actions={
          <>
            <Button variant="subtle" size="sm" onClick={() => { setNewCal({ name: '', color: DEFAULT_COLOR }); setCreateOpen(true) }}>
              <Plus className="size-4" /> {t('New calendar')}
            </Button>
            <HolidayCalendarsButton />
            <BirthdayCalendarButton />
            {anyWritable && <Button onClick={() => openNew()}><Plus className="size-4" /> {t('New event')}</Button>}
          </>
        }
      />

      {/* Calendar picker chips */}
      <div className="flex flex-wrap gap-2">
        {(calendars ?? []).map((c) => {
          const on = isShown(c.uri)
          const owned = c.permission === 'owner'
          return (
            <span
              key={c.uri}
              className={cn(
                'inline-flex items-center rounded-full text-xs font-medium ring-1 ring-inset transition',
                on ? 'bg-foreground/8 text-foreground ring-foreground/15' : 'text-muted ring-foreground/10',
              )}
            >
              <button
                type="button"
                onClick={() => toggleCal(c.uri)}
                className={cn('inline-flex items-center gap-2 rounded-full py-1.5 pl-3', owned ? 'pr-1.5' : 'pr-3', !on && 'hover:text-foreground')}
              >
                <span className="size-2.5 rounded-full ring-1 ring-inset ring-black/10" style={{ background: on ? (c.color || DEFAULT_COLOR) : 'transparent', borderColor: c.color || DEFAULT_COLOR, boxShadow: on ? undefined : `inset 0 0 0 1.5px ${c.color || DEFAULT_COLOR}` }} />
                {c.display_name}
                {c.read_only && !c.shared && <Lock className="size-3 text-muted" aria-label={t('Read-only')} />}
                {c.shared && <span className="text-[0.65rem] text-muted">· {c.permission.replace('_', ' ')}</span>}
              </button>
              {owned && (
                <button
                  type="button"
                  onClick={() => setToDeleteCal(c)}
                  aria-label={`${t('Delete')} ${c.display_name}`}
                  title={t('Delete calendar')}
                  className="mr-1 grid size-5 shrink-0 place-items-center rounded-full text-muted transition hover:bg-danger/15 hover:text-danger"
                >
                  <Trash2 className="size-3" />
                </button>
              )}
            </span>
          )
        })}
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="inline-flex flex-wrap rounded-xl bg-foreground/5 p-0.5 ring-1 ring-inset ring-foreground/10">
          {VIEWS.map((v) => (
            <button
              key={v.value}
              type="button"
              onClick={() => setView(v.value)}
              className={cn('rounded-lg px-3 py-1.5 text-xs font-medium transition', view === v.value ? 'bg-accent text-accent-foreground shadow-glow' : 'text-muted hover:text-foreground')}
            >
              {v.value === 'list' ? <List className="mr-1 inline size-3.5" /> : null}{t(v.label)}
            </button>
          ))}
        </div>

        {(view === 'month' || view === 'week' || view === 'workweek') && (
          <button
            type="button"
            onClick={() => setShowWeeks((v) => !v)}
            aria-pressed={showWeeks}
            title={showWeeks ? t('Hide week numbers') : t('Show week numbers')}
            className={cn(
              'rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset transition',
              showWeeks ? 'bg-accent/15 text-accent ring-accent/30' : 'text-muted ring-foreground/10 hover:text-foreground',
            )}
          >
            KW
          </button>
        )}

        {view !== 'list' && (
          <div className="flex items-center gap-1">
            <Button variant="ghost" size="icon" aria-label={t('Previous')} onClick={() => step(-1)}><ChevronLeft className="size-4" /></Button>
            <Button variant="subtle" size="sm" onClick={() => setAnchor(new Date())}>{t('Today')}</Button>
            <Button variant="ghost" size="icon" aria-label={t('Next')} onClick={() => step(1)}><ChevronRight className="size-4" /></Button>
          </div>
        )}

        <span className="text-sm font-semibold">{periodLabel}</span>

        {view !== 'list' && (
          <div className="ml-auto w-full sm:w-44">
            <DatePicker value={dayKey(anchor)} onChange={(v) => v && setAnchor(parseEventDate(v))} placeholder={t('Jump to date')} />
          </div>
        )}
      </div>

      {isLoading ? (
        <Skeleton className="h-96 w-full" />
      ) : view === 'month' ? (
        <MonthView anchor={anchor} byDay={byDay} onDay={openNew} onEvent={openEdit} canCreate={anyWritable} showWeeks={showWeeks} />
      ) : view === 'day' ? (
        <DayColumns days={[anchor]} byDay={byDay} onDay={openNew} onEvent={openEdit} canCreate={anyWritable} />
      ) : view === 'week' || view === 'workweek' ? (
        <DayColumns
          days={Array.from({ length: view === 'workweek' ? 5 : 7 }, (_, i) => addDays(startOfWeek(anchor), i))}
          byDay={byDay}
          onDay={openNew}
          onEvent={openEdit}
          canCreate={anyWritable}
        />
      ) : (
        <ListView events={events} onEvent={openEdit} canCreate={anyWritable} onNew={() => openNew()} />
      )}

      <Modal
        open={open}
        onOpenChange={setEventModalOpen}
        size="2xl"
        title={editing ? t('Edit event') : t('New event')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEventModalOpen(false)}>{t('Cancel')}</Button>
            <Button onClick={submit} loading={save.isPending}>{editing ? t('Save') : t('Create')}</Button>
          </>
        }
      >
        <form onSubmit={submit} className="space-y-6">
          {/* Basics */}
          <FormSection title={t('Basics')} icon={CalendarDays}>
            <Field label={t('Title')}><Input value={form.summary} onChange={(e) => set('summary', e.target.value)} placeholder={t('Team meeting')} autoFocus /></Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('Calendar')}>
                <Select value={form.cal} onValueChange={(v) => set('cal', v)} options={writableCals.map((c) => ({ value: c.uri, label: c.display_name }))} placeholder={t('Select calendar')} />
              </Field>
              <Field label={t('Status')}>
                <Select
                  value={form.status || STATUS_NONE}
                  onValueChange={(v) => set('status', (v === STATUS_NONE ? '' : v) as EventStatus)}
                  options={STATUS_OPTIONS.map((o) => ({ value: o.value, label: t(o.label) }))}
                />
              </Field>
            </div>
          </FormSection>

          {/* Time */}
          <FormSection title={t('Time')} icon={Clock}>
            <label className="flex cursor-pointer items-center gap-2.5 text-sm">
              <input type="checkbox" checked={form.allDay} onChange={(e) => setAllDay(e.target.checked)} className="size-4 cursor-pointer rounded border-foreground/20 bg-transparent text-accent accent-accent" />
              <span className="font-medium">{t('All day')}</span>
            </label>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {form.allDay ? (
                <>
                  <Field label={t('Start')}><DatePicker value={form.dtstart} onChange={(v) => set('dtstart', v)} /></Field>
                  <Field label={t('End')}><DatePicker value={form.dtend} onChange={(v) => set('dtend', v)} /></Field>
                </>
              ) : (
                <>
                  <Field label={t('Start')}><DateTimePicker value={form.dtstart} onChange={(v) => set('dtstart', v)} /></Field>
                  <Field label={t('End')}><DateTimePicker value={form.dtend} onChange={(v) => set('dtend', v)} /></Field>
                </>
              )}
            </div>
          </FormSection>

          {/* Recurrence */}
          <FormSection title={t('Recurrence')} icon={Repeat}>
            {form.recurrenceSupported ? (
              <>
                <Segmented
                  value={form.recurring ? 'recurring' : 'once'}
                  onChange={(v) => set('recurring', v === 'recurring')}
                  options={[{ value: 'once', label: t('One-time') }, { value: 'recurring', label: t('Recurring') }]}
                />
                {form.recurring && (
                  <div className="space-y-3 rounded-xl bg-foreground/[0.03] p-3 ring-1 ring-inset ring-foreground/8">
                    <div className="grid gap-3 sm:grid-cols-2">
                      <Field label={t('Frequency')}>
                        <Select value={form.freq} onValueChange={(v) => set('freq', v as RecurFreq)} options={FREQ_OPTIONS.map((o) => ({ value: o.value, label: t(o.label) }))} />
                      </Field>
                      <Field label={t('Repeat every ({unit})', { unit: t(freqUnit) })}>
                        <Input type="number" min={1} value={form.interval} onChange={(e) => set('interval', Math.max(1, Number(e.target.value) || 1))} />
                      </Field>
                    </div>
                    <Field label={t('Ends')}>
                      <Segmented
                        value={form.endType}
                        onChange={(v) => set('endType', v as RecurEndType)}
                        options={[{ value: 'never', label: t('Never') }, { value: 'until', label: t('On date') }, { value: 'count', label: t('After') }]}
                      />
                    </Field>
                    {form.endType === 'until' && <Field label={t('Until')}><DatePicker value={form.until} onChange={(v) => set('until', v)} /></Field>}
                    {form.endType === 'count' && (
                      <Field label={t('Occurrences')}>
                        <Input type="number" min={1} value={form.count} onChange={(e) => set('count', Math.max(1, Number(e.target.value) || 1))} />
                      </Field>
                    )}
                  </div>
                )}
              </>
            ) : (
              <div className="flex items-start gap-2.5 rounded-xl bg-info/8 p-3 text-xs text-muted-strong ring-1 ring-inset ring-info/20">
                <Info className="mt-0.5 size-4 shrink-0 text-info" />
                <div className="space-y-2">
                  <p>{t('This event uses an advanced recurrence rule that the editor can’t fully display. It will be preserved unchanged when you save.')}</p>
                  {form.rruleRaw && <p className="font-mono text-[0.7rem] text-muted">{form.rruleRaw}</p>}
                  <button type="button" onClick={() => setForm((f) => ({ ...f, recurrenceSupported: true, recurring: true }))} className="font-medium text-info hover:underline">
                    {t('Replace with a simple rule instead')}
                  </button>
                </div>
              </div>
            )}
          </FormSection>

          {/* Reminder */}
          <FormSection title={t('Reminders')} icon={Bell}>
            {form.remindersSupported ? (
              <div className="flex flex-wrap gap-2">
                {REMINDER_PRESETS.map((m) => {
                  const on = form.reminders.includes(m)
                  return (
                    <button
                      key={m}
                      type="button"
                      onClick={() => toggleReminder(m)}
                      className={cn('rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition', on ? 'bg-accent/15 text-accent ring-accent/30' : 'text-muted ring-foreground/10 hover:text-foreground')}
                    >
                      {reminderLabel(m, t)}
                    </button>
                  )
                })}
                {form.reminders.filter((m) => !REMINDER_PRESETS.includes(m)).map((m) => (
                  <span key={m} className="inline-flex items-center gap-1 rounded-full bg-accent/15 px-3 py-1.5 text-xs font-medium text-accent ring-1 ring-inset ring-accent/30">
                    {reminderLabel(m, t)}
                    <button type="button" onClick={() => toggleReminder(m)} aria-label={t('Remove reminder')}><X className="size-3" /></button>
                  </span>
                ))}
                <CustomReminder onAdd={(m) => !form.reminders.includes(m) && toggleReminder(m)} t={t} />
              </div>
            ) : (
              <div className="flex items-start gap-2.5 rounded-xl bg-info/8 p-3 text-xs text-muted-strong ring-1 ring-inset ring-info/20">
                <Info className="mt-0.5 size-4 shrink-0 text-info" />
                <p>{t('This event has custom alarms that the editor can’t represent. They’ll be kept unchanged when you save.')}</p>
              </div>
            )}
          </FormSection>

          {/* Details */}
          <FormSection title={t('Details')} icon={AlignLeft}>
            <Field label={t('Location')}>
              <div className="relative">
                <MapPin className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <Input value={form.location} onChange={(e) => set('location', e.target.value)} placeholder={t('Conference room / address')} className="pl-9" />
              </div>
            </Field>
            <Field label={t('Description')}><Textarea value={form.description} onChange={(e) => set('description', e.target.value)} placeholder={t('Agenda, notes…')} /></Field>
            <Field label={t('Categories')} hint={t('Press Enter or comma to add')}>
              <TagInput value={form.categories} onChange={(v) => set('categories', v)} placeholder={t('Add category…')} />
            </Field>
          </FormSection>

          {editing && (
            <div className="flex items-center justify-between gap-3">
              <button type="button" onClick={() => { setEventModalOpen(false); setToDelete(editing) }} className="inline-flex items-center gap-1 text-xs font-medium text-danger hover:underline">
                <Tag className="size-3.5" /> {t('Delete this event')}
              </button>
              <a
                href={`/api/user/export/calendar?cal=${encodeURIComponent(editing.calUri)}&uri=${encodeURIComponent(editing.uri)}`}
                download
                className="inline-flex items-center gap-1 text-xs font-medium text-muted-strong hover:text-foreground hover:underline"
              >
                <Download className="size-3.5" /> {t('Export .ics')}
              </a>
            </div>
          )}
        </form>
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={t('Delete event?')}
        description={t('"{name}" will be removed.', { name: toDelete?.summary || t('This event') })}
        confirmLabel={t('Delete')}
        danger
        loading={del.isPending}
        onConfirm={async () => {
          try {
            await del.mutateAsync({ cal: toDelete!.calUri, uri: toDelete!.uri })
            toast.success(t('Event deleted'))
          } catch (err) {
            toast.error(t('Could not delete'), err instanceof ApiError ? err.message : undefined)
          }
          setToDelete(null)
        }}
      />

      <Modal
        open={createOpen}
        onOpenChange={setCreateOpen}
        title={t('New calendar')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>{t('Cancel')}</Button>
            <Button
              loading={createCal.isPending}
              onClick={async () => {
                if (!newCal.name.trim()) return toast.error(t('Name required'), t('Enter a calendar name.'))
                try {
                  await createCal.mutateAsync({ display_name: newCal.name.trim(), color: newCal.color })
                  // New calendars are visible by default (not in the hidden set).
                  toast.success(t('Calendar created'))
                  setCreateOpen(false)
                } catch (err) {
                  toast.error(t('Could not create calendar'), err instanceof ApiError ? err.message : undefined)
                }
              }}
            >
              {t('Create')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label={t('Name')}>
            <Input value={newCal.name} onChange={(e) => setNewCal((c) => ({ ...c, name: e.target.value }))} placeholder={t('e.g. Travel, Sports…')} autoFocus />
          </Field>
          <Field label={t('Color')}>
            <ColorPopover
              value={newCal.color}
              onChange={(hex) => setNewCal((c) => ({ ...c, color: hex }))}
              trigger={
                <button type="button" className="inline-flex items-center gap-2 rounded-xl bg-foreground/5 px-3 py-2 text-sm ring-1 ring-inset ring-foreground/10 transition hover:bg-foreground/10">
                  <span className="size-5 rounded-md ring-1 ring-inset ring-black/10" style={{ background: newCal.color }} />
                  <span className="font-mono text-xs uppercase text-muted">{newCal.color}</span>
                </button>
              }
            />
          </Field>
        </div>
      </Modal>

      <ConfirmDialog
        open={toDeleteCal !== null}
        onOpenChange={(o) => !o && setToDeleteCal(null)}
        title={t('Delete calendar?')}
        description={t('"{name}" and all of its events will be permanently removed. This cannot be undone.', { name: toDeleteCal?.display_name ?? t('This calendar') })}
        confirmLabel={t('Delete calendar')}
        danger
        loading={delCal.isPending}
        onConfirm={async () => {
          const target = toDeleteCal!
          try {
            await delCal.mutateAsync(target.uri)
            setHidden((h) => { const n = new Set(h); n.delete(target.uri); return n }) // clean up persisted state
            toast.success(t('Calendar deleted'))
          } catch (err) {
            toast.error(t('Could not delete calendar'), err instanceof ApiError ? err.message : undefined)
          }
          setToDeleteCal(null)
        }}
      />
    </div>
  )
}

/** Small inline segmented control. */
function Segmented<T extends string>({ value, onChange, options }: {
  value: T; onChange: (v: T) => void; options: { value: T; label: string }[]
}) {
  return (
    <div className="inline-flex rounded-xl bg-foreground/5 p-0.5 ring-1 ring-inset ring-foreground/10">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          className={cn('rounded-lg px-3 py-1.5 text-xs font-medium transition', value === o.value ? 'bg-accent text-accent-foreground shadow-glow' : 'text-muted hover:text-foreground')}
        >
          {o.label}
        </button>
      ))}
    </div>
  )
}

function CustomReminder({ onAdd, t }: { onAdd: (minutes: number) => void; t: Translate }) {
  const [n, setN] = useState('')
  const [unit, setUnit] = useState('60')
  return (
    <div className="inline-flex items-center gap-1.5">
      <Input value={n} onChange={(e) => setN(e.target.value.replace(/\D/g, ''))} placeholder={t('Custom')} className="h-9 w-20 px-2 py-1 text-xs" />
      <div className="w-24">
        <Select value={unit} onValueChange={setUnit} options={[{ value: '1', label: t('min') }, { value: '60', label: t('hours') }, { value: '1440', label: t('days') }]} />
      </div>
      <Button type="button" variant="subtle" size="sm" onClick={() => { const v = Number(n) * Number(unit); if (v > 0) { onAdd(v); setN('') } }}>{t('Add')}</Button>
    </div>
  )
}

function EventChip({ ev, onEvent }: { ev: AnnEvent; onEvent: (e: AnnEvent) => void }) {
  const t = useT()
  return (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); onEvent(ev) }}
      className={cn('flex w-full items-center gap-1.5 truncate rounded-md px-1.5 py-0.5 text-left text-[0.7rem] transition hover:brightness-110', !ev.writable && 'cursor-default')}
      style={{ background: `${ev.color}22`, color: ev.color }}
      title={`${ev.summary} · ${ev.calName}`}
    >
      <span className="size-1.5 shrink-0 rounded-full" style={{ background: ev.color }} />
      {ev.recurring && <Repeat className="size-2.5 shrink-0 opacity-70" />}
      <span className="truncate text-foreground/90">{ev.all_day ? '' : `${ev.dtstart.slice(11, 16)} `}{ev.summary || t('(no title)')}</span>
    </button>
  )
}

function MonthView({ anchor, byDay, onDay, onEvent, canCreate, showWeeks }: {
  anchor: Date; byDay: Map<string, AnnEvent[]>; onDay: (d: Date) => void; onEvent: (e: AnnEvent) => void; canCreate: boolean; showWeeks: boolean
}) {
  const t = useT()
  const { locale } = useLocale()
  const loc = bcp47(locale)
  // Monday-first short weekday names in the active locale (Jan 1 2024 is a Monday).
  const weekdayNames = useMemo(
    () => Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(loc, { weekday: 'short' })),
    [loc],
  )
  const cells = monthMatrix(anchor)
  const today = new Date()
  // Group the 42 day cells into 6 weeks so each row can carry a leading KW cell.
  const weeks: Date[][] = []
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7))
  const colsClass = showWeeks ? 'grid-cols-[2.25rem_repeat(7,minmax(0,1fr))]' : 'grid-cols-7'

  return (
    <Card className="overflow-hidden p-0">
      <div className={cn('grid border-b border-foreground/10', colsClass)}>
        {showWeeks && <div className="px-1 py-2 text-center text-[0.6rem] font-semibold uppercase tracking-wide text-muted">KW</div>}
        {weekdayNames.map((w) => (
          <div key={w} className="px-1 py-2 text-center text-[0.6rem] font-semibold uppercase tracking-wide text-muted sm:px-2 sm:text-[0.65rem]">{w}</div>
        ))}
      </div>
      <div className={cn('grid [grid-auto-rows:1fr] min-h-[58vh] 2xl:min-h-[72vh] min-[1920px]:min-h-[80vh]', colsClass)}>
        {weeks.map((week, wi) => (
          <Fragment key={wi}>
            {showWeeks && (
              <div className="flex justify-center border-b border-r border-foreground/8 bg-foreground/[0.02] pt-1.5 text-[0.65rem] font-medium tabular-nums text-muted">
                {isoWeek(week[0])}
              </div>
            )}
            {week.map((d, di) => {
              const inMonth = d.getMonth() === anchor.getMonth()
              const list = byDay.get(dayKey(d)) ?? []
              return (
                <div
                  key={di}
                  onClick={() => canCreate && onDay(d)}
                  className={cn(
                    'relative min-h-20 border-b border-r border-foreground/8 p-1 transition sm:min-h-24 sm:p-1.5',
                    di === 6 && 'border-r-0',
                    !inMonth && 'bg-foreground/[0.02] text-muted',
                    sameDay(d, today) && 'z-10 bg-accent/10 ring-2 ring-inset ring-accent',
                    canCreate && 'cursor-pointer hover:bg-foreground/[0.04]',
                  )}
                >
                  <div className="mb-1 flex justify-end">
                    <span className={cn('grid size-6 place-items-center rounded-full text-xs', sameDay(d, today) && 'bg-accent font-semibold text-accent-foreground shadow-glow')}>
                      {d.getDate()}
                    </span>
                  </div>
                  <div className="space-y-0.5">
                    {list.slice(0, 3).map((ev) => <EventChip key={ev.uri + ev.calUri} ev={ev} onEvent={onEvent} />)}
                    {list.length > 3 && <p className="px-1.5 text-[0.65rem] text-muted">{t('+{n} more', { n: list.length - 3 })}</p>}
                  </div>
                </div>
              )
            })}
          </Fragment>
        ))}
      </div>
    </Card>
  )
}

function DayColumns({ days, byDay, onDay, onEvent, canCreate }: {
  days: Date[]; byDay: Map<string, AnnEvent[]>; onDay: (d: Date) => void; onEvent: (e: AnnEvent) => void; canCreate: boolean
}) {
  const t = useT()
  const { locale } = useLocale()
  const loc = bcp47(locale)
  const today = new Date()
  return (
    <div className={cn('grid gap-3', days.length === 1 ? 'grid-cols-1' : days.length === 5 ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5' : 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-7')}>
      {days.map((d) => {
        const list = byDay.get(dayKey(d)) ?? []
        const isToday = sameDay(d, today)
        return (
          <Card key={dayKey(d)} className={cn('flex min-h-48 flex-col p-3 lg:min-h-[58vh] 2xl:min-h-[64vh]', isToday && 'bg-accent/10 ring-2 ring-inset ring-accent shadow-glow')}>
            <div className="mb-2 flex items-center justify-between">
              <div>
                <p className={cn('text-[0.65rem] uppercase tracking-wide', isToday ? 'font-semibold text-accent' : 'text-muted')}>
                  {isToday ? t('Today') : d.toLocaleDateString(loc, { weekday: 'short' })}
                </p>
                <p className={cn('text-sm font-semibold', isToday && 'text-accent')}>{d.toLocaleDateString(loc, { day: 'numeric', month: 'short' })}</p>
              </div>
              {canCreate && (
                <button type="button" onClick={() => onDay(d)} className="grid size-6 place-items-center rounded-lg text-muted transition hover:bg-foreground/10 hover:text-foreground" aria-label={t('Add event')}>
                  <Plus className="size-3.5" />
                </button>
              )}
            </div>
            <div className="space-y-1">
              {list.length ? list.map((ev) => <EventChip key={ev.uri + ev.calUri} ev={ev} onEvent={onEvent} />)
                : <p className="py-4 text-center text-xs text-muted">{t('No events')}</p>}
            </div>
          </Card>
        )
      })}
    </div>
  )
}

function ListView({ events, onEvent, canCreate, onNew }: {
  events: AnnEvent[]; onEvent: (e: AnnEvent) => void; canCreate: boolean; onNew: () => void
}) {
  const t = useT()
  const { locale } = useLocale()
  const loc = bcp47(locale)
  const sorted = [...events].sort((a, b) => a.dtstart.localeCompare(b.dtstart))
  if (!sorted.length) {
    return (
      <EmptyState
        icon={CalendarDays}
        title={t('No events')}
        description={canCreate ? t('Create an event in one of your calendars.') : t('No events in the selected calendars.')}
        action={canCreate ? <Button onClick={onNew}><Plus className="size-4" /> {t('New event')}</Button> : undefined}
      />
    )
  }
  return (
    <div className="space-y-2">
      {sorted.map((ev, i) => (
        <motion.div key={ev.uri + ev.calUri} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(i * 0.02, 0.3) }}>
          <Card hover className="group flex items-center gap-4 p-4">
            <div className="grid size-12 shrink-0 place-items-center rounded-xl text-center" style={{ background: `${ev.color}1f`, color: ev.color }}>
              <div className="leading-none">
                <div className="text-[0.6rem] uppercase">{parseEventDate(ev.dtstart).toLocaleDateString(loc, { month: 'short' })}</div>
                <div className="text-base font-semibold">{parseEventDate(ev.dtstart).getDate() || '•'}</div>
              </div>
            </div>
            <div className="min-w-0 flex-1">
              <p className="flex items-center gap-1.5 truncate text-sm font-medium">
                {ev.summary || t('(no title)')}
                {ev.recurring && <Repeat className="size-3 shrink-0 text-muted" />}
              </p>
              <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted">
                <Clock className="size-3" />
                {ev.all_day
                  ? `${t('All day')} · ${shortDate(ev.dtstart, locale)}${ev.dtend && ev.dtend !== ev.dtstart ? ` – ${shortDate(ev.dtend, locale)}` : ''}`
                  : `${dateTime(ev.dtstart, locale)} → ${dateTime(ev.dtend, locale)}`}
                {ev.location && <span className="inline-flex items-center gap-1"><MapPin className="size-3" /> {ev.location}</span>}
                <span className="inline-flex items-center gap-1"><span className="size-2 rounded-full" style={{ background: ev.color }} /> {ev.calName}</span>
              </p>
            </div>
            {ev.writable && (
              <div className="flex shrink-0 gap-1 opacity-0 transition group-hover:opacity-100">
                <Button variant="ghost" size="icon" onClick={() => onEvent(ev)} aria-label={t('Edit')}><Pencil className="size-4" /></Button>
              </div>
            )}
          </Card>
        </motion.div>
      ))}
    </div>
  )
}
