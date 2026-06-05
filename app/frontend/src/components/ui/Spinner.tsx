import { cn } from '@/lib/cn'
import { useT } from '@/i18n/LocaleContext'

export function Spinner({ className }: { className?: string }) {
  const t = useT()
  return (
    <span
      className={cn(
        'inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent',
        className,
      )}
      role="status"
      aria-label={t('Loading')}
    />
  )
}
