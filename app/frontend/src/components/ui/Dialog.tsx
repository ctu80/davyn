import * as RDialog from '@radix-ui/react-dialog'
import { AnimatePresence, motion } from 'motion/react'
import { X } from 'lucide-react'

const SIZE_CLASS = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-lg',
  xl: 'max-w-xl',
  '2xl': 'max-w-2xl',
} as const

export function Modal({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
  size = 'md',
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  title: string
  description?: string
  children?: React.ReactNode
  footer?: React.ReactNode
  size?: keyof typeof SIZE_CLASS
}) {
  return (
    <RDialog.Root open={open} onOpenChange={onOpenChange}>
      <AnimatePresence>
        {open && (
          <RDialog.Portal forceMount>
            <RDialog.Overlay asChild forceMount>
              <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-50 bg-black/55 backdrop-blur-sm"
              />
            </RDialog.Overlay>
            <RDialog.Content
              asChild
              forceMount
              aria-describedby={undefined}
              // Popovers (date pickers, etc.) portal into <body>, i.e. outside the
              // dialog. Don't treat interacting with them as an outside-click.
              onInteractOutside={(e) => {
                const t = (e.detail as { originalEvent?: Event })?.originalEvent?.target as HTMLElement | null
                if (t?.closest('[data-davyn-popover]')) e.preventDefault()
              }}
            >
              <motion.div
                initial={{ opacity: 0, scale: 0.96, y: 12 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.97, y: 8 }}
                transition={{ type: 'spring', stiffness: 320, damping: 28 }}
                className={`glass-strong fixed left-1/2 top-1/2 z-50 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] ${SIZE_CLASS[size]} -translate-x-1/2 -translate-y-1/2 flex-col rounded-2xl p-6 shadow-soft ring-1 ring-inset ring-foreground/10`}
              >
                <div className="mb-4 flex shrink-0 items-start justify-between gap-4">
                  <div>
                    <RDialog.Title className="text-base font-semibold">{title}</RDialog.Title>
                    {description && (
                      <RDialog.Description className="mt-1 text-sm text-muted">{description}</RDialog.Description>
                    )}
                  </div>
                  <RDialog.Close className="grid size-8 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-foreground/5 hover:text-foreground">
                    <X className="size-4" />
                  </RDialog.Close>
                </div>
                <div className="-mx-1 min-h-0 flex-1 overflow-y-auto px-1">{children}</div>
                {footer && <div className="mt-6 flex shrink-0 justify-end gap-2">{footer}</div>}
              </motion.div>
            </RDialog.Content>
          </RDialog.Portal>
        )}
      </AnimatePresence>
    </RDialog.Root>
  )
}
