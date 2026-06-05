import { Navigate } from 'react-router-dom'
import { useMe } from '@/api/user'
import { Spinner } from '@/components/ui/Spinner'

export function RequireAdmin({ children }: { children: React.ReactNode }) {
  const { data: me, isLoading } = useMe()
  if (isLoading)
    return (
      <div className="grid place-items-center py-24 text-muted">
        <Spinner className="size-6" />
      </div>
    )
  if (me?.role !== 'admin') return <Navigate to="/" replace />
  return <>{children}</>
}
