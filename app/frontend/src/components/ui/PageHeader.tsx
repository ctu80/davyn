import { motion } from 'motion/react'
import { cn } from '@/lib/cn'

export function PageHeader({
  title,
  subtitle,
  icon: Icon,
  actions,
  className,
}: {
  title: string
  subtitle?: string
  icon?: React.ComponentType<{ className?: string }>
  actions?: React.ReactNode
  className?: string
}) {
  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4 }}
      className={cn('flex flex-wrap items-end justify-between gap-4', className)}
    >
      <div className="flex items-center gap-3.5">
        {Icon && (
          <div className="grid size-11 place-items-center rounded-2xl bg-accent/12 text-accent ring-1 ring-inset ring-accent/20">
            <Icon className="size-5" />
          </div>
        )}
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
        </div>
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </motion.div>
  )
}
