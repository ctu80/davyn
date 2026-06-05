import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import * as RToast from '@radix-ui/react-toast'
import { AnimatePresence, motion } from 'motion/react'
import { CheckCircle2, AlertTriangle, XCircle, Info, X } from 'lucide-react'
import { cn } from '@/lib/cn'
import { useT } from '@/i18n/LocaleContext'

type ToastKind = 'success' | 'error' | 'warning' | 'info'
interface ToastItem {
  id: number
  kind: ToastKind
  title: string
  description?: string
}

interface ToastApi {
  toast: (t: Omit<ToastItem, 'id'>) => void
  success: (title: string, description?: string) => void
  error: (title: string, description?: string) => void
}

const Ctx = createContext<ToastApi | null>(null)

const icons = { success: CheckCircle2, error: XCircle, warning: AlertTriangle, info: Info }
const tones = {
  success: 'text-success',
  error: 'text-danger',
  warning: 'text-warning',
  info: 'text-info',
}

let counter = 0

export function ToastProvider({ children }: { children: ReactNode }) {
  const tr = useT()
  const [items, setItems] = useState<ToastItem[]>([])

  const remove = (id: number) => setItems((xs) => xs.filter((x) => x.id !== id))
  const toast = useCallback((t: Omit<ToastItem, 'id'>) => {
    const id = ++counter
    setItems((xs) => [...xs, { ...t, id }])
  }, [])

  const api: ToastApi = {
    toast,
    success: (title, description) => toast({ kind: 'success', title, description }),
    error: (title, description) => toast({ kind: 'error', title, description }),
  }

  return (
    <Ctx.Provider value={api}>
      <RToast.Provider swipeDirection="right" duration={4500}>
        {children}
        <AnimatePresence>
          {items.map((item) => {
            const Icon = icons[item.kind]
            return (
              <RToast.Root key={item.id} asChild onOpenChange={(o) => !o && remove(item.id)} forceMount>
                <motion.div
                  layout
                  initial={{ opacity: 0, x: 40, scale: 0.95 }}
                  animate={{ opacity: 1, x: 0, scale: 1 }}
                  exit={{ opacity: 0, x: 40, scale: 0.9 }}
                  transition={{ type: 'spring', stiffness: 350, damping: 30 }}
                  className="glass-strong pointer-events-auto flex w-80 items-start gap-3 rounded-2xl p-4 shadow-soft ring-1 ring-inset ring-foreground/10"
                >
                  <Icon className={cn('mt-0.5 size-5 shrink-0', tones[item.kind])} />
                  <div className="min-w-0 flex-1">
                    <RToast.Title className="text-sm font-medium">{item.title}</RToast.Title>
                    {item.description && (
                      <RToast.Description className="mt-0.5 text-xs text-muted">
                        {item.description}
                      </RToast.Description>
                    )}
                  </div>
                  <RToast.Close className="text-muted transition hover:text-foreground" aria-label={tr('Dismiss')}>
                    <X className="size-4" />
                  </RToast.Close>
                </motion.div>
              </RToast.Root>
            )
          })}
        </AnimatePresence>
        <RToast.Viewport className="fixed bottom-4 right-4 z-[100] flex w-80 flex-col gap-3 outline-none" />
      </RToast.Provider>
    </Ctx.Provider>
  )
}

export function useToast() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useToast must be used within ToastProvider')
  return ctx
}
