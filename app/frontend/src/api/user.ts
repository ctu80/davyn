import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiGet, apiPost, apiUpload, primeCsrfToken } from '@/lib/api'
import type {
  AddressBook,
  AppPassword,
  AppSettings,
  BirthdayCalendarStatus,
  Calendar,
  CalEvent,
  Contact,
  DashboardData,
  HolidayCatalog,
  HolidaySubscription,
  Me,
  PublicLink,
  SessionInfo,
  ShareRow,
  ShareTarget,
} from './types'

/** Instance branding/settings, readable by any signed-in user (not just admins). */
export function useInstanceSettings() {
  return useQuery({
    queryKey: ['instance-settings'],
    queryFn: () => apiGet<AppSettings>('/api/user/settings'),
    staleTime: 5 * 60 * 1000,
  })
}

export function useMe() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      const me = await apiGet<Me>('/api/user/me')
      primeCsrfToken(me.csrf_token)
      return me
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => apiGet<DashboardData>('/api/user/dashboard'),
  })
}

export function useAppPasswords() {
  return useQuery({
    queryKey: ['app-passwords'],
    queryFn: async () => (await apiGet<{ app_passwords: AppPassword[] }>('/api/user/app-passwords')).app_passwords,
  })
}

export function useCreateAppPassword() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (name: string) => apiPost<{ password: string }>('/api/user/app-passwords/create', { name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['app-passwords'] }),
  })
}

export function useRevokeAppPassword() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (name: string) => apiPost('/api/user/app-passwords/revoke', { name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['app-passwords'] }),
  })
}
export function useDeleteAppPassword() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (name: string) => apiPost('/api/user/app-passwords/delete', { name }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['app-passwords'] }),
  })
}

export function useSessions() {
  return useQuery({
    queryKey: ['sessions'],
    queryFn: async () => (await apiGet<{ sessions: SessionInfo[] }>('/api/user/sessions')).sessions,
  })
}

export function useRevokeSession() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => apiPost<{ logged_out?: boolean }>('/api/user/sessions/revoke', { id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sessions'] }),
  })
}
export function useClearRevokedSessions() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => apiPost<{ deleted: number }>('/api/user/sessions/clear-revoked'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sessions'] }),
  })
}

export function useCalendars() {
  return useQuery({
    queryKey: ['calendars'],
    queryFn: async () => (await apiGet<{ calendars: Calendar[] }>('/api/user/calendars')).calendars,
  })
}

export function useAddressBooks() {
  return useQuery({
    queryKey: ['addressbooks'],
    queryFn: async () => (await apiGet<{ addressbooks: AddressBook[] }>('/api/user/addressbooks')).addressbooks,
  })
}

/** Create a new (regular) calendar for the current user. Returns its generated uri. */
export function useCreateCalendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { display_name: string; color?: string }) =>
      apiPost<{ uri: string }>('/api/user/calendars/create', v),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['calendars'] }),
  })
}

/** Create a new address book for the current user. Returns its generated uri. */
export function useCreateAddressBook() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { display_name: string }) =>
      apiPost<{ uri: string }>('/api/user/addressbooks/create', v),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['addressbooks'] }),
  })
}

/** Permanently delete an owned calendar (events, shares, links all removed). */
export function useDeleteCalendar() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (uri: string) => apiPost('/api/user/calendars/delete', { uri }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['calendars'] })
      qc.invalidateQueries({ queryKey: ['holiday-calendars'] })
      qc.invalidateQueries({ queryKey: ['events'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })
}

/** Permanently delete an owned address book (all contacts removed). */
export function useDeleteAddressBook() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (uri: string) => apiPost('/api/user/addressbooks/delete', { uri }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['addressbooks'] })
      qc.invalidateQueries({ queryKey: ['contacts'] })
      qc.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })
}

// ── Holiday calendar subscriptions ──
export function useHolidayCalendars() {
  return useQuery({
    queryKey: ['holiday-calendars'],
    queryFn: () =>
      apiGet<{ subscriptions: HolidaySubscription[]; catalog: HolidayCatalog }>('/api/user/holiday-calendars'),
  })
}

interface HolidayPreview {
  preview: true
  provider_key: string
  label: string
  this_year: { year: number; count: number }
  next_year: { year: number; count: number }
}

/** Dry-run preview: holiday counts for this year + next, without persisting. */
export function usePreviewHolidayCalendar() {
  return useMutation({
    mutationFn: (v: { provider_key: string; locale?: string }) =>
      apiPost<HolidayPreview>('/api/user/holiday-calendars/create', { ...v, dry_run: true }),
  })
}

