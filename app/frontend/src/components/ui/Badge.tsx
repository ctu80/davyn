import { cn } from '@/lib/cn'

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info'

const tones: Record<Tone, string> = {
  neutral: 'bg-foreground/8 text-muted-strong ring-1 ring-inset ring-foreground/10',
  accent: 'bg-accent/15 text-accent ring-1 ring-inset ring-accent/30',
  success: 'bg-success/15 text-success ring-1 ring-inset ring-success/30',
  warning: 'bg-warning/15 text-warning ring-1 ring-inset ring-warning/30',
  danger: 'bg-danger/15 text-danger ring-1 ring-inset ring-danger/30',
  info: 'bg-info/15 text-info ring-1 ring-inset ring-info/30',
}

export function Badge({
  tone = 'neutral',
  className,
  children,
}: {
  tone?: Tone
  className?: string
  children: React.ReactNode
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[0.7rem] font-medium',
        tones[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}
