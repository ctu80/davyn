import { Sparkles } from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { EmptyState } from '@/components/ui/EmptyState'
import { useT } from '@/i18n/LocaleContext'

export default function Placeholder({ title }: { title: string }) {
  const t = useT()
  return (
    <div className="space-y-6">
      <PageHeader title={title} subtitle={t('This section is being prepared.')} icon={Sparkles} />
      <EmptyState icon={Sparkles} title={t('Coming together')} description={t('This view is part of the new Davyn experience and lands shortly.')} />
    </div>
  )
}
