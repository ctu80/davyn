import { cn } from '@/lib/cn'

type Status = 'ok' | 'warn' | 'error' | 'idle'

const map: Record<Status, { dot: string; text: string; tint: string; label: string }> = {
  ok: { dot: 'bg-success', text: 'text-success', tint: 'bg-success/10 ring-success/25', label: 'Healthy' },
  warn: { dot: 'bg-warning', text: 'text-warning', tint: 'bg-warning/10 ring-warning/25', label: 'Warning' },
  error: { dot: 'bg-danger', text: 'text-danger', tint: 'bg-danger/10 ring-danger/25', label: 'Error' },
  idle: { dot: 'bg-muted', text: 'text-muted', tint: 'bg-foreground/5 ring-foreground/10', label: 'Idle' },
}

export function StatusPill({
  status,
  label,
  pulse = true,
  className,
}: {
  status: Status
  label?: string
  pulse?: boolean
  className?: string
}) {
  const s = map[status]
  return (
    <span
      className={cn(
        'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset',
        s.tint,
        s.text,
        className,
      )}
    >
      <span className="relative flex size-2">
        {pulse && status === 'ok' && (
          <span className={cn('absolute inline-flex size-full animate-ping rounded-full opacity-60', s.dot)} />
        )}
        <span className={cn('relative inline-flex size-2 rounded-full', s.dot)} />
      </span>
      {label ?? s.label}
    </span>
  )
}
