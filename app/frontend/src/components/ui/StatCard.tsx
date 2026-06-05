import { motion } from 'motion/react'
import { Link } from 'react-router-dom'
import { ArrowUpRight } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Card } from './Card'

export function StatCard({
  label,
  value,
  icon: Icon,
  sub,
  accent = 'accent',
  index = 0,
  to,
}: {
  label: string
  value: React.ReactNode
  icon: React.ComponentType<{ className?: string }>
  sub?: React.ReactNode
  accent?: 'accent' | 'success' | 'warning' | 'danger' | 'info'
  index?: number
  /** When set, the card becomes a keyboard-accessible link to this route. */
  to?: string
}) {
  const tone = {
    accent: 'text-accent bg-accent/12 ring-accent/25 shadow-[0_6px_20px_-6px_rgb(var(--accent)/0.55)]',
    success: 'text-success bg-success/12 ring-success/25 shadow-[0_6px_20px_-6px_rgb(var(--success)/0.55)]',
    warning: 'text-warning bg-warning/12 ring-warning/25 shadow-[0_6px_20px_-6px_rgb(var(--warning)/0.55)]',
    danger: 'text-danger bg-danger/12 ring-danger/25 shadow-[0_6px_20px_-6px_rgb(var(--danger)/0.55)]',
    info: 'text-info bg-info/12 ring-info/25 shadow-[0_6px_20px_-6px_rgb(var(--info)/0.55)]',
  }[accent]
  const glow = {
    accent: 'bg-accent/20',
    success: 'bg-success/20',
    warning: 'bg-warning/20',
    danger: 'bg-danger/20',
    info: 'bg-info/20',
  }[accent]

  const inner = (
    <Card hover className="h-full p-5">
      <div className={cn('orb -right-6 -top-8 size-24 opacity-60', glow)} />
      <div className="relative flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-xs font-medium uppercase tracking-wide text-muted">{label}</p>
          <p className="mt-2 truncate text-[1.7rem] font-semibold tracking-tight tabular-nums">{value}</p>
          {sub && <div className="mt-1 text-xs text-muted">{sub}</div>}
        </div>
        <div className={cn('grid size-11 shrink-0 place-items-center rounded-2xl ring-1 ring-inset', tone)}>
          <Icon className="size-5" />
        </div>
      </div>
      {to && (
        <ArrowUpRight className="absolute bottom-3.5 right-3.5 size-4 text-muted opacity-0 transition-all duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
      )}
    </Card>
  )

  return (
    <motion.div
      initial={{ opacity: 0, y: 12 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.45, delay: index * 0.06 }}
      className="h-full"
    >
      {to ? (
        <Link
          to={to}
          className="group block h-full rounded-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
        >
          {inner}
        </Link>
      ) : (
        inner
      )}
    </motion.div>
  )
}
