import { motion } from 'motion/react'

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: React.ComponentType<{ className?: string }>
  title: string
  description?: string
  action?: React.ReactNode
}) {
  return (
    <motion.div
      initial={{ opacity: 0, scale: 0.98 }}
      animate={{ opacity: 1, scale: 1 }}
      className="relative flex flex-col items-center justify-center gap-3 overflow-hidden rounded-2xl border border-dashed border-foreground/12 px-6 py-14 text-center"
    >
      <div className="orb left-1/2 top-2 size-32 -translate-x-1/2 bg-accent/15" />
      <div className="relative grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-accent/20 to-accent/5 text-accent ring-1 ring-inset ring-accent/25 shadow-[0_8px_28px_-8px_rgb(var(--accent)/0.5)]">
        <Icon className="size-7" />
      </div>
      <div>
        <p className="font-medium">{title}</p>
        {description && <p className="mx-auto mt-1 max-w-sm text-sm text-muted">{description}</p>}
      </div>
      {action}
    </motion.div>
  )
}
