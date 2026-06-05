import { cn } from '@/lib/cn'

export function Skeleton({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'relative overflow-hidden rounded-lg bg-foreground/5',
        'after:absolute after:inset-0 after:-translate-x-full after:animate-[shimmer_1.6s_infinite]',
        'after:bg-gradient-to-r after:from-transparent after:via-foreground/10 after:to-transparent',
        className,
      )}
    />
  )
}