function useHolidayInvalidator() {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: ['holiday-calendars'] })
    qc.invalidateQueries({ queryKey: ['calendars'] })
    qc.invalidateQueries({ queryKey: ['events'] })
  }
}

export function useAddHolidayCalendar() {
  const invalidate = useHolidayInvalidator()
  return useMutation({
    mutationFn: (v: { provider_key: string; locale?: string; years_ahead?: number }) =>
      apiPost('/api/user/holiday-calendars/create', v),
    onSuccess: invalidate,
  })
}

export function useDeleteHolidayCalendar() {
  const invalidate = useHolidayInvalidator()
  return useMutation({
    mutationFn: (id: number) => apiPost('/api/user/holiday-calendars/delete', { id }),
    onSuccess: invalidate,
  })
}

export function useRegenerateHolidayCalendar() {
  const invalidate = useHolidayInvalidator()
  return useMutation({
    mutationFn: (id: number) => apiPost<{ generated: number; removed: number }>('/api/user/holiday-calendars/regenerate', { id }),
    onSuccess: invalidate,
  })
}

export function useToggleHolidayCalendar() {
  const invalidate = useHolidayInvalidator()
  return useMutation({
    mutationFn: (v: { id: number; enabled: boolean }) => apiPost('/api/user/holiday-calendars/toggle', v),
    onSuccess: invalidate,
  })
}

// ── Birthday calendar (generated from contacts' BDAY) ──
export function useBirthdayCalendar() {
  return useQuery({
    queryKey: ['birthday-calendar'],
    queryFn: () => apiGet<BirthdayCalendarStatus>('/api/user/birthday-calendar'),
  })
}

function useBirthdayInvalidator() {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: ['birthday-calendar'] })
    qc.invalidateQueries({ queryKey: ['calendars'] })
    qc.invalidateQueries({ queryKey: ['events'] })
  }
}

export function useRegenerateBirthdays() {
  const invalidate = useBirthdayInvalidator()
  return useMutation({
    mutationFn: () => apiPost<{ generated: number; removed: number }>('/api/user/birthday-calendar/regenerate', {}),
    onSuccess: invalidate,
  })
}

export function useToggleBirthdays() {
  const invalidate = useBirthdayInvalidator()
  return useMutation({
    mutationFn: (enabled: boolean) => apiPost('/api/user/birthday-calendar/toggle', { enabled }),
    onSuccess: invalidate,
  })
}

export function useContacts(ab: string | undefined) {
  return useQuery({
    queryKey: ['contacts', ab],
    enabled: !!ab,
    queryFn: async () =>
      (await apiGet<{ contacts: Contact[] }>('/api/user/contacts?ab=' + encodeURIComponent(ab!))).contacts,
  })
}

export function useEvents(cal: string | undefined) {
  return useQuery({
    queryKey: ['events', cal],
    enabled: !!cal,
    queryFn: async () =>
      (await apiGet<{ events: CalEvent[] }>('/api/user/events?cal=' + encodeURIComponent(cal!))).events,
  })
}

/** Fetch events for several calendars at once (shares cache with useEvents). */
export function useMultiEvents(uris: string[], enabled = true) {
  const queries = useQueries({
    queries: uris.map((uri) => ({
      queryKey: ['events', uri],
      enabled: enabled && !!uri,
      queryFn: async () =>
        (await apiGet<{ events: CalEvent[] }>('/api/user/events?cal=' + encodeURIComponent(uri))).events,
    })),
  })
  const byUri: Record<string, CalEvent[]> = {}
  uris.forEach((u, i) => { byUri[u] = (queries[i]?.data as CalEvent[] | undefined) ?? [] })
  return { byUri, isLoading: queries.some((q) => q.isLoading) }
}

/** Fetch contacts for several address books at once (shares cache with useContacts). */
export function useMultiContacts(uris: string[], enabled = true) {
  const queries = useQueries({
    queries: uris.map((uri) => ({
      queryKey: ['contacts', uri],
      enabled: enabled && !!uri,
      queryFn: async () =>
        (await apiGet<{ contacts: Contact[] }>('/api/user/contacts?ab=' + encodeURIComponent(uri))).contacts,
    })),
  })
  const byUri: Record<string, Contact[]> = {}
  uris.forEach((u, i) => { byUri[u] = (queries[i]?.data as Contact[] | undefined) ?? [] })
  return { byUri, isLoading: queries.some((q) => q.isLoading) }
}

