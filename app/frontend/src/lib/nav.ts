import {
  LayoutDashboard,
  CalendarDays,
  Contact,
  ArrowLeftRight,
  Link2,
  UserCircle,
  ShieldCheck,
  Users,
  FolderTree,
  Share2,
  DatabaseBackup,
  Activity,
  Settings,
  Lock,
  type LucideIcon,
} from 'lucide-react'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
}

export const userNav: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays },
  { to: '/contacts', label: 'Contacts', icon: Contact },
  { to: '/sharing', label: 'Sharing', icon: Share2 },
  { to: '/links', label: 'Public Links', icon: Link2 },
  { to: '/import', label: 'Import / Export', icon: ArrowLeftRight },
  { to: '/account', label: 'Account', icon: UserCircle },
]

export const adminNav: NavItem[] = [
  { to: '/admin', label: 'Status', icon: ShieldCheck, end: true },
  { to: '/admin/users', label: 'Users', icon: Users },
  { to: '/admin/collections', label: 'Collections', icon: FolderTree },
  { to: '/admin/sharing', label: 'Sharing', icon: Share2 },
  { to: '/admin/backups', label: 'Backups', icon: DatabaseBackup },
  { to: '/admin/activity', label: 'Activity', icon: Activity },
  { to: '/admin/security', label: 'Security', icon: Lock },
  { to: '/admin/settings', label: 'Settings', icon: Settings },
]
