import { useEffect, useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, CalendarDays, Clock } from 'lucide-react'
import { Popover } from './Popover'
import { cn } from '@/lib/cn'
import { useT, useLocale } from '@/i18n/LocaleContext'
import { bcp47 } from '@/lib/format'

/* ── date helpers (all timezone-safe, value strings are local wall-clock) ──
   date value:     'YYYY-MM-DD'
   datetime value: 'YYYY-MM-DDTHH:mm'  (matches <input type=datetime-local>) */

function pad(n: number) {
  return String(n).padStart(2, '0')
}
function ymd(y: number, m: number, d: number) {
  return `${y}-${pad(m + 1)}-${pad(d)}`
}
function parseYmd(v: string): { y: number; m: number; d: number } | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v)
  if (!m) return null
  return { y: +m[1], m: +m[2] - 1, d: +m[3] }
}
function todayYmd(): { y: number; m: number; d: number } {
  const n = new Date()
  return { y: n.getFullYear(), m: n.getMonth(), d: n.getDate() }
}
/** Monday-first weekday index (0..6) of the 1st of the month. */
function firstWeekday(y: number, m: number) {
  return (new Date(y, m, 1).getDay() + 6) % 7
}
function daysInMonth(y: number, m: number) {
  return new Date(y, m + 1, 0).getDate()
}
function formatDateLabel(v: string, loc: string): string {
  const p = parseYmd(v)
  if (!p) return ''
  return new Date(p.y, p.m, p.d).toLocaleDateString(loc, {
    weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
  })
}

/** Parse a manually typed date into 'YYYY-MM-DD', or null if it isn't a valid date.
 *  Accepts ISO 'YYYY-MM-DD' (also '/' or '.') and day-first 'DD.MM.YYYY' / 'DD.MM.YY'. */
function parseFlexibleDate(raw: string): string | null {
  const s = raw.trim()
  if (!s) return null
  let y: number, m: number, d: number
  let mt: RegExpExecArray | null
  if ((mt = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(s))) {
    y = +mt[1]; m = +mt[2]; d = +mt[3]
  } else if ((mt = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})$/.exec(s))) {
    d = +mt[1]; m = +mt[2]; y = +mt[3]
    if (y < 100) y += y >= 70 ? 1900 : 2000
  } else {
    return null
  }
  if (m < 1 || m > 12 || d < 1 || d > 31) return null
  // Reject impossible dates (e.g. 31.02) by round-tripping through Date.
  const dt = new Date(y, m - 1, d)
  if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return null
  return ymd(y, m - 1, d)
}

const fieldInput =
  'w-full rounded-lg bg-foreground/5 px-2.5 py-1.5 text-sm ring-1 ring-inset ring-foreground/10 focus:outline-none focus:ring-2 focus:ring-accent/60'

/** Manual date entry inside the picker popover. Updates live as a valid date is typed;
 *  reverts to the current value on blur if what was typed isn't a complete valid date. */
function ManualDateInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const t = useT()
  const [text, setText] = useState(value)
  useEffect(() => { setText(value) }, [value])

  return (
    <input
      type="text"
      inputMode="numeric"
      value={text}
      onChange={(e) => {
        setText(e.target.value)
        const parsed = parseFlexibleDate(e.target.value)
        if (parsed) onChange(parsed)
      }}
      onBlur={() => setText(value)}
      placeholder={t('Type a date, e.g. YYYY-MM-DD')}
      aria-label={t('Type a date')}
      className={cn(fieldInput, 'mb-2')}
    />
  )
}

