import * as RSelect from '@radix-ui/react-select'
import { Check, ChevronDown } from 'lucide-react'
import { cn } from '@/lib/cn'

export interface Option {
  value: string
  label: string
  /** Optional group heading; consecutive options sharing a group are boxed together. */
  group?: string
}

export function Select({
  value,
  onValueChange,
  options,
  placeholder = 'Select…',
  className,
}: {
  value?: string
  onValueChange: (v: string) => void
  options: Option[]
  placeholder?: string
  className?: string
}) {
  // Group options while preserving order; ungrouped options render flat.
  const grouped: { group?: string; items: Option[] }[] = []
  for (const o of options) {
    const last = grouped[grouped.length - 1]
    if (last && last.group === o.group) last.items.push(o)
    else grouped.push({ group: o.group, items: [o] })
  }
  const hasGroups = options.some((o) => o.group)
  return (
    <RSelect.Root value={value} onValueChange={onValueChange}>
      <RSelect.Trigger
        className={cn(
          'inline-flex h-10 w-full items-center justify-between gap-2 rounded-xl bg-foreground/5 px-3.5 text-sm ring-1 ring-inset ring-foreground/10 transition focus:outline-none focus:ring-2 focus:ring-accent/60 data-[placeholder]:text-muted',
          className,
        )}
      >
        <RSelect.Value placeholder={placeholder} />
        <RSelect.Icon>
          <ChevronDown className="size-4 text-muted" />
        </RSelect.Icon>
      </RSelect.Trigger>
      <RSelect.Portal>
        <RSelect.Content
          position="popper"
          sideOffset={6}
          className="glass-strong z-50 max-h-72 overflow-hidden rounded-xl p-1 shadow-soft ring-1 ring-inset ring-foreground/10"
        >
          <RSelect.Viewport className="p-1">
            {grouped.map((g, gi) => (
              <RSelect.Group key={g.group ?? gi}>
                {hasGroups && g.group && (
                  <RSelect.Label className="px-2 pb-1 pt-2 text-[0.65rem] font-semibold uppercase tracking-wide text-muted">
                    {g.group}
                  </RSelect.Label>
                )}
                {g.items.map((o) => (
                  <RSelect.Item
                    key={o.value}
                    value={o.value}
                    className="relative flex cursor-pointer select-none items-center rounded-lg py-2 pl-8 pr-3 text-sm outline-none data-[highlighted]:bg-accent/12 data-[highlighted]:text-foreground"
                  >
                    <RSelect.ItemIndicator className="absolute left-2 inline-flex">
                      <Check className="size-4 text-accent" />
                    </RSelect.ItemIndicator>
                    <RSelect.ItemText>{o.label}</RSelect.ItemText>
                  </RSelect.Item>
                ))}
              </RSelect.Group>
            ))}
          </RSelect.Viewport>
        </RSelect.Content>
      </RSelect.Portal>
    </RSelect.Root>
  )
}
