import type { LucideIcon } from 'lucide-react'

/** A titled group of fields inside a form/modal. */
export function FormSection({
  title,
  icon: Icon,
  children,
  aside,
}: {
  title: string
  icon?: LucideIcon
  children: React.ReactNode
  aside?: React.ReactNode
}) {
  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted">
          {Icon && <Icon className="size-3.5" />}
          {title}
        </h3>
        {aside}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  )
}