function MonthGrid({ value, onSelect }: { value: string; onSelect: (v: string) => void }) {
  const t = useT()
  const { locale } = useLocale()
  const loc = bcp47(locale)
  // Localised month names and Monday-first weekday abbreviations.
  const months = useMemo(
    () => Array.from({ length: 12 }, (_, i) => new Date(2024, i, 1).toLocaleDateString(loc, { month: 'long' })),
    [loc],
  )
  const weekdays = useMemo(
    () => Array.from({ length: 7 }, (_, i) => new Date(2024, 0, 1 + i).toLocaleDateString(loc, { weekday: 'short' })),
    [loc],
  )
  const sel = parseYmd(value)
  const today = todayYmd()
  const init = sel ?? today
  const [view, setView] = useState({ y: init.y, m: init.m })

  // Follow the value when it changes externally (e.g. typed into the manual field).
  useEffect(() => {
    const p = parseYmd(value)
    if (p) setView({ y: p.y, m: p.m })
  }, [value])

  const lead = firstWeekday(view.y, view.m)
  const total = daysInMonth(view.y, view.m)
  const cells: (number | null)[] = [
    ...Array<null>(lead).fill(null),
    ...Array.from({ length: total }, (_, i) => i + 1),
  ]
  while (cells.length % 7 !== 0) cells.push(null)

  function shift(delta: number) {
    setView((v) => {
      const m = v.m + delta
      return { y: v.y + Math.floor(m / 12), m: ((m % 12) + 12) % 12 }
    })
  }

  return (
    <div className="w-64">
      <div className="mb-2 flex items-center justify-between px-1">
        <button type="button" onClick={() => shift(-1)} aria-label={t('Previous month')}
          className="grid size-7 place-items-center rounded-lg text-muted transition hover:bg-foreground/10 hover:text-foreground">
          <ChevronLeft className="size-4" />
        </button>
        <span className="text-sm font-semibold">{months[view.m]} {view.y}</span>
        <button type="button" onClick={() => shift(1)} aria-label={t('Next month')}
          className="grid size-7 place-items-center rounded-lg text-muted transition hover:bg-foreground/10 hover:text-foreground">
          <ChevronRight className="size-4" />
        </button>
      </div>
      <div className="mb-1 grid grid-cols-7 gap-0.5">
        {weekdays.map((w, i) => (
          <span key={i} className="grid h-7 place-items-center text-[0.65rem] font-medium uppercase text-muted">{w}</span>
        ))}
      </div>
      <div className="grid grid-cols-7 gap-0.5">
        {cells.map((d, i) => {
          if (d === null) return <span key={i} />
          const isSel = sel && sel.y === view.y && sel.m === view.m && sel.d === d
          const isToday = today.y === view.y && today.m === view.m && today.d === d
          return (
            <button
              key={i}
              type="button"
              onClick={() => onSelect(ymd(view.y, view.m, d))}
              className={cn(
                'grid h-8 place-items-center rounded-lg text-sm transition',
                isSel
                  ? 'bg-accent font-semibold text-accent-foreground shadow-glow'
                  : 'hover:bg-foreground/10',
                !isSel && isToday && 'font-semibold text-accent ring-1 ring-inset ring-accent/40',
              )}
            >
              {d}
            </button>
          )
        })}
      </div>
      <button
        type="button"
        onClick={() => onSelect(ymd(today.y, today.m, today.d))}
        className="mt-2 w-full rounded-lg py-1.5 text-xs font-medium text-accent transition hover:bg-accent/10"
      >
        {t('Today')}
      </button>
    </div>
  )
}

const triggerBase =
  'inline-flex h-10 w-full items-center gap-2 rounded-xl bg-foreground/5 px-3.5 text-left text-sm ring-1 ring-inset ring-foreground/10 transition hover:ring-foreground/20 focus:outline-none focus:ring-2 focus:ring-accent/60'

/** Date-only picker. value/onChange use 'YYYY-MM-DD'. */
export function DatePicker({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
}) {
  const t = useT()
  const { locale } = useLocale()
  const [open, setOpen] = useState(false)
  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      align="start"
      portal
      trigger={
        <button type="button" className={triggerBase}>
          <CalendarDays className="size-4 shrink-0 text-muted" />
          <span className={cn('flex-1 truncate', !value && 'text-muted')}>
            {value ? formatDateLabel(value, bcp47(locale)) : (placeholder ?? t('Pick a date'))}
          </span>
        </button>
      }
    >
      <div className="w-64">
        <ManualDateInput value={value} onChange={onChange} />
        <MonthGrid
          value={value}
          onSelect={(v) => {
            onChange(v)
            setOpen(false)
          }}
        />
      </div>
    </Popover>
  )
}

/** Date + time picker. value/onChange use 'YYYY-MM-DDTHH:mm'. */
export function DateTimePicker({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
}) {
  const t = useT()
  const { locale } = useLocale()
  const [open, setOpen] = useState(false)
  const datePart = value.slice(0, 10)
  const timePart = value.length >= 16 ? value.slice(11, 16) : ''

  function setDate(d: string) {
    onChange(`${d}T${timePart || '09:00'}`)
  }
  function setTime(t: string) {
    // Keep the date if present; otherwise seed with today so the value is valid.
    const base = datePart || (() => { const n = todayYmd(); return ymd(n.y, n.m, n.d) })()
    onChange(`${base}T${t || '09:00'}`)
  }

  return (
    <Popover
      open={open}
      onOpenChange={setOpen}
      align="start"
      portal
      trigger={
        <button type="button" className={triggerBase}>
          <CalendarDays className="size-4 shrink-0 text-muted" />
          <span className={cn('flex-1 truncate', !value && 'text-muted')}>
            {datePart ? formatDateLabel(datePart, bcp47(locale)) : (placeholder ?? t('Pick a date'))}
          </span>
          {timePart && <span className="shrink-0 text-xs font-medium text-accent">{timePart}</span>}
        </button>
      }
    >
      <div className="w-64">
        <ManualDateInput value={datePart} onChange={setDate} />
        <MonthGrid value={datePart} onSelect={setDate} />
        <div className="mt-2 flex items-center gap-2 border-t border-foreground/10 pt-2">
          <Clock className="size-4 shrink-0 text-muted" />
          <input
            type="time"
            value={timePart}
            onChange={(e) => setTime(e.target.value)}
            className={fieldInput}
          />
        </div>
      </div>
    </Popover>
  )
}
