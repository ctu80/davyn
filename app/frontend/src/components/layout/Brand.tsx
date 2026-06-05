import { motion } from 'motion/react'
import { useT } from '@/i18n/LocaleContext'
import { LogoMark } from './LogoMark'

export function Brand({ name = 'Davyn' }: { name?: string }) {
  const t = useT()
  return (
    <div className="flex items-center gap-2.5">
      <motion.div
        initial={{ rotate: -8, scale: 0.9 }}
        animate={{ rotate: 0, scale: 1 }}
        transition={{ type: 'spring', stiffness: 260, damping: 18 }}
        className="relative size-9 overflow-hidden rounded-xl shadow-glow"
      >
        <LogoMark alt={name} className="size-full object-cover" />
        <span className="absolute inset-0 rounded-xl ring-1 ring-inset ring-white/15" />
      </motion.div>
      <div className="leading-tight">
        <div className="text-[0.95rem] font-semibold tracking-tight">{name}</div>
        <div className="text-[0.68rem] text-muted">{t('Private Sync Hub')}</div>
      </div>
    </div>
  )
}
