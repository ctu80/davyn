import * as RTabs from '@radix-ui/react-tabs'
import { motion } from 'motion/react'
import { cn } from '@/lib/cn'

export interface TabDef {
  value: string
  label: string
  icon?: React.ComponentType<{ className?: string }>
}

export function Tabs({
  tabs,
  value,
  onValueChange,
  children,
}: {
  tabs: TabDef[]
  value: string
  onValueChange: (v: string) => void
  children: React.ReactNode
}) {
  return (
    <RTabs.Root value={value} onValueChange={onValueChange}>
      <RTabs.List className="mb-6 inline-flex gap-1 rounded-xl bg-foreground/5 p-1 ring-1 ring-inset ring-foreground/10">
        {tabs.map((t) => {
          const active = t.value === value
          const Icon = t.icon
          return (
            <RTabs.Trigger
              key={t.value}
              value={t.value}
              className={cn(
                'relative inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors',
                active ? 'text-foreground' : 'text-muted hover:text-foreground',
              )}
            >
              {active && (
                <motion.span
                  layoutId="tab-active"
                  transition={{ type: 'spring', stiffness: 400, damping: 32 }}
                  className="absolute inset-0 rounded-lg bg-bg-elevated shadow-soft ring-1 ring-inset ring-foreground/10"
                />
              )}
              {Icon && <Icon className="relative size-4" />}
              <span className="relative">{t.label}</span>
            </RTabs.Trigger>
          )
        })}
      </RTabs.List>
      {children}
    </RTabs.Root>
  )
}

export const TabPanel = RTabs.Content
