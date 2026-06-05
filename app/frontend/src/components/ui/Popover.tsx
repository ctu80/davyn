import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { AnimatePresence, motion } from 'motion/react'
import { cn } from '@/lib/cn'

interface PanelPos { top?: number; bottom?: number; left?: number; right?: number }

/**
 * Lightweight popover — no external dependency. Anchors a floating panel to a
 * trigger, closes on outside-click / Escape.
 *
 * By default the panel is absolutely positioned next to the trigger. Pass
 * `portal` to instead render it into <body> with fixed coordinates — required
 * when the popover lives inside a scroll/overflow container (e.g. a tall modal)
 * so the panel isn't clipped. Portal mode also flips above the trigger when
 * there isn't enough room below.
 */
export function Popover({
  open,
  onOpenChange,
  trigger,
  children,
  align = 'start',
  panelClassName,
  portal = false,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  trigger: ReactNode
  children: ReactNode
  align?: 'start' | 'end'
  panelClassName?: string
  portal?: boolean
}) {
  const wrapRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const [pos, setPos] = useState<PanelPos>({})

  useEffect(() => {
    if (!open) return
    function onDown(e: MouseEvent) {
      const t = e.target as Node
      if (wrapRef.current?.contains(t)) return
      if (panelRef.current?.contains(t)) return
      onOpenChange(false)
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onOpenChange(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open, onOpenChange])

  // When the panel is portaled to <body> but the trigger lives inside a modal
  // (Radix Dialog), the dialog's focus trap steals focus back the instant a field
  // in the panel is focused, so text inputs can't be typed into. The trap reacts to
  // two document-level (bubble-phase) events: `focusin` whose target is our field,
  // and `focusout` on the dialog element whose relatedTarget is our field (this one
  // bubbles through the dialog, not the panel, so a panel-level listener misses it).
  // Intercept both in the capture phase — before the trap's handler — and stop them
  // when they concern our panel, so the trap never fires for our own fields.
  useEffect(() => {
    if (!portal || !open) return
    function inPanel(n: EventTarget | null): boolean {
      return n instanceof Node && !!panelRef.current?.contains(n)
    }
    function onFocusIn(e: FocusEvent) {
      if (inPanel(e.target)) e.stopImmediatePropagation()
    }
    function onFocusOut(e: FocusEvent) {
      if (inPanel(e.relatedTarget)) e.stopImmediatePropagation()
    }
    document.addEventListener('focusin', onFocusIn, true)
    document.addEventListener('focusout', onFocusOut, true)
    return () => {
      document.removeEventListener('focusin', onFocusIn, true)
      document.removeEventListener('focusout', onFocusOut, true)
    }
  }, [portal, open])

  // Compute fixed coordinates for portal mode, keeping them fresh on scroll/resize.
  useLayoutEffect(() => {
    if (!portal || !open) return
    function place() {
      const trig = wrapRef.current
      if (!trig) return
      const r = trig.getBoundingClientRect()
      const gap = 8
      const panelH = panelRef.current?.offsetHeight ?? 320
      const panelW = panelRef.current?.offsetWidth ?? 288
      const flipUp = r.bottom + gap + panelH > window.innerHeight && r.top > panelH + gap
      const next: PanelPos = flipUp
        ? { bottom: window.innerHeight - r.top + gap }
        : { top: r.bottom + gap }
      // Anchor horizontally to the requested edge, then clamp so the panel always
      // stays fully inside the viewport (otherwise a trigger near the right edge
      // pushes the panel off-screen — e.g. the calendar's jump-to-date field).
      const maxLeft = Math.max(gap, window.innerWidth - panelW - gap)
      if (align === 'end') {
        const right = window.innerWidth - r.right
        next.right = Math.min(Math.max(gap, right), maxLeft)
      } else {
        next.left = Math.min(Math.max(gap, r.left), maxLeft)
      }
      setPos(next)
    }
    place()
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
    return () => {
      window.removeEventListener('scroll', place, true)
      window.removeEventListener('resize', place)
    }
  }, [portal, open, align])

  const panel = (
    <AnimatePresence>
      {open && (
        <motion.div
          ref={panelRef}
          data-davyn-popover=""
          initial={{ opacity: 0, scale: 0.97, y: -4 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.97, y: -4 }}
          transition={{ type: 'spring', stiffness: 380, damping: 30 }}
          // In portal mode the panel lives in <body>, outside the modal. Radix's
          // modal layer disables pointer-events on everything outside the dialog,
          // so re-enable them explicitly or the panel becomes unclickable.
          style={portal ? { position: 'fixed', pointerEvents: 'auto', ...pos } : undefined}
          className={cn(
            'glass-strong z-50 rounded-2xl p-3 shadow-soft ring-1 ring-inset ring-foreground/10',
            !portal && 'absolute top-full mt-2',
            !portal && (align === 'end' ? 'right-0' : 'left-0'),
            panelClassName,
          )}
        >
          {children}
        </motion.div>
      )}
    </AnimatePresence>
  )

  return (
    <div ref={wrapRef} className="relative">
      <div onClick={() => onOpenChange(!open)}>{trigger}</div>
      {portal ? (typeof document !== 'undefined' ? createPortal(panel, document.body) : null) : panel}
    </div>
  )
}
