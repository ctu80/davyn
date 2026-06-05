import { useState } from 'react'
import { Check } from 'lucide-react'
import { Popover } from './Popover'
import { cn } from '@/lib/cn'

/** Curated extended palette for the custom-color popover. Davyn-styled,
 *  no generic browser color dialog. */
const PALETTE = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e',
  '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1',
  '#7c6cf6', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e',
  '#64748b', '#475569', '#0f766e', '#b91c1c', '#1d4ed8', '#9333ea',
]

function isHex(v: string) {
  return /^#[0-9a-fA-F]{6}$/.test(v)
}

export function ColorPopover({
  value,
  onChange,
  trigger,
}: {
  value: string
  onChange: (hex: string) => void
  trigger: React.ReactNode
}) {
  const [open, setOpen] = useState(false)
  const [draft, setDraft] = useState(value || '#7c6cf6')

  return (
    <Popover open={open} onOpenChange={setOpen} align="start" portal trigger={trigger}>
      <div className="w-60 space-y-3">
        <div className="grid grid-cols-6 gap-1.5">
          {PALETTE.map((hex) => (
            <button
              key={hex}
              type="button"
              title={hex}
              onClick={() => { onChange(hex); setDraft(hex) }}
              style={{ background: hex }}
              className={cn(
                'relative size-7 rounded-lg transition hover:scale-110',
                value.toLowerCase() === hex.toLowerCase()
                  ? 'ring-2 ring-white/70 ring-offset-1 ring-offset-transparent'
                  : 'opacity-85 hover:opacity-100',
              )}
            >
              {value.toLowerCase() === hex.toLowerCase() && (
                <span className="absolute inset-0 grid place-items-center">
                  <Check className="size-3.5 text-white drop-shadow" />
                </span>
              )}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2 border-t border-foreground/10 pt-3">
          <span
            className="size-8 shrink-0 rounded-lg ring-1 ring-inset ring-foreground/15"
            style={{ background: isHex(draft) ? draft : 'transparent' }}
          />
          <div className="relative flex-1">
            <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-muted">#</span>
            <input
              value={draft.replace(/^#/, '')}
              onChange={(e) => {
                const next = '#' + e.target.value.replace(/[^0-9a-fA-F]/g, '').slice(0, 6)
                setDraft(next)
                if (isHex(next)) onChange(next)
              }}
              placeholder="7c6cf6"
              maxLength={6}
              className="w-full rounded-lg bg-foreground/5 py-1.5 pl-6 pr-2.5 font-mono text-sm uppercase ring-1 ring-inset ring-foreground/10 focus:outline-none focus:ring-2 focus:ring-accent/60"
            />
          </div>
        </div>
      </div>
    </Popover>
  )
}
