import { NavLink } from 'react-router-dom'
import { motion } from 'motion/react'
import { cn } from '@/lib/cn'
import { adminNav, userNav, type NavItem } from '@/lib/nav'
import { Brand } from './Brand'
import { useT } from '@/i18n/LocaleContext'

function NavRow({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  const t = useT()
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      className="group relative block"
    >
      {({ isActive }) => (
        <div
          className={cn(
            'relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors',
            isActive ? 'text-foreground' : 'text-muted hover:bg-foreground/5 hover:text-foreground',
          )}
        >
          {isActive && (
            <motion.div
              layoutId="nav-active"
              transition={{ type: 'spring', stiffness: 400, damping: 32 }}
              className="absolute inset-0 rounded-xl bg-gradient-to-r from-accent/20 to-accent/[0.06] ring-1 ring-inset ring-accent/30 shadow-[0_4px_18px_-6px_rgb(var(--accent)/0.5)]"
            />
          )}
          {isActive && (
            <motion.div
              layoutId="nav-bar"
              transition={{ type: 'spring', stiffness: 400, damping: 32 }}
              className="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-full gradient-accent shadow-[0_0_12px_rgb(var(--accent)/0.8)]"
            />
          )}
          <Icon
            className={cn(
              'relative size-[1.05rem] transition-colors',
              isActive && 'text-accent [filter:drop-shadow(0_0_6px_rgb(var(--accent)/0.6))]',
            )}
          />
          <span className="relative font-medium">{t(item.label)}</span>
        </div>
      )}
    </NavLink>
  )
}

export function SidebarContent({
  isAdmin,
  brandName,
  onNavigate,
}: {
  isAdmin: boolean
  brandName?: string
  onNavigate?: () => void
}) {
  const t = useT()
  return (
    <div className="relative flex h-full flex-col gap-6 overflow-hidden p-4">
      <div className="orb -left-10 -top-12 size-40 animate-float-slow bg-accent/25" />
      <div className="px-2 pt-2">
        <Brand name={brandName} />
      </div>

      <nav className="flex flex-1 flex-col gap-1">
        <p className="px-3 pb-1 text-[0.68rem] font-semibold uppercase tracking-wider text-muted/70">
          {t('Workspace')}
        </p>
        {userNav.map((item) => (
          <NavRow key={item.to} item={item} onNavigate={onNavigate} />
        ))}

        {isAdmin && (
          <>
            <p className="px-3 pb-1 pt-5 text-[0.68rem] font-semibold uppercase tracking-wider text-muted/70">
              {t('Administration')}
            </p>
            {adminNav.map((item) => (
              <NavRow key={item.to} item={item} onNavigate={onNavigate} />
            ))}
          </>
        )}
      </nav>

      <div className="rounded-xl bg-foreground/5 p-3 text-[0.7rem] text-muted ring-1 ring-inset ring-foreground/10">
        <span className="gradient-text font-semibold">Davyn</span> &middot; {t('self-hosted CalDAV & CardDAV')}
      </div>
    </div>
  )
}

export function Sidebar({ isAdmin, brandName }: { isAdmin: boolean; brandName?: string }) {
  return (
    <aside className="glass sticky top-0 hidden h-screen w-64 shrink-0 border-r border-foreground/10 lg:block">
      <SidebarContent isAdmin={isAdmin} brandName={brandName} />
    </aside>
  )
}
