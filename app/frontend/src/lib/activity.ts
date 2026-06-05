import {
  Activity, Users, CalendarDays, Contact, DatabaseBackup, Link2, KeyRound,
  Share2, Settings, ArrowLeftRight, LogIn, type LucideIcon,
} from 'lucide-react'

export type ActivityCategory =
  | 'auth' | 'users' | 'calendar' | 'contacts' | 'backups'
  | 'public_links' | 'app_passwords' | 'sharing' | 'settings' | 'import' | 'other'

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info'

interface Meta {
  category: ActivityCategory
  label: string
  icon: LucideIcon
  tone: Tone
}

/** Maps a dot-namespaced action key to a display category. Robust to prefixes
 *  like `admin.` / `user.` and to actions we don't recognise. */
export function activityMeta(action: string): Meta {
  const a = (action || '').toLowerCase()
  const has = (s: string) => a.includes(s)

  if (has('login') || has('logout') || has('reauth')) return { category: 'auth', label: 'Auth', icon: LogIn, tone: 'info' }
  if (has('app_password')) return { category: 'app_passwords', label: 'App password', icon: KeyRound, tone: 'warning' }
  if (has('public_link')) return { category: 'public_links', label: 'Public link', icon: Link2, tone: 'accent' }
  if (has('share')) return { category: 'sharing', label: 'Sharing', icon: Share2, tone: 'info' }
  if (has('backup')) return { category: 'backups', label: 'Backup', icon: DatabaseBackup, tone: 'success' }
  if (has('event') || has('calendar')) return { category: 'calendar', label: 'Calendar', icon: CalendarDays, tone: 'accent' }
  if (has('contact') || has('addressbook')) return { category: 'contacts', label: 'Contacts', icon: Contact, tone: 'info' }
  if (has('import') || has('export')) return { category: 'import', label: 'Import', icon: ArrowLeftRight, tone: 'neutral' }
  if (has('settings')) return { category: 'settings', label: 'Settings', icon: Settings, tone: 'neutral' }
  if (has('user')) return { category: 'users', label: 'Users', icon: Users, tone: 'warning' }
  return { category: 'other', label: 'Event', icon: Activity, tone: 'neutral' }
}

export const ACTIVITY_CATEGORIES: { value: ActivityCategory | 'all'; label: string }[] = [
  { value: 'all', label: 'All types' },
  { value: 'auth', label: 'Login / Logout' },
  { value: 'users', label: 'Users' },
  { value: 'calendar', label: 'Calendar' },
  { value: 'contacts', label: 'Contacts' },
  { value: 'backups', label: 'Backups' },
  { value: 'public_links', label: 'Public links' },
  { value: 'app_passwords', label: 'App passwords' },
  { value: 'sharing', label: 'Sharing' },
  { value: 'settings', label: 'Settings' },
  { value: 'import', label: 'Import / Export' },
]

/** Best human-readable line for an entry: the stored summary, falling back to a
 *  de-keyed action. */
export function activityText(entry: { summary?: string; detail?: string; action?: string }): string {
  return entry.summary || entry.detail || (entry.action ? entry.action.replace(/[._]/g, ' ') : '')
}
