import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AnimatePresence, motion } from 'motion/react'
import {
  Search, CalendarDays, Contact, Link2, Users, DatabaseBackup, ArrowRight, CornerDownLeft, Clock,
  type LucideIcon,
} from 'lucide-react'
import { userNav, adminNav } from '@/lib/nav'
import { useAddressBooks, useCalendars, useMultiContacts, useMultiEvents, usePublicLinks } from '@/api/user'
import { useBackups, useUsers } from '@/api/admin'
import { shortDate } from '@/lib/format'
import { contactSearchText } from '@/lib/contacts'
import { cn } from '@/lib/cn'
import { useT, useLocale } from '@/i18n/LocaleContext'

interface Item {
  id: string
  label: string
  sublabel?: string
  group: string
  icon: LucideIcon
  to: string
  /** Extra hidden text folded into the search match (e.g. all contact fields). */
  keywords?: string
}

export function CommandPalette({
  open,
  onOpenChange,
  isAdmin,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  isAdmin: boolean
}) {
  const navigate = useNavigate()
  const t = useT()
  const { locale } = useLocale()
  const [q, setQ] = useState('')
  const [active, setActive] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  // Data sources — admin ones only fetch for admins (gated by `enabled`).
  const { data: calendars } = useCalendars()
  const { data: addressbooks } = useAddressBooks()
  const { data: links } = usePublicLinks()
  const { data: users } = useUsers(isAdmin)
  const { data: backups } = useBackups(isAdmin)
  // Events are only fetched while the palette is open (avoids loading every
  // calendar's events on every page).
  const calUris = useMemo(() => (calendars ?? []).map((c) => c.uri), [calendars])
  const { byUri: eventsByCal } = useMultiEvents(calUris, open)
  // Contacts are only fetched while the palette is open (every field is searchable).
  const abUris = useMemo(() => (addressbooks ?? []).map((a) => a.uri), [addressbooks])
  const { byUri: contactsByAb } = useMultiContacts(abUris, open)

  // Global Cmd/Ctrl+K toggle.
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        onOpenChange(!open)
      }
      if (e.key === 'Escape' && open) onOpenChange(false)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onOpenChange])

  useEffect(() => {
    if (open) {
      setQ('')
      setActive(0)
      setTimeout(() => inputRef.current?.focus(), 30)
    }
  }, [open])

  const items = useMemo<Item[]>(() => {
    const nav = [...userNav, ...(isAdmin ? adminNav : [])]
    const out: Item[] = nav.map((n) => ({
      id: `nav:${n.to}`, label: t(n.label), group: t('Pages'), icon: n.icon, to: n.to,
    }))
    for (const c of calendars ?? []) out.push({ id: `cal:${c.uri}`, label: c.display_name, sublabel: c.shared ? t('Shared calendar') : t('Calendar'), group: t('Calendars'), icon: CalendarDays, to: '/calendar' })
    for (const a of addressbooks ?? []) out.push({ id: `ab:${a.uri}`, label: a.display_name, sublabel: t('Address book'), group: t('Address books'), icon: Contact, to: '/contacts' })
    for (const a of addressbooks ?? []) {
      for (const c of contactsByAb[a.uri] ?? []) {
        const name = c.fn || `${c.first_name} ${c.last_name}`.trim() || c.org || t('(no name)')
        out.push({
          id: `contact:${a.uri}:${c.uri}`,
          label: name,
          // Show org / primary email / phone as a hint; full match is via keywords.
          sublabel: [c.org, c.email || c.tel, a.display_name].filter(Boolean).join(' · '),
          group: t('Contacts'),
          icon: Contact,
          // Query params (not router state) so the Contacts route opens it
          // reliably even when mounting fresh from another page.
          to: `/contacts?ab=${encodeURIComponent(a.uri)}&cn=${encodeURIComponent(c.uri)}`,
          keywords: contactSearchText(c),
        })
      }
    }
    for (const c of calendars ?? []) {
      for (const ev of eventsByCal[c.uri] ?? []) {
        out.push({
          id: `ev:${c.uri}:${ev.uri}`,
          label: ev.summary || t('(no title)'),
          sublabel: `${shortDate(ev.dtstart, locale)} · ${c.display_name}`,
          group: t('Events'),
          icon: Clock,
          to: `/calendar?ecal=${encodeURIComponent(c.uri)}&euri=${encodeURIComponent(ev.uri)}`,
        })
      }
    }
    for (const l of links ?? []) {
      if (l.revoked_at) continue
      out.push({ id: `link:${l.id}`, label: l.name || l.display_name || t('Calendar feed'), sublabel: t('Public link'), group: t('Public links'), icon: Link2, to: '/links' })
    }
    if (isAdmin) {
      for (const u of users ?? []) out.push({ id: `user:${u.username}`, label: u.display_name, sublabel: `@${u.username} · ${u.role.replace('_', ' ')}`, group: t('Users'), icon: Users, to: '/admin/users' })
      for (const b of backups ?? []) out.push({ id: `bk:${b.filename}`, label: b.filename, sublabel: t('Backup'), group: t('Backups'), icon: DatabaseBackup, to: '/admin/backups' })
    }
    return out
  }, [calendars, addressbooks, contactsByAb, links, users, backups, isAdmin, eventsByCal, t, locale])

  const results = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const list = !needle ? items : items.filter((i) => `${i.label} ${i.sublabel ?? ''} ${i.group} ${i.keywords ?? ''}`.toLowerCase().includes(needle))
    return list.slice(0, 40)
  }, [items, q])

  // Keep the active index in range as results change.
  useEffect(() => { setActive(0) }, [q])

  function select(item: Item) {
    onOpenChange(false)
    navigate(item.to)
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, results.length - 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)) }
    else if (e.key === 'Enter' && results[active]) { e.preventDefault(); select(results[active]) }
  }

  // Group results in display order while keeping a flat index for keyboarding.
  let flatIndex = -1
  const groups: { name: string; items: { item: Item; idx: number }[] }[] = []
  for (const item of results) {
    flatIndex++
    const g = groups.find((x) => x.name === item.group)
    const entry = { item, idx: flatIndex }
    if (g) g.items.push(entry)
    else groups.push({ name: item.group, items: [entry] })
  }

  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            onClick={() => onOpenChange(false)}
            className="fixed inset-0 z-50 bg-black/55 backdrop-blur-sm"
          />
          <motion.div
            initial={{ opacity: 0, scale: 0.98, y: -8 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.98, y: -8 }}
            transition={{ type: 'spring', stiffness: 360, damping: 30 }}
            className="glass-strong fixed left-1/2 top-[12vh] z-50 w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 overflow-hidden rounded-2xl shadow-soft ring-1 ring-inset ring-foreground/10"
          >
            <div className="flex items-center gap-3 border-b border-foreground/10 px-4">
              <Search className="size-[1.1rem] shrink-0 text-muted" />
              <input
                ref={inputRef}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={onKeyDown}
                placeholder={t('Search pages, calendars, contacts, users…')}
                className="h-14 w-full bg-transparent text-sm outline-none placeholder:text-muted"
              />
              <kbd className="hidden rounded bg-foreground/10 px-1.5 py-0.5 text-[0.65rem] text-muted sm:block">ESC</kbd>
            </div>
            <div className="max-h-[55vh] overflow-y-auto p-2">
              {results.length === 0 ? (
                <p className="px-3 py-8 text-center text-sm text-muted">{t('No results for "{q}".', { q })}</p>
              ) : (
                groups.map((g) => (
                  <div key={g.name} className="mb-1">
                    <p className="px-2.5 pb-1 pt-2 text-[0.65rem] font-semibold uppercase tracking-wider text-muted/70">{g.name}</p>
                    {g.items.map(({ item, idx }) => {
                      const Icon = item.icon
                      return (
                        <button
                          key={item.id}
                          type="button"
                          onMouseEnter={() => setActive(idx)}
                          onClick={() => select(item)}
                          className={cn(
                            'flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition',
                            idx === active ? 'bg-accent/12 text-foreground' : 'text-muted-strong hover:bg-foreground/5',
                          )}
                        >
                          <Icon className={cn('size-4 shrink-0', idx === active ? 'text-accent' : 'text-muted')} />
                          <span className="flex-1 truncate">{item.label}</span>
                          {item.sublabel && <span className="hidden truncate text-xs text-muted sm:block">{item.sublabel}</span>}
                          {idx === active ? <CornerDownLeft className="size-3.5 text-accent" /> : <ArrowRight className="size-3.5 opacity-0" />}
                        </button>
                      )
                    })}
                  </div>
                ))
              )}
            </div>
          </motion.div>
        </>
      )}
    </AnimatePresence>
  )
}
