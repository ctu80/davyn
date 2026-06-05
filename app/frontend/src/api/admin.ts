import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiGet, apiPost, primeCsrfToken } from '@/lib/api'
import type { ActivityEntry, AdminStatus, AdminUser, AppSettings, BackupConfig, BackupFrequency, BackupItem, CollectionGroup, Role, TlsStatus } from './types'

export function useAdminStatus(enabled = true) {
  return useQuery({
    queryKey: ['admin-status'],
    enabled,
    queryFn: async () => {
      const s = await apiGet<AdminStatus>('/api/admin/status')
      if (s.csrf_token) primeCsrfToken(s.csrf_token)
      return s
    },
  })
}

export function useUsers(enabled = true) {
  return useQuery({
    queryKey: ['admin-users'],
    enabled,
    queryFn: () => apiGet<AdminUser[]>('/api/admin/users'),
  })
}
const invUsers = (qc: ReturnType<typeof useQueryClient>) => qc.invalidateQueries({ queryKey: ['admin-users'] })

export function useCreateUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; display_name: string; role: Role; password: string }) =>
      apiPost('/api/admin/users/create', v),
    onSuccess: () => invUsers(qc),
  })
}
export function useSetUserActive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; active: boolean }) => apiPost('/api/admin/users/set-active', v),
    onSuccess: () => invUsers(qc),
  })
}
export function useChangeUserPassword() {
  return useMutation({
    mutationFn: (v: { username: string; password: string }) => apiPost('/api/admin/users/change-password', v),
  })
}
export function useRenameUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; display_name: string }) =>
      apiPost('/api/admin/users/update-display-name', v),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-users'] }),
  })
}

export function useDeleteUser() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; confirm_username: string }) => apiPost('/api/admin/users/delete', v),
    onSuccess: () => invUsers(qc),
  })
}

export function useCollections() {
  return useQuery({
    queryKey: ['admin-collections'],
    queryFn: () => apiGet<CollectionGroup[]>('/api/admin/collections'),
  })
}
const invCollections = (qc: ReturnType<typeof useQueryClient>) =>
  qc.invalidateQueries({ queryKey: ['admin-collections'] })

export function useCreateCalendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string; display_name: string; color?: string }) =>
      apiPost('/api/admin/collections/calendars/create', v),
    onSuccess: () => invCollections(qc),
  })
}
export function useUpdateCalendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string; display_name?: string; color?: string }) =>
      apiPost('/api/admin/collections/calendars/update', v),
    onSuccess: () => invCollections(qc),
  })
}
export function useCreateAddressBook() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string; display_name: string }) =>
      apiPost('/api/admin/collections/addressbooks/create', v),
    onSuccess: () => invCollections(qc),
  })
}
export function useUpdateAddressBook() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string; display_name?: string }) =>
      apiPost('/api/admin/collections/addressbooks/update', v),
    onSuccess: () => invCollections(qc),
  })
}
export function useDeleteCalendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string }) =>
      apiPost('/api/admin/collections/calendars/delete', v),
    onSuccess: () => invCollections(qc),
  })
}
export function useDeleteAddressBook() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { username: string; uri: string }) =>
      apiPost('/api/admin/collections/addressbooks/delete', v),
    onSuccess: () => invCollections(qc),
  })
}

export interface ShareEntry {
  username: string
  display_name: string
  permission: string
  updated_at: string
}
export function useShares(type: string, id: number, enabled: boolean) {
  return useQuery({
    queryKey: ['admin-shares', type, id],
    enabled,
    // The endpoint also returns a `collection` object; the UI only needs `shares`.
    queryFn: () => apiGet<{ shares: ShareEntry[] }>(`/api/admin/shares?type=${type}&id=${id}`),
  })
}
export function useSetShare() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { collection_type: string; collection_id: number; username: string; permission: string }) =>
      apiPost('/api/admin/shares/set', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['admin-shares', v.collection_type, v.collection_id] }),
  })
}
export function useRemoveShare() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { collection_type: string; collection_id: number; username: string }) =>
      apiPost('/api/admin/shares/remove', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['admin-shares', v.collection_type, v.collection_id] }),
  })
}

