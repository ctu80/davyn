export type Role = 'admin' | 'user' | 'read_only'
export type Permission = 'owner' | 'read_write' | 'read_only' | 'none'

export interface Me {
  username: string
  display_name: string
  role: Role
  public_base_url?: string
  dav_base: string
  caldav_url: string
  carddav_url: string
  csrf_token: string
  maintenance?: boolean
  maintenance_reason?: string | null
  /** Per-user preferences; null = follow the instance default. */
  locale?: string | null
  theme?: string | null
}

/** A user another user can share a collection with. */
export interface ShareTarget {
  username: string
  display_name: string
}

/** One share row on a collection (user-facing sharing UI). */
export interface ShareRow {
  username: string
  display_name: string
  permission: 'read_only' | 'read_write'
  updated_at: string
}

export interface UpcomingEvent {
  calendar_name: string
  cal_uri: string
  color: string | null
  uri: string
  summary: string
  location: string
  description: string
  date: string
  all_day: boolean
  time: string
  time_end: string
  dtstart_raw: string
}

export interface ActivityEntry {
  id?: number
  action: string
  summary?: string
  detail?: string
  created_at: string
  username?: string
  actor_username?: string | null
  target_type?: string | null
  target_id?: string | null
  ip?: string | null
}

export interface DashboardData {
  today: string
  upcoming: UpcomingEvent[]
  recent_activity: ActivityEntry[]
}

export interface AppPassword {
  name: string
  created_at: string
  last_used_at: string | null
  last_ip?: string | null
  last_user_agent?: string | null
  revoked_at: string | null
  active?: boolean
}

export interface SessionInfo {
  id: number
  user_agent: string | null
  ip?: string | null
  created_at: string
  last_seen_at: string
  revoked: boolean
  current: boolean
  recently_active: boolean
}

export interface Calendar {
  id: number
  uri: string
  display_name: string
  color: string | null
  owner_username: string
  permission: Permission
  shared: boolean
  /** Set for auto-generated calendars (e.g. 'holidays', 'birthdays', 'external'). */
  generated_type?: string | null
  /** True when the calendar cannot be edited (shared read-only or generated). */
  read_only?: boolean
  dav_url: string
}

// ── Holiday calendar subscriptions ──
export interface HolidayRegion {
  region_code: string
  provider_key: string
  label: string
}
export interface HolidayCountry {
  country_code: string
  label: string
  group: string
  default_locale: string
  supported_locales: string[]
  has_regions: boolean
  national_provider_key: string
  regions: HolidayRegion[]
}
export interface HolidayCatalog {
  groups: string[]
  countries: HolidayCountry[]
}
export interface HolidaySubscription {
  id: number
  provider_key: string
  country_code: string
  region_code: string | null
  label: string
  locale: string
  years_back: number
  years_ahead: number
  enabled: boolean
  calendar_id: number
  calendar_uri: string | null
  calendar_name: string | null
  color: string | null
  event_count: number
  generated_years: number[]
  last_generated_at: string | null
  read_only: boolean
}

// ── Birthday calendar (generated from contacts' BDAY) ──
export interface BirthdayCalendarStatus {
  enabled: boolean
  calendar_id: number | null
  event_count: number
  last_generated_at: string | null
  read_only: boolean
}

export interface AddressBook {
  id: number
  uri: string
  display_name: string
  owner_username: string
  permission: Permission
  shared: boolean
  dav_url: string
}

export type ContactFieldType = 'home' | 'work' | 'other'
export type PhoneFieldType = 'mobile' | 'home' | 'work' | 'other'

export interface ContactEmail { type: ContactFieldType; value: string }
export interface ContactPhone { type: PhoneFieldType; value: string }
export interface ContactAddress {
  type: ContactFieldType
  street: string
  city: string
  region: string
  code: string
  country: string
}

export interface Contact {
  uri: string
  etag: string
  fn: string
  first_name: string
  last_name: string
  nickname: string
  org: string
  title: string
  note: string
  bday: string
  url: string
  categories: string[]
  emails: ContactEmail[]
  phones: ContactPhone[]
  addresses: ContactAddress[]
  has_photo: boolean
  /** Flattened primaries for compact cards / search. */
  email: string
  tel: string
}

export type RecurFreq = '' | 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY'
export type RecurEndType = 'never' | 'until' | 'count'
export interface Recurrence {
  freq: RecurFreq
  interval: number
  end: { type: RecurEndType; until: string; count: number }
}
export interface Reminder { minutes: number }
export type EventStatus = '' | 'CONFIRMED' | 'TENTATIVE' | 'CANCELLED'

export interface CalEvent {
  uri: string
  etag: string
  summary: string
  all_day: boolean
  dtstart: string
  dtend: string
  location: string
  description: string
  status: EventStatus
  categories: string[]
  recurring: boolean
  recurrence: Recurrence
  rrule_raw: string
  recurrence_supported: boolean
  reminders: Reminder[]
  reminders_supported: boolean
}

export interface PublicLink {
  id: number
  calendar_id?: number
  calendar_uri?: string
  display_name?: string
  name?: string
  token?: string | null
  token_prefix: string
  created_at: string
  revoked_at: string | null
}

/* ── Admin ── */
export interface AdminStatus {
  app: string
  database: { ok: boolean }
  maintenance: { enabled: boolean; reason?: string | null }
  latest_backup: { filename: string; modified_at: string } | null
  backup_auto_frequency?: BackupFrequency
  recent_activity: ActivityEntry[]
  counts: Record<string, number>
  tls?: {
    mode: TlsMode
    configured: boolean
    certificate: CertStatus
    days_remaining: number | null
    restart_required: boolean
  }
  csrf_token: string | null
}

export type TlsMode = 'http' | 'selfsigned' | 'custom'
export type CertStatus = 'missing' | 'valid' | 'invalid' | 'expired' | 'not_yet_valid' | 'key_mismatch'

export interface TlsCertificate {
  status: CertStatus
  has_certificate: boolean
  has_key: boolean
  subject_cn: string | null
  sans: string[]
  issuer: string | null
  self_signed: boolean | null
  valid_from: string | null
  valid_until: string | null
  days_remaining: number | null
  fingerprint_sha256: string | null
  serial: string | null
}

export type HttpMode = 'enabled' | 'redirect'

export interface TlsStatus {
  mode: TlsMode
  http_mode: HttpMode
  public_base_url: string
  host: string
  http_port: number
  https_port: number
  restart_required: boolean
  restart_pending_since: string | null
  cert_dir: string
  certificate: TlsCertificate
}

export interface AdminUser {
  username: string
  display_name: string
  role: Role
  active: boolean
  created_at: string
}

export interface CollectionGroup {
  username: string
  calendars: AdminCollection[]
  addressbooks: AdminCollection[]
}
export interface AdminCollection {
  id: number
  uri: string
  display_name: string
  owner_username: string
  sync_token: number
  object_count: number
  shares_count: number
}

export interface BackupItem {
  filename: string
  size: number
  size_human?: string
  modified_at: string
}

export type BackupFrequency = 'off' | 'daily' | 'weekly' | 'monthly'

export interface BackupConfig {
  frequency: BackupFrequency
  retention_days: number
  min_keep: number
  last_run_at: string | null
  next_due_at: string | null
  auto_active: boolean
}

export interface AppSettings {
  instance_name: string
  default_locale: string
  default_theme: string
  accent_color: string
  public_base_url: string
}
