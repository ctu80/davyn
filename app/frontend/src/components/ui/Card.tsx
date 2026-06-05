import { forwardRef } from 'react'
import { motion, type HTMLMotionProps } from 'motion/react'
import { cn } from '@/lib/cn'

export interface CardProps extends Omit<HTMLMotionProps<'div'>, 'children'> {
  hover?: boolean
  glow?: boolean
  children?: React.ReactNode
}

export const Card = forwardRef<HTMLDivElement, CardProps>(
  ({ className, hover = false, glow = false, children, ...props }, ref) => (
    <motion.div
      ref={ref}
      whileHover={hover ? { y: -4 } : undefined}
      transition={{ type: 'spring', stiffness: 300, damping: 26 }}
      className={cn(
        'glass sheen-top relative overflow-hidden rounded-2xl shadow-soft transition-[box-shadow,border-color] duration-300',
        hover && 'cursor-default hover:border-accent/25 hover:shadow-glow',
        glow && 'shadow-glow',
        className,
      )}
      {...props}
    >
      {children}
    </motion.div>
  ),
)
Card.displayName = 'Card'

export function CardHeader({ className, ...p }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex items-start justify-between gap-3 p-5 pb-0', className)} {...p} />
}
export function CardTitle({ className, ...p }: React.HTMLAttributes<HTMLHeadingElement>) {
  return <h3 className={cn('text-sm font-semibold tracking-tight text-foreground', className)} {...p} />
}
export function CardDescription({ className, ...p }: React.HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn('text-xs text-muted', className)} {...p} />
}
export function CardContent({ className, ...p }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('p-5', className)} {...p} />
}