export interface ContactInput {
  ab: string
  fn?: string
  first_name?: string
  last_name?: string
  nickname?: string
  org?: string
  title?: string
  note?: string
  bday?: string
  url?: string
  categories?: string[]
  emails?: { type: string; value: string }[]
  phones?: { type: string; value: string }[]
  addresses?: { type: string; street: string; city: string; region: string; code: string; country: string }[]
  uri?: string
  expected_etag?: string
}
export function useSaveContact() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: ContactInput) => apiPost('/api/user/contacts/save', input),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['contacts', v.ab] }),
  })
}
export function useDeleteContact() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { ab: string; uri: string }) => apiPost('/api/user/contacts/delete', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['contacts', v.ab] }),
  })
}

export interface EventInput {
  cal: string
  summary: string
  all_day: boolean
  dtstart: string
  dtend: string
  location?: string
  description?: string
  status?: string
  categories?: string[]
  patch_recurrence?: boolean
  recurrence?: {
    freq: string
    interval: number
    end: { type: string; until: string; count: number }
  }
  patch_reminders?: boolean
  reminders?: { minutes: number }[]
  uri?: string
  expected_etag?: string
  /** Source calendar when editing; if it differs from `cal` the event is moved. */
  from_cal?: string
}
export function useSaveEvent() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: EventInput) => apiPost('/api/user/events/save', input),
    onSuccess: (_d, v) => {
      qc.invalidateQueries({ queryKey: ['events', v.cal] })
      // On a move, refresh the source calendar too so the old copy disappears.
      if (v.from_cal && v.from_cal !== v.cal) qc.invalidateQueries({ queryKey: ['events', v.from_cal] })
    },
  })
}
export function useDeleteEvent() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { cal: string; uri: string }) => apiPost('/api/user/events/delete', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['events', v.cal] }),
  })
}

export function usePublicLinks() {
  return useQuery({
    queryKey: ['public-links'],
    queryFn: async () => (await apiGet<{ links: PublicLink[] }>('/api/user/public-links')).links,
  })
}
export function useCreatePublicLink() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { calendar_uri: string; name?: string }) =>
      apiPost<{ token: string; prefix: string; existing?: boolean }>('/api/user/public-links', v),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['public-links'] }),
  })
}
export function useRevokePublicLink() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => apiPost('/api/user/public-links/revoke', { id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['public-links'] }),
  })
}
export function useDeletePublicLink() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => apiPost('/api/user/public-links/delete', { id }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['public-links'] }),
  })
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (v: { current_password: string; new_password: string }) =>
      apiPost('/api/user/change-password', v),
  })
}

/** Update own profile: display name and/or per-user locale/theme. */
export function useUpdateProfile() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { display_name?: string; locale?: string; theme?: string }) =>
      apiPost<{ ok: boolean; display_name: string; locale: string | null; theme: string | null }>(
        '/api/user/profile',
        v,
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['me'] }),
  })
}

// ── User-owned sharing ──────────────────────────────────────────────────────

/** Other active users this user can share their own collections with. */
export function useShareTargets() {
  return useQuery({
    queryKey: ['share-targets'],
    queryFn: async () => (await apiGet<{ users: ShareTarget[] }>('/api/user/share-targets')).users,
    staleTime: 60_000,
  })
}

/** Shares on one collection the current user owns. */
export function useUserShares(type: 'calendar' | 'addressbook', id: number, enabled: boolean) {
  return useQuery({
    queryKey: ['user-shares', type, id],
    enabled,
    queryFn: () => apiGet<{ shares: ShareRow[] }>(`/api/user/shares?type=${type}&id=${id}`),
  })
}

export function useSetUserShare() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { collection_type: 'calendar' | 'addressbook'; collection_id: number; username: string; permission: string }) =>
      apiPost('/api/user/shares/set', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['user-shares', v.collection_type, v.collection_id] }),
  })
}

export function useRemoveUserShare() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { collection_type: 'calendar' | 'addressbook'; collection_id: number; username: string }) =>
      apiPost('/api/user/shares/remove', v),
    onSuccess: (_d, v) => qc.invalidateQueries({ queryKey: ['user-shares', v.collection_type, v.collection_id] }),
  })
}

export interface ImportFileResult {
  name: string
  created: number
  updated: number
  skipped: number
  errors: string[]
}
export interface ImportResult {
  created: number
  updated: number
  skipped: number
  errors: string[]
  files: ImportFileResult[]
}

export function useImportUpload() {
  return useMutation({
    mutationFn: (v: { type: string; collection: string; files: File[] }) => {
      const fd = new FormData()
      fd.set('type', v.type)
      fd.set('collection', v.collection)
      for (const file of v.files) fd.append('file[]', file)
      return apiUpload<{ result: ImportResult }>('/api/user/import/upload', fd)
    },
  })
}
