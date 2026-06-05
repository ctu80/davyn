import { useEffect, useMemo, useState } from 'react'
import {
  ShieldCheck, Lock, Globe, Upload, Download, RefreshCw, Trash2, Plus,
  AlertTriangle, KeyRound, RotateCcw,
} from 'lucide-react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardContent } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Field, Textarea } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Skeleton } from '@/components/ui/Skeleton'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { TagInput } from '@/components/ui/TagInput'
import { CopyButton } from '@/components/ui/CopyButton'
import { useToast } from '@/components/ui/Toast'
import { useReauth } from '@/components/ReauthProvider'
import {
  useSettings, useSaveSettings, useTlsStatus,
  useGenerateCert, useUploadCert, useValidateCert, useRemoveCert, useAckTlsRestart, useSetHttpMode,
} from '@/api/admin'
import type { CertStatus, TlsMode } from '@/api/types'
import { ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useT, useLocale } from '@/i18n/LocaleContext'

const certTone: Record<CertStatus, 'success' | 'warning' | 'danger' | 'neutral'> = {
  valid: 'success',
  missing: 'neutral',
  not_yet_valid: 'warning',
  expired: 'danger',
  invalid: 'danger',
  key_mismatch: 'danger',
}

export default function Security() {
  const t = useT()
  const { locale } = useLocale()
  const toast = useToast()
  const reauth = useReauth()

  const { data: settings } = useSettings()
  const saveSettings = useSaveSettings()
  const { data: tls, isLoading } = useTlsStatus()

  const generate = useGenerateCert()
  const upload = useUploadCert()
  const validate = useValidateCert()
  const remove = useRemoveCert()
  const ack = useAckTlsRestart()
  const setHttpMode = useSetHttpMode()

  const [baseUrl, setBaseUrl] = useState('')
  const [genOpen, setGenOpen] = useState(false)
  const [uploadOpen, setUploadOpen] = useState(false)
  const [removeOpen, setRemoveOpen] = useState(false)
  const [forceOpen, setForceOpen] = useState(false)

  useEffect(() => {
    if (settings) setBaseUrl(settings.public_base_url ?? '')
  }, [settings])

  const host = tls?.host || window.location.hostname
  const httpUrl = tls ? `http://${host}:${tls.http_port}` : ''
  const httpsUrl = tls ? `https://${host}:${tls.https_port}` : ''
  const cert = tls?.certificate

  const modeLabel: Record<TlsMode, string> = {
    http: t('HTTP only'),
    selfsigned: t('Self-signed'),
    custom: t('Custom certificate'),
  }
  const certStatusLabel: Record<CertStatus, string> = {
    valid: t('Valid'),
    missing: t('Missing'),
    invalid: t('Invalid'),
    expired: t('Expired'),
    not_yet_valid: t('Not yet valid'),
    key_mismatch: t('Key mismatch'),
  }

  async function saveBaseUrl() {
    try {
      await saveSettings.mutateAsync({ public_base_url: baseUrl.trim() })
      toast.success(t('Settings saved'))
    } catch (e) {
      toast.error(t('Could not save settings'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function doValidate() {
    try {
      await validate.mutateAsync()
      toast.success(t('Certificate re-checked'))
    } catch (e) {
      toast.error(t('Validation failed'), e instanceof ApiError ? e.message : undefined)
    }
  }

  async function dismissRestart() {
    try { await ack.mutateAsync() } catch { /* ignore */ }
  }

  async function applyHttpMode(mode: 'enabled' | 'redirect') {
    try {
      await reauth.run(() => setHttpMode.mutateAsync({ mode }))
      toast.success(mode === 'redirect' ? t('Plain HTTP disabled') : t('Plain HTTP re-enabled'))
      setForceOpen(false)
    } catch (e) {
      if (e instanceof ApiError && /cancelled/i.test(e.message)) { setForceOpen(false); return }
      toast.error(t('Could not change HTTP mode'), e instanceof ApiError ? e.message : undefined)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('Security')} subtitle={t('Public URL and internal HTTPS')} icon={ShieldCheck} />

      {/* Section 1: Public / Base URL */}
      <Card className="max-w-2xl">
        <CardContent className="space-y-4">
          <div className="flex items-center gap-2 text-sm font-medium">
            <Globe className="size-4 text-accent" /> {t('Public / Base URL')}
          </div>
          <Field
            label={t('Public URL')}
            hint={t('Used for DAV setup URLs, public links and QR codes. Behind a reverse proxy (e.g. Traefik, Caddy, Nginx Proxy Manager) set this to your external HTTPS URL. Leave empty to derive it from the browser.')}
          >
            <Input
              value={baseUrl}
              onChange={(e) => setBaseUrl(e.target.value)}
              placeholder="https://davyn.example.com"
              spellCheck={false}
            />
          </Field>
          <Button onClick={saveBaseUrl} loading={saveSettings.isPending}>{t('Save')}</Button>
        </CardContent>
      </Card>

      {/* Section 2: Internal HTTPS */}
      {isLoading || !tls ? (
        <Skeleton className="h-64 w-full max-w-2xl" />
      ) : (
        <Card className="max-w-2xl">
          <CardContent className="space-y-5">
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2 text-sm font-medium">
                <Lock className="size-4 text-accent" /> {t('Internal HTTPS')}
              </div>
              <Badge tone={tls.mode === 'http' ? 'neutral' : 'accent'}>{modeLabel[tls.mode]}</Badge>
            </div>

            {tls.restart_required && (
              <div className="flex items-start gap-3 rounded-xl bg-warning/10 p-3 text-sm ring-1 ring-inset ring-warning/30">
                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-warning">{t('Restart required')}</p>
                  <p className="mt-0.5 text-xs text-muted">
                    {t('Certificate saved. Restart the Caddy container to activate the change:')}
                  </p>
                  <div className="mt-1.5 space-y-2">
                    <CommandLine
                      hint={t('In the Davyn project directory (where docker-compose.yml lives):')}
                      command="docker compose restart caddy"
                    />
                    <CommandLine
                      hint={t('Or from anywhere, by container name:')}
                      command="docker restart davyn-caddy"
                    />
                  </div>
                </div>
                <Button variant="ghost" size="sm" onClick={dismissRestart} loading={ack.isPending}>
                  <RotateCcw className="size-3.5" /> {t("I've restarted")}
                </Button>
              </div>
            )}

            {/* Endpoints */}
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="rounded-xl bg-foreground/5 p-3 ring-1 ring-inset ring-foreground/10">
                <p className="text-xs text-muted">{t('HTTP endpoint')}</p>
                <p className="mt-0.5 font-mono text-sm">{httpUrl}</p>
              </div>
              <div className="rounded-xl bg-foreground/5 p-3 ring-1 ring-inset ring-foreground/10">
                <p className="text-xs text-muted">{t('HTTPS endpoint')}</p>
                <p className="mt-0.5 font-mono text-sm">{cert?.status === 'valid' ? httpsUrl : '—'}</p>
              </div>
            </div>

            {/* Certificate status card */}
            <div className="rounded-xl border border-foreground/10 p-4">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium">{t('Certificate')}</p>
                <Badge tone={certTone[cert?.status ?? 'missing']}>
                  {certStatusLabel[cert?.status ?? 'missing']}
                </Badge>
              </div>
              {cert?.has_certificate ? (
                <dl className="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                  <Row label={t('Subject (CN)')} value={cert.subject_cn} />
                  <Row label={t('Issuer')} value={cert.issuer} />
                  <Row label={t('Valid from')} value={cert.valid_from ? dateTime(cert.valid_from, locale) : null} />
                  <Row label={t('Valid until')} value={cert.valid_until ? dateTime(cert.valid_until, locale) : null} />
                  <Row
                    label={t('Days remaining')}
                    value={cert.days_remaining !== null ? String(cert.days_remaining) : null}
                  />
                  <Row label={t('Self-signed')} value={cert.self_signed === null ? null : cert.self_signed ? t('Yes') : t('No')} />
                  <div className="sm:col-span-2">
                    <dt className="text-xs text-muted">{t('Subject Alternative Names')}</dt>
                    <dd className="mt-1 flex flex-wrap gap-1.5">
                      {cert.sans.length ? cert.sans.map((s) => <Badge key={s}>{s}</Badge>) : <span className="text-muted">—</span>}
                    </dd>
                  </div>
                  <div className="sm:col-span-2">
                    <dt className="text-xs text-muted">{t('SHA-256 fingerprint')}</dt>
                    <dd className="mt-1 flex items-center gap-2">
                      <code className="block min-w-0 flex-1 truncate rounded-lg bg-foreground/5 px-2 py-1 font-mono text-xs">
                        {cert.fingerprint_sha256 ?? '—'}
                      </code>
                      {cert.fingerprint_sha256 && <CopyButton value={cert.fingerprint_sha256} />}
                    </dd>
                  </div>
                </dl>
              ) : (
                <p className="mt-2 text-sm text-muted">
                  {t('No certificate installed. Davyn is served over HTTP only.')}
                </p>
              )}
            </div>

            {/* Section 3: Actions */}
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => setGenOpen(true)}><Plus className="size-4" /> {t('Generate self-signed')}</Button>
              <Button variant="secondary" onClick={() => setUploadOpen(true)}><Upload className="size-4" /> {t('Upload certificate')}</Button>
              {cert?.has_certificate && (
                <a
                  href="/api/admin/tls/download"
                  className="inline-flex h-10 items-center gap-1.5 rounded-xl bg-foreground/5 px-3.5 text-sm text-muted-strong ring-1 ring-inset ring-foreground/10 transition hover:bg-foreground/10 hover:text-foreground"
                >
                  <Download className="size-4" /> {t('Download certificate')}
                </a>
              )}
              <Button variant="ghost" onClick={doValidate} loading={validate.isPending}><RefreshCw className="size-4" /> {t('Validate')}</Button>
              {cert?.has_certificate && (
                <Button variant="ghost" onClick={() => setRemoveOpen(true)}><Trash2 className="size-4" /> {t('Remove')}</Button>
              )}
            </div>

            {/* Force HTTPS — disable plain HTTP (redirect :8080 → HTTPS) */}
            <div className="rounded-xl border border-foreground/10 p-4">
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <p className="flex items-center gap-2 text-sm font-medium">
                    {t('Force HTTPS')}
                    {tls.http_mode === 'redirect' && <Badge tone="success">{t('On')}</Badge>}
                  </p>
                  <p className="mt-0.5 text-xs text-muted">
                    {tls.http_mode === 'redirect'
                      ? t('Plain HTTP is redirected to HTTPS.')
                      : cert?.status === 'valid' && tls.mode !== 'http'
                        ? t('Redirect all plain HTTP to HTTPS. Requires a Caddy restart to apply.')
                        : t('Configure HTTPS first before disabling plain HTTP.')}
                  </p>
                </div>
                {tls.http_mode === 'redirect' ? (
                  <Button variant="ghost" size="sm" loading={setHttpMode.isPending} onClick={() => applyHttpMode('enabled')}>
                    {t('Re-enable HTTP')}
                  </Button>
                ) : (
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={!(cert?.status === 'valid' && tls.mode !== 'http')}
                    onClick={() => setForceOpen(true)}
                  >
                    {t('Disable HTTP')}
                  </Button>
                )}
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <GenerateModal
        open={genOpen}
        onClose={() => setGenOpen(false)}
        defaultHost={host}
        pending={generate.isPending}
        onSubmit={async (input) => {
          try {
            await reauth.run(() => generate.mutateAsync(input))
            toast.success(t('Self-signed certificate generated'), t('Restart the Caddy container to activate it.'))
            setGenOpen(false)
          } catch (e) {
            if (e instanceof ApiError && /cancelled/i.test(e.message)) return
            toast.error(t('Certificate generation failed'), e instanceof ApiError ? e.message : undefined)
          }
        }}
      />

      <UploadModal
        open={uploadOpen}
        onClose={() => setUploadOpen(false)}
        pending={upload.isPending}
        onSubmit={async (input) => {
          try {
            const res = await reauth.run(() => upload.mutateAsync(input))
            const warnings = res?.warnings ?? []
            if (warnings.length) {
              toast.success(t('Certificate uploaded'), t('Warnings: {list}', { list: warnings.join(', ') }))
            } else {
              toast.success(t('Certificate uploaded'), t('Restart the Caddy container to activate it.'))
            }
            setUploadOpen(false)
          } catch (e) {
            if (e instanceof ApiError && /cancelled/i.test(e.message)) return
            toast.error(t('Certificate upload failed'), e instanceof ApiError ? e.message : undefined)
          }
        }}
      />

      <ConfirmDialog
        open={removeOpen}
        onOpenChange={(o) => !o && setRemoveOpen(false)}
        title={t('Remove certificate?')}
        description={t('The certificate is backed up, then removed. After you restart Caddy, Davyn is served over HTTP only.')}
        confirmLabel={t('Remove')}
        danger
        loading={remove.isPending}
        onConfirm={async () => {
          try {
            await reauth.run(() => remove.mutateAsync())
            toast.success(t('Certificate removed'), t('Restart the Caddy container to apply.'))
          } catch (e) {
            if (e instanceof ApiError && /cancelled/i.test(e.message)) { setRemoveOpen(false); return }
            toast.error(t('Could not remove certificate'), e instanceof ApiError ? e.message : undefined)
          }
          setRemoveOpen(false)
        }}
      />

      <ConfirmDialog
        open={forceOpen}
        onOpenChange={(o) => !o && setForceOpen(false)}
        title={t('Disable plain HTTP?')}
        description={t('All HTTP requests to :8080 will be redirected to HTTPS, including http:// public-calendar subscriptions. You must restart the Caddy container to apply. If the certificate is later removed, plain HTTP automatically returns so you are never locked out.')}
        confirmLabel={t('Disable HTTP')}
        loading={setHttpMode.isPending}
        onConfirm={() => applyHttpMode('redirect')}
      />
    </div>
  )
}

function CommandLine({ hint, command }: { hint: string; command: string }) {
  return (
    <div>
      <p className="text-xs text-muted">{hint}</p>
      <div className="mt-1 flex items-center gap-2">
        <code className="block min-w-0 flex-1 truncate rounded-lg bg-foreground/5 px-2 py-1 font-mono text-xs">{command}</code>
        <CopyButton value={command} />
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="truncate font-mono text-sm">{value ?? '—'}</dd>
    </div>
  )
}

function GenerateModal({
  open, onClose, defaultHost, pending, onSubmit,
}: {
  open: boolean
  onClose: () => void
  defaultHost: string
  pending: boolean
  onSubmit: (input: { common_name: string; dns_sans: string[]; ip_sans: string[]; days: number; organization?: string }) => void
}) {
  const t = useT()
  const hostIsIp = useMemo(() => /^\d{1,3}(\.\d{1,3}){3}$/.test(defaultHost), [defaultHost])
  const [cn, setCn] = useState('')
  const [dns, setDns] = useState<string[]>([])
  const [ips, setIps] = useState<string[]>([])
  const [org, setOrg] = useState('Davyn Local')
  const [validity, setValidity] = useState('730')
  const [customDays, setCustomDays] = useState('730')

  // Seed sensible defaults from the configured host whenever the modal opens.
  useEffect(() => {
    if (!open) return
    setCn(hostIsIp ? '' : defaultHost)
    setDns(hostIsIp ? ['localhost'] : Array.from(new Set([defaultHost, 'localhost'])))
    setIps(hostIsIp ? [defaultHost] : [])
  }, [open, defaultHost, hostIsIp])

  const days = validity === 'custom' ? Math.max(1, Math.min(3650, parseInt(customDays || '0', 10) || 0)) : parseInt(validity, 10)

  return (
    <Modal
      open={open}
      onOpenChange={(o) => !o && onClose()}
      title={t('Generate self-signed certificate')}
      description={t('Davyn creates a private key and a self-signed certificate. Suitable for the internal hop behind a reverse proxy.')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('Cancel')}</Button>
          <Button
            loading={pending}
            onClick={() => onSubmit({ common_name: cn.trim(), dns_sans: dns, ip_sans: ips, days, organization: org.trim() || undefined })}
          >
            <KeyRound className="size-4" /> {t('Generate')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label={t('Common name (hostname)')} hint={t('Derived from your Public URL when set.')}>
          <Input value={cn} onChange={(e) => setCn(e.target.value)} placeholder="davyn.example.com" spellCheck={false} />
        </Field>
        <Field label={t('DNS names (SAN)')}>
          <TagInput value={dns} onChange={setDns} placeholder={t('Add DNS name…')} />
        </Field>
        <Field label={t('IP addresses (SAN)')}>
          <TagInput value={ips} onChange={setIps} placeholder={t('Add IP address…')} />
        </Field>
        <Field label={t('Validity')}>
          <Select
            value={validity}
            onValueChange={setValidity}
            options={[
              { value: '90', label: t('90 days') },
              { value: '365', label: t('1 year') },
              { value: '730', label: t('2 years') },
              { value: '1825', label: t('5 years') },
              { value: 'custom', label: t('Custom…') },
            ]}
          />
        </Field>
        {validity === 'custom' && (
          <Field label={t('Custom days (1–3650)')}>
            <Input type="number" min={1} max={3650} value={customDays} onChange={(e) => setCustomDays(e.target.value)} />
          </Field>
        )}
        <Field label={t('Organization (optional)')}>
          <Input value={org} onChange={(e) => setOrg(e.target.value)} maxLength={64} />
        </Field>
      </div>
    </Modal>
  )
}

function UploadModal({
  open, onClose, pending, onSubmit,
}: {
  open: boolean
  onClose: () => void
  pending: boolean
  onSubmit: (input: { certificate: string; private_key: string; chain?: string }) => void
}) {
  const t = useT()
  const toast = useToast()
  const [certPem, setCertPem] = useState('')
  const [keyPem, setKeyPem] = useState('')
  const [chainPem, setChainPem] = useState('')

  useEffect(() => {
    if (open) { setCertPem(''); setKeyPem(''); setChainPem('') }
  }, [open])

  function readInto(setter: (v: string) => void) {
    return (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0]
      if (!file) return
      file.text().then(setter).catch(() => toast.error(t('Could not read file')))
      e.target.value = ''
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={(o) => !o && onClose()}
      title={t('Upload custom certificate')}
      description={t('Paste PEM contents or pick files. The key never leaves the server and is never shown back.')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('Cancel')}</Button>
          <Button
            loading={pending}
            disabled={!certPem.trim() || !keyPem.trim()}
            onClick={() => onSubmit({ certificate: certPem.trim(), private_key: keyPem.trim(), chain: chainPem.trim() || undefined })}
          >
            <Upload className="size-4" /> {t('Upload & install')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <PemField label={t('Certificate (PEM)')} value={certPem} onChange={setCertPem} onFile={readInto(setCertPem)} placeholder="-----BEGIN CERTIFICATE-----" />
        <PemField label={t('Private key (PEM)')} value={keyPem} onChange={setKeyPem} onFile={readInto(setKeyPem)} placeholder="-----BEGIN PRIVATE KEY-----" />
        <PemField label={t('Chain / CA bundle (optional)')} value={chainPem} onChange={setChainPem} onFile={readInto(setChainPem)} placeholder="-----BEGIN CERTIFICATE-----" />
      </div>
    </Modal>
  )
}

function PemField({
  label, value, onChange, onFile, placeholder,
}: {
  label: string
  value: string
  onChange: (v: string) => void
  onFile: (e: React.ChangeEvent<HTMLInputElement>) => void
  placeholder: string
}) {
  const t = useT()
  return (
    <Field label={label}>
      <Textarea value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} spellCheck={false} className="font-mono text-xs" rows={4} />
      <label className="mt-1.5 inline-flex cursor-pointer items-center gap-1.5 text-xs text-accent hover:underline">
        <Upload className="size-3.5" /> {t('Choose file…')}
        <input type="file" accept=".pem,.crt,.cer,.key,.txt,application/x-pem-file" className="hidden" onChange={onFile} />
      </label>
    </Field>
  )
}
