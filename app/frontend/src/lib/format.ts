/** Map an app locale ('en' | 'de') to a BCP-47 tag for Intl APIs. */
export function bcp47(locale: string): string {
  return locale === 'de' ? 'de-DE' : 'en-US'
}

export function relativeTime(iso?: string | null, locale = 'en'): string {
  if (!iso) return '—'
  const then = new Date(iso.replace(' ', 'T'))
  const t = then.getTime()
  if (Number.isNaN(t)) return iso
  const diff = Date.now() - t
  const sec = Math.round(diff / 1000)
  const abs = Math.abs(sec)
  const fmt = (n: number, unit: Intl.RelativeTimeFormatUnit) =>
    new Intl.RelativeTimeFormat(bcp47(locale), { numeric: 'auto' }).format(
      -Math.round(n) * Math.sign(sec || 1),
      unit,
    )
  if (abs < 60) return locale === 'de' ? 'gerade eben' : 'just now'
  if (abs < 3600) return fmt(sec / 60, 'minute')
  if (abs < 86400) return fmt(sec / 3600, 'hour')
  if (abs < 2592000) return fmt(sec / 86400, 'day')
  if (abs < 31536000) return fmt(sec / 2592000, 'month')
  return fmt(sec / 31536000, 'year')
}

export function shortDate(iso?: string | null, locale = 'en'): string {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(bcp47(locale), { month: 'short', day: 'numeric', year: 'numeric' })
}

export function dateTime(iso?: string | null, locale = 'en'): string {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(bcp47(locale), {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function bytes(n?: number): string {
  if (!n && n !== 0) return '—'
  const u = ['B', 'KB', 'MB', 'GB']
  let i = 0
  let v = n
  while (v >= 1024 && i < u.length - 1) {
    v /= 1024
    i++
  }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${u[i]}`
}
