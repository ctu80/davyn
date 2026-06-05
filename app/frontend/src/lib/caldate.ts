// Small, timezone-safe date helpers for the calendar views. Event date strings
// are local wall-clock ('YYYY-MM-DD' for all-day, 'YYYY-MM-DD HH:mm[:ss]' or ISO
// for timed) — we never shift them through UTC.

export function pad(n: number) {
  return String(n).padStart(2, '0')
}

/** Local YYYY-MM-DD key for a Date. */
export function dayKey(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** Parse an event date string to a local Date (anchored to midnight if date-only). */
export function parseEventDate(v: string): Date {
  if (!v) return new Date(NaN)
  if (v.length === 10) return new Date(`${v}T00:00`)
  return new Date(v.replace(' ', 'T'))
}

export function addDays(d: Date, n: number): Date {
  const x = new Date(d)
  x.setDate(x.getDate() + n)
  return x
}

export function addMonths(d: Date, n: number): Date {
  const x = new Date(d)
  x.setMonth(x.getMonth() + n)
  return x
}

export function startOfDay(d: Date): Date {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}

/** Monday-first start of the week containing d. */
export function startOfWeek(d: Date): Date {
  const x = startOfDay(d)
  const dow = (x.getDay() + 6) % 7 // 0 = Monday
  return addDays(x, -dow)
}

export function sameDay(a: Date, b: Date): boolean {
  return dayKey(a) === dayKey(b)
}

/** ISO-8601 week number (1–53), Monday-first, week 1 contains the first Thursday. */
export function isoWeek(date: Date): number {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
  const dayNum = (d.getUTCDay() + 6) % 7 // Mon=0 … Sun=6
  d.setUTCDate(d.getUTCDate() - dayNum + 3) // shift to the Thursday of this week
  const firstThursday = new Date(Date.UTC(d.getUTCFullYear(), 0, 4))
  const firstDayNum = (firstThursday.getUTCDay() + 6) % 7
  firstThursday.setUTCDate(firstThursday.getUTCDate() - firstDayNum + 3)
  return 1 + Math.round((d.getTime() - firstThursday.getTime()) / (7 * 24 * 3600 * 1000))
}

/** 6×7 matrix of dates covering the month of `anchor`, Monday-first. */
export function monthMatrix(anchor: Date): Date[] {
  const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1)
  const start = startOfWeek(first)
  return Array.from({ length: 42 }, (_, i) => addDays(start, i))
}
