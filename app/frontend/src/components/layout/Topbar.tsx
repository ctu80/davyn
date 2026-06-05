import * as Dropdown from '@radix-ui/react-dropdown-menu'
import { motion } from 'motion/react'
import { Menu, Moon, Sun, LogOut, UserCircle, ChevronDown, Search } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTheme } from '@/lib/theme'
import { getCsrfToken } from '@/lib/api'
import { cn } from '@/lib/cn'
import { isMac, modKey } from '@/lib/platform'
import { Brand } from './Brand'
import { useT } from '@/i18n/LocaleContext'
import type { Me } from '@/api/types'

const roleLabelKey: Record<string, string> = { admin: 'Admin', user: 'User', read_only: 'Read only' }

async function logout() {
  const csrf = await getCsrfToken()
  const f = document.createElement('form')
  f.method = 'POST'
  f.action = '/logout'
  const i = document.createElement('input')
  i.type = 'hidden'
  i.name = 'csrf_token'
  i.value = csrf
  f.appendChild(i)
  document.body.appendChild(f)
  f.submit()
}

const roleTone: Record<string, string> = {
  admin: 'text-accent',
  user: 'text-info',
  read_only: 'text-muted',
}

export function Topbar({ me, onMenuClick, onSearchClick }: { me?: Me; onMenuClick: () => void; onSearchClick?: () => void }) {
  const { resolved, toggle } = useTheme()
  const t = useT()
  const initials = (me?.display_name || me?.username || 'D').slice(0, 2).toUpperCase()

  return (
    <header className="glass z-30 flex h-16 items-center gap-3 border-b border-foreground/10 px-4 lg:px-6">
      <button
        onClick={onMenuClick}
        className="grid size-10 place-items-center rounded-xl text-muted transition hover:bg-foreground/5 hover:text-foreground lg:hidden"
        aria-label={t('Open menu')}
      >
        <Menu className="size-5" />
      </button>

      <div className="lg:hidden">
        <Brand />
      </div>

      <div className="hidden flex-col lg:flex">
        <span className="text-sm text-muted">{t('Welcome back')}</span>
        <span className="text-[0.95rem] font-semibold leading-tight">
          {me?.display_name ?? '—'}
        </span>
      </div>

      <div className="ml-auto flex items-center gap-2">
        <button
          onClick={onSearchClick}
          className="hidden h-10 items-center gap-2 rounded-xl bg-foreground/5 px-3 text-sm text-muted ring-1 ring-inset ring-foreground/10 transition hover:bg-foreground/10 hover:text-foreground sm:flex"
          aria-label={t('Search')}
        >
          <Search className="size-4" />
          <span>{t('Search…')}</span>
          <kbd className="rounded bg-foreground/10 px-1.5 py-0.5 text-[0.65rem]">{isMac ? `${modKey}K` : `${modKey} K`}</kbd>
        </button>
        <button
          onClick={onSearchClick}
          className="grid size-10 place-items-center rounded-xl text-muted transition hover:bg-foreground/5 hover:text-foreground sm:hidden"
          aria-label={t('Search')}
        >
          <Search className="size-5" />
        </button>
        <button
          onClick={toggle}
          className="grid size-10 place-items-center rounded-xl text-muted transition hover:bg-foreground/5 hover:text-foreground"
          aria-label={t('Toggle theme')}
        >
          <motion.span key={resolved} initial={{ rotate: -30, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }}>
            {resolved === 'dark' ? <Moon className="size-5" /> : <Sun className="size-5" />}
          </motion.span>
        </button>

        <Dropdown.Root>
          <Dropdown.Trigger asChild>
            <button className="flex items-center gap-2 rounded-xl bg-foreground/5 py-1.5 pl-1.5 pr-2.5 text-sm transition hover:bg-foreground/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60">
              <span className="grid size-7 place-items-center rounded-lg gradient-accent text-xs font-semibold text-white">
                {initials}
              </span>
              <span className="hidden font-medium sm:block">{me?.username ?? '—'}</span>
              <ChevronDown className="size-4 text-muted" />
            </button>
          </Dropdown.Trigger>
          <Dropdown.Portal>
            <Dropdown.Content
              align="end"
              sideOffset={8}
              className="glass-strong z-50 w-56 rounded-xl p-1.5 shadow-soft ring-1 ring-inset ring-foreground/10 data-[state=open]:animate-in"
            >
              <div className="px-2.5 py-2">
                <div className="text-sm font-medium">{me?.display_name}</div>
                <div className={cn('text-xs font-medium', roleTone[me?.role ?? 'user'])}>
                  {t(roleLabelKey[me?.role ?? 'user'] ?? 'User')}
                </div>
              </div>
              <div className="my-1 h-px bg-foreground/10" />
              <Dropdown.Item asChild>
                <Link
                  to="/account"
                  className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-muted-strong outline-none transition data-[highlighted]:bg-foreground/8 data-[highlighted]:text-foreground"
                >
                  <UserCircle className="size-4" /> {t('Account')}
                </Link>
              </Dropdown.Item>
              <Dropdown.Item
                onSelect={() => logout()}
                className="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-danger outline-none transition data-[highlighted]:bg-danger/10"
              >
                <LogOut className="size-4" /> {t('Sign out')}
              </Dropdown.Item>
            </Dropdown.Content>
          </Dropdown.Portal>
        </Dropdown.Root>
      </div>
    </header>
  )
}