export function useBackups(enabled = true) {
  return useQuery({
    queryKey: ['admin-backups'],
    enabled,
    queryFn: () => apiGet<BackupItem[]>('/api/admin/backups'),
  })
}
export function useCreateBackup() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiPost('/api/admin/backups/create'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-backups'] }),
  })
}
export function useDeleteBackup() {
  const qc = useQueryClient()
  return useMutation({
    // Accepts one filename or a batch; the server deletes all under a single reauth.
    mutationFn: (filenames: string | string[]) =>
      apiPost('/api/admin/backups/delete', {
        filenames: Array.isArray(filenames) ? filenames : [filenames],
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-backups'] }),
  })
}

export function useBackupConfig(enabled = true) {
  return useQuery({
    queryKey: ['admin-backup-config'],
    enabled,
    queryFn: () => apiGet<BackupConfig>('/api/admin/backups/config'),
  })
}
export function useSaveBackupConfig() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { frequency: BackupFrequency; retention_days: number; min_keep: number }) =>
      apiPost<BackupConfig>('/api/admin/backups/config', v),
    onSuccess: (data) => {
      qc.setQueryData(['admin-backup-config'], data)
      qc.invalidateQueries({ queryKey: ['admin-status'] })
    },
  })
}

export function useSetMaintenance() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { enabled: boolean; reason?: string }) =>
      apiPost<{ enabled: boolean; reason: string | null; enabled_at: string | null }>(
        '/api/admin/maintenance',
        v,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-status'] })
      // The persistent maintenance banner reads from `me`; refresh it so it flies
      // in/out immediately when toggled from anywhere.
      qc.invalidateQueries({ queryKey: ['me'] })
    },
  })
}

export function useActivity(limit = 100) {
  return useQuery({
    queryKey: ['admin-activity', limit],
    queryFn: async () =>
      (await apiGet<{ entries: ActivityEntry[] }>(`/api/admin/activity?limit=${limit}`)).entries,
  })
}

export function useSettings() {
  return useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => apiGet<AppSettings>('/api/admin/settings'),
  })
}
export function useSaveSettings() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: Partial<AppSettings>) => apiPost('/api/admin/settings', v),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-settings'] })
      qc.invalidateQueries({ queryKey: ['instance-settings'] })
    },
  })
}

// ── Internal HTTPS / TLS ───────────────────────────────────────────────────

export interface GenerateCertInput {
  common_name: string
  dns_sans: string[]
  ip_sans: string[]
  days: number
  organization?: string
}
export interface UploadCertInput {
  certificate: string
  private_key: string
  chain?: string
}
interface TlsActionResult {
  ok: boolean
  status: TlsStatus
  warnings?: string[]
}

export function useTlsStatus(enabled = true) {
  return useQuery({
    queryKey: ['admin-tls'],
    enabled,
    queryFn: () => apiGet<TlsStatus>('/api/admin/tls/status'),
  })
}

function tlsMutation<V>(path: string) {
  return function useTlsMutation() {
    const qc = useQueryClient()
    return useMutation({
      mutationFn: (v?: V) => apiPost<TlsActionResult>(path, v),
      onSuccess: (res) => {
        qc.setQueryData(['admin-tls'], res.status)
        qc.invalidateQueries({ queryKey: ['admin-status'] })
      },
    })
  }
}

export const useGenerateCert = tlsMutation<GenerateCertInput>('/api/admin/tls/generate')
export const useUploadCert   = tlsMutation<UploadCertInput>('/api/admin/tls/upload')
export const useValidateCert = tlsMutation<void>('/api/admin/tls/validate')
export const useRemoveCert   = tlsMutation<void>('/api/admin/tls/remove')
export const useAckTlsRestart = tlsMutation<void>('/api/admin/tls/ack-restart')
// Force-HTTPS toggle. Reauth-gated server-side; wrap the call in useReauth().run().
export const useSetHttpMode  = tlsMutation<{ mode: 'enabled' | 'redirect' }>('/api/admin/tls/http-mode')
