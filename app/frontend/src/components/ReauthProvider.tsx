import { createContext, useContext, useRef, useState, type ReactNode } from 'react'
import { ShieldCheck } from 'lucide-react'
import { ApiError, reauth } from '@/lib/api'
import { Modal } from '@/components/ui/Dialog'
import { Button } from '@/components/ui/Button'
import { Input, Field } from '@/components/ui/Input'
import { useT } from '@/i18n/LocaleContext'

interface ReauthApi {
  /** Run an action, transparently handling a 'Reauthentication required' challenge. */
  run: <T>(fn: () => Promise<T>) => Promise<T>
}

const Ctx = createContext<ReauthApi | null>(null)

export function ReauthProvider({ children }: { children: ReactNode }) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [pw, setPw] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const resolver = useRef<{ resolve: (s: string) => void; reject: (e: unknown) => void } | null>(null)

  function promptPassword(): Promise<string> {
    return new Promise((resolve, reject) => {
      resolver.current = { resolve, reject }
      setPw('')
      setErr(null)
      setOpen(true)
    })
  }

  async function run<T>(fn: () => Promise<T>): Promise<T> {
    try {
      return await fn()
    } catch (e) {
      if (e instanceof ApiError && e.status === 403 && /reauth/i.test(e.message)) {
        const password = await promptPassword()
        await reauth(password)
        return await fn()
      }
      throw e
    }
  }

  async function submit() {
    setBusy(true)
    setErr(null)
    try {
      await reauth(pw)
      setOpen(false)
      resolver.current?.resolve(pw)
      resolver.current = null
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : t('Authentication failed'))
    } finally {
      setBusy(false)
    }
  }

  function cancel(o: boolean) {
    if (o) return
    setOpen(false)
    resolver.current?.reject(new ApiError(403, 'Reauthentication cancelled'))
    resolver.current = null
  }

  return (
    <Ctx.Provider value={{ run }}>
      {children}
      <Modal
        open={open}
        onOpenChange={cancel}
        title={t("Confirm it's you")}
        description={t('This sensitive action needs your admin password.')}
        footer={
          <>
            <Button variant="ghost" onClick={() => cancel(false)}>{t('Cancel')}</Button>
            <Button onClick={submit} loading={busy}><ShieldCheck className="size-4" /> {t('Confirm')}</Button>
          </>
        }
      >
        <Field label={t('Admin password')}>
          <Input
            type="password"
            autoFocus
            value={pw}
            onChange={(e) => setPw(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && submit()}
          />
        </Field>
        {err && <p className="mt-2 text-sm text-danger">{err}</p>}
      </Modal>
    </Ctx.Provider>
  )
}

export function useReauth() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useReauth must be used within ReauthProvider')
  return ctx
}
