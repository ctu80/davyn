import type { Contact } from '@/api/types'

/**
 * Flatten every meaningful field of a contact into one lowercase string for
 * substring search — name parts, nickname, org/title (work place), notes, URL,
 * birthday, categories, and all emails/phones/addresses (including their types).
 * Used by both the per-page contact filter and the global command palette so
 * searching for a phone number, company, city, etc. all hit.
 */
export function contactSearchText(c: Contact): string {
  const parts: string[] = [
    c.fn, c.first_name, c.last_name, c.nickname, c.org, c.title, c.note, c.url, c.bday,
    ...(c.categories ?? []),
    ...(c.emails ?? []).flatMap((e) => [e.value, e.type]),
    ...(c.phones ?? []).flatMap((p) => [p.value, p.type]),
    ...(c.addresses ?? []).flatMap((a) => [a.street, a.city, a.region, a.code, a.country, a.type]),
  ]
  return parts.filter(Boolean).join(' ').toLowerCase()
}
