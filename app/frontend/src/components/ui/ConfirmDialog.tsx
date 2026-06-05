import { Modal } from './Dialog'
import { Button } from './Button'
import { useT } from '@/i18n/LocaleContext'

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  danger = false,
  loading = false,
  onConfirm,
}: {
  open: boolean
  onOpenChange: (o: boolean) => void
  title: string
  description?: string
  confirmLabel?: string
  danger?: boolean
  loading?: boolean
  onConfirm: () => void
}) {
  const t = useT()
  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      description={description}
      footer={
        <>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button variant={danger ? 'danger' : 'primary'} loading={loading} onClick={onConfirm}>
            {confirmLabel ?? t('Confirm')}
          </Button>
        </>
      }
    />
  )
}
