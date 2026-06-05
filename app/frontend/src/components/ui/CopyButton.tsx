import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { cn } from '@/lib/cn'
import { copyText } from '@/lib/clipboard'
import { useToast } from '@/components/ui/Toast'
import { useT } from '@/i18n/LocaleContext'

export function CopyButton({ value, className, label }: { value: string; className?: string; label?: string }) {
  const [copied, setCopied] = useState(false)
  const toast = useToast()
  const t = useT()
  return (
    <button
      type="button"
      onClick={async () => {
        const ok = await copyText(value)
        if (ok) {
          setCopied(true)
          toast.success(t('Copied to clipboard'))
          setTimeout(() => setCopied(false), 1500)
        } else {
          toast.error(t('Could not copy'), t('Select the text and copy it manually.'))
        }
      }}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-muted transition hover:bg-foreground/10 hover:text-foreground',
        className,
      )}
    >
      {copied ? <Check className="size-3.5 text-success" /> : <Copy className="size-3.5" />}
      {label ?? (copied ? t('Copied') : t('Copy'))}
    </button>
  )
}
