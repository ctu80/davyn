import { forwardRef } from 'react'
import { motion, type HTMLMotionProps } from 'motion/react'
import { cn } from '@/lib/cn'
import { Spinner } from './Spinner'

type Variant = 'primary' | 'secondary' | 'subtle' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg' | 'icon'

const variants: Record<Variant, string> = {
  primary:
    'text-accent-foreground gradient-accent ring-1 ring-inset ring-white/20 shadow-[0_8px_24px_-8px_rgb(var(--accent)/0.6)] hover:brightness-[1.08] hover:shadow-[0_12px_32px_-8px_rgb(var(--accent)/0.7)]',
  secondary: 'glass text-foreground hover:border-accent/25 hover:bg-foreground/[0.07]',
  subtle: 'bg-foreground/5 text-muted-strong ring-1 ring-inset ring-foreground/10 hover:text-foreground hover:bg-foreground/10',
  ghost: 'text-muted hover:text-foreground hover:bg-foreground/5',
  danger: 'bg-danger/90 text-white ring-1 ring-inset ring-white/15 hover:bg-danger',
}

const sizes: Record<Size, string> = {
  sm: 'h-8 px-3 text-xs gap-1.5 rounded-lg',
  md: 'h-10 px-4 text-sm gap-2 rounded-xl',
  lg: 'h-12 px-6 text-[0.95rem] gap-2 rounded-xl',
  icon: 'h-10 w-10 rounded-xl',
}

export interface ButtonProps extends Omit<HTMLMotionProps<'button'>, 'children'> {
  variant?: Variant
  size?: Size
  loading?: boolean
  children?: React.ReactNode
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = 'primary', size = 'md', loading, disabled, children, ...props }, ref) => (
    <motion.button
      ref={ref}
      whileTap={{ scale: 0.97 }}
      whileHover={{ y: -1 }}
      transition={{ type: 'spring', stiffness: 400, damping: 25 }}
      disabled={disabled || loading}
      className={cn(
        'relative inline-flex items-center justify-center overflow-hidden font-medium select-none transition-colors',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 focus-visible:ring-offset-0',
        'disabled:opacity-50 disabled:pointer-events-none',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {loading && <Spinner className="mr-2" />}
      {children}
    </motion.button>
  ),
)
Button.displayName = 'Button'
