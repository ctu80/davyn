import { forwardRef } from 'react'
import { cn } from '@/lib/cn'

const base =
  'w-full rounded-xl bg-foreground/5 px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted/70 ' +
  'ring-1 ring-inset ring-foreground/10 transition focus:outline-none focus:ring-2 focus:ring-accent/60 ' +
  'disabled:opacity-50'

export const Input = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, ...props }, ref) => (
    <input ref={ref} className={cn(base, className)} {...props} />
  ),
)
Input.displayName = 'Input'

export const Textarea = forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(
  ({ className, ...props }, ref) => (
    <textarea ref={ref} className={cn(base, 'min-h-24 resize-y', className)} {...props} />
  ),
)
Textarea.displayName = 'Textarea'

export function Field({
  label,
  hint,
  children,
  className,
}: {
  label: string
  hint?: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <label className={cn('block space-y-1.5', className)}>
      <span className="text-xs font-medium text-muted-strong">{label}</span>
      {children}
      {hint && <span className="block text-xs text-muted">{hint}</span>}
    </label>
  )
}

export const inputClass = base
