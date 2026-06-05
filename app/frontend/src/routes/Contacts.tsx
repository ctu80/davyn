import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Contact as ContactIcon, Plus, Pencil, Trash2, Mail, Phone, Lock, Search,
  User, Briefcase, MapPin, Cake, StickyNote, Image as ImageIcon, X, Download,
} from 'lucide-react'
import { motion } from 'motion/react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input, Textarea, Field } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Dialog'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { Skeleton } from '@/components/ui/Skeleton'
import { DatePicker } from '@/components/ui/DatePicker'
import { FormSection } from '@/components/ui/FormSection'
import { TagInput } from '@/components/ui/TagInput'
import { useToast } from '@/components/ui/Toast'
import { useAddressBooks, useContacts, useDeleteContact, useSaveContact, useDeleteAddressBook, useCreateAddressBook } from '@/api/user'
import type { Contact, ContactAddress, ContactEmail, ContactPhone } from '@/api/types'
import { contactSearchText } from '@/lib/contacts'
import { ApiError } from '@/lib/api'
import { useT } from '@/i18n/LocaleContext'

// Type labels are English keys translated at render via t(o.label).
const EMAIL_TYPES = [
  { value: 'home', label: 'Home' }, { value: 'work', label: 'Work' }, { value: 'other', label: 'Other' },
]
const PHONE_TYPES = [
  { value: 'mobile', label: 'Mobile' }, { value: 'home', label: 'Home' }, { value: 'work', label: 'Work' }, { value: 'other', label: 'Other' },
]
const ADDR_TYPES = EMAIL_TYPES

interface ContactForm {
  fn: string
  first: string
  last: string
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
  hasPhoto: boolean
}

const EMPTY: ContactForm = {
  fn: '', first: '', last: '', nickname: '', org: '', title: '', note: '', bday: '', url: '',
  categories: [], emails: [], phones: [], addresses: [], hasPhoto: false,
}

const emptyAddress = (): ContactAddress => ({ type: 'home', street: '', city: '', region: '', code: '', country: '' })

export default function Contacts() {
  const toast = useToast()
  const t = useT()
  const { data: books } = useAddressBooks()
  const [searchParams, setSearchParams] = useSearchParams()
  // Optional deep-link from global search: ?ab=&cn= open a specific contact.
  const abParam = searchParams.get('ab')
  const cnParam = searchParams.get('cn')
  const [ab, setAb] = useState<string>(abParam ?? '')
  useEffect(() => {
    if (!ab && books?.length) setAb(books[0].uri)
  }, [books, ab])
  // Select the linked book right away and drop the ab param so it can't fight
  // manual book switching; the contact itself opens once that book loads (below).
  useEffect(() => {
    if (!abParam) return
    setAb(abParam)
    setSearchParams((p) => { p.delete('ab'); return p }, { replace: true })
  }, [abParam]) // eslint-disable-line react-hooks/exhaustive-deps

  const current = books?.find((b) => b.uri === ab)
  const writable = current?.permission === 'owner' || current?.permission === 'read_write'
  const owned = current?.permission === 'owner'
  const { data: contacts, isLoading } = useContacts(ab)
  const save = useSaveContact()
  const del = useDeleteContact()
  const delBook = useDeleteAddressBook()
  const createBook = useCreateAddressBook()
  const [deleteBookOpen, setDeleteBookOpen] = useState(false)
  const [createBookOpen, setCreateBookOpen] = useState(false)
  const [newBookName, setNewBookName] = useState('')

  const [editing, setEditing] = useState<Contact | null>(null)
  const [open, setOpen] = useState(false)
  const [toDelete, setToDelete] = useState<Contact | null>(null)
  const [form, setForm] = useState<ContactForm>(EMPTY)
  const set = <K extends keyof ContactForm>(k: K, v: ContactForm[K]) => setForm((f) => ({ ...f, [k]: v }))
  const [q, setQ] = useState('')

  const options = useMemo(
    () => (books ?? []).map((b) => ({ value: b.uri, label: b.display_name })),
    [books],
  )

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase()
    if (!needle) return contacts ?? []
    return (contacts ?? []).filter((c) => contactSearchText(c).includes(needle))
  }, [contacts, q])

  // Open the deep-linked contact once its book's contacts have loaded. The cn
  // param is cleared when the modal closes (see setContactModalOpen), which also
  // lets the same contact be reopened from a later search. Reading from the URL
  // (not ephemeral router state) makes this robust to the route mounting.
  const openedRef = useRef<string | null>(null)
  useEffect(() => {
    if (!cnParam) { openedRef.current = null; return }
    if (openedRef.current === cnParam || !contacts) return
    const c = contacts.find((x) => x.uri === cnParam)
    if (c) { openedRef.current = cnParam; openEdit(c) }
  }, [cnParam, contacts]) // eslint-disable-line react-hooks/exhaustive-deps

  // Every close path goes through here so the deep-link param is dropped.
  function setContactModalOpen(o: boolean) {
    setOpen(o)
    if (!o && searchParams.has('cn')) {
      setSearchParams((p) => { p.delete('cn'); return p }, { replace: true })
    }
  }

  function openNew() {
    setEditing(null)
    setForm({ ...EMPTY, emails: [{ type: 'home', value: '' }], phones: [{ type: 'mobile', value: '' }] })
    setOpen(true)
  }
  function openEdit(c: Contact) {
    setEditing(c)
    setForm({
      fn: c.fn ?? '',
      first: c.first_name ?? '',
      last: c.last_name ?? '',
      nickname: c.nickname ?? '',
      org: c.org ?? '',
      title: c.title ?? '',
      note: c.note ?? '',
      bday: c.bday ?? '',
      url: c.url ?? '',
      categories: c.categories ?? [],
      emails: c.emails?.length ? c.emails : [],
      phones: c.phones?.length ? c.phones : [],
      addresses: c.addresses ?? [],
      hasPhoto: c.has_photo ?? false,
    })
    setOpen(true)
  }

  const derivedName = form.fn.trim() || `${form.first} ${form.last}`.trim() || form.org.trim()

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!ab) return
    if (!derivedName) return toast.error(t('Name required'), t('Enter a first/last name or a display name.'))
    try {
      await save.mutateAsync({
        ab,
        fn: form.fn.trim(),
        first_name: form.first.trim(),
        last_name: form.last.trim(),
        nickname: form.nickname.trim(),
        org: form.org.trim(),
        title: form.title.trim(),
        note: form.note.trim(),
        bday: form.bday.trim(),
        url: form.url.trim(),
        categories: form.categories,
        emails: form.emails.filter((x) => x.value.trim()),
        phones: form.phones.filter((x) => x.value.trim()),
        addresses: form.addresses.filter((a) => a.street || a.city || a.region || a.code || a.country),
        uri: editing?.uri,
        expected_etag: editing?.etag,
      })
      toast.success(editing ? t('Contact updated') : t('Contact created'))
      setContactModalOpen(false)
    } catch (err) {
      toast.error(t('Could not save contact'), err instanceof ApiError ? err.message : undefined)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('Contacts')}
        subtitle={t('Address books synced over CardDAV')}
        icon={ContactIcon}
        actions={
          <>
            <Button variant="subtle" size="sm" onClick={() => { setNewBookName(''); setCreateBookOpen(true) }}>
              <Plus className="size-4" /> {t('New list')}
            </Button>
            {writable && <Button onClick={openNew}><Plus className="size-4" /> {t('New contact')}</Button>}
          </>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <div className="w-full sm:w-72">
          <Select value={ab} onValueChange={setAb} options={options} placeholder={t('Select address book')} />
        </div>
        <div className="relative w-full sm:max-w-xs sm:flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Search name, email, phone…')} className="pl-9" />
        </div>
        {current?.shared && <Badge tone="info">{t('shared')} · {current.permission.replace('_', ' ')}</Badge>}
        {owned && current && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setDeleteBookOpen(true)}
            className="text-muted hover:text-danger sm:ml-auto"
            title={t('Delete this address book')}
          >
            <Trash2 className="size-4" /> {t('Delete list')}
          </Button>
        )}
      </div>

      {isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20 w-full" />)}
        </div>
      ) : filtered.length ? (
        <div className="grid grid-cols-1 gap-3 sm:[grid-template-columns:repeat(auto-fit,minmax(17rem,1fr))]">
          {filtered.map((c, i) => (
            <motion.div key={c.uri} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: Math.min(i * 0.03, 0.3) }}>
              <Card hover className="group flex items-center justify-between gap-3 p-4">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="grid size-11 shrink-0 place-items-center rounded-xl gradient-accent text-sm font-semibold text-white">
                    {(c.fn || '?').slice(0, 2).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium">{c.fn || t('(no name)')}</p>
                    {c.org && <p className="truncate text-xs text-muted">{c.title ? `${c.title} · ` : ''}{c.org}</p>}
                    <div className="mt-0.5 flex flex-col gap-0.5 text-xs text-muted">
                      {c.email && <span className="flex items-center gap-1 truncate"><Mail className="size-3" /> {c.email}</span>}
                      {c.tel && <span className="flex items-center gap-1 truncate"><Phone className="size-3" /> {c.tel}</span>}
                    </div>
                  </div>
                </div>
                {writable && (
                  <div className="flex shrink-0 gap-1 opacity-0 transition group-hover:opacity-100">
                    <Button variant="ghost" size="icon" onClick={() => openEdit(c)} aria-label={t('Edit')}><Pencil className="size-4" /></Button>
                    <Button variant="ghost" size="icon" onClick={() => setToDelete(c)} aria-label={t('Delete')}><Trash2 className="size-4" /></Button>
                  </div>
                )}
              </Card>
            </motion.div>
          ))}
        </div>
      ) : q.trim() && contacts?.length ? (
        <EmptyState icon={Search} title={t('No matches')} description={t('No contacts match "{q}".', { q })} />
      ) : (
        <EmptyState
          icon={writable ? ContactIcon : Lock}
          title={writable ? t('No contacts yet') : t('Read-only address book')}
          description={writable ? t('Add your first contact to this address book.') : t('You can view but not edit this shared address book.')}
          action={writable ? <Button onClick={openNew}><Plus className="size-4" /> {t('New contact')}</Button> : undefined}
        />
      )}

      <Modal
        open={open}
        onOpenChange={setContactModalOpen}
        size="2xl"
        title={editing ? t('Edit contact') : t('New contact')}
        footer={
          <>
            {editing && ab && (
              <a
                href={`/api/user/export/addressbook?ab=${encodeURIComponent(ab)}&uri=${encodeURIComponent(editing.uri)}`}
                download
                className="mr-auto inline-flex items-center gap-1.5 text-xs font-medium text-muted-strong hover:text-foreground hover:underline"
              >
                <Download className="size-3.5" /> {t('Export .vcf')}
              </a>
            )}
            <Button variant="ghost" onClick={() => setContactModalOpen(false)}>{t('Cancel')}</Button>
            <Button onClick={submit} loading={save.isPending}>{editing ? t('Save') : t('Create')}</Button>
          </>
        }
      >
        <form onSubmit={submit} className="space-y-6">
          {/* Name */}
          <FormSection title={t('Name')} icon={User}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('First name')}><Input value={form.first} onChange={(e) => set('first', e.target.value)} placeholder="Jane" autoFocus /></Field>
              <Field label={t('Last name')}><Input value={form.last} onChange={(e) => set('last', e.target.value)} placeholder="Doe" /></Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('Display name')} hint={t('Blank = auto from name')}>
                <Input value={form.fn} onChange={(e) => set('fn', e.target.value)} placeholder={derivedName || 'Jane Doe'} />
              </Field>
              <Field label={t('Nickname')}><Input value={form.nickname} onChange={(e) => set('nickname', e.target.value)} placeholder="Janie" /></Field>
            </div>
          </FormSection>

          {/* Contact */}
          <FormSection
            title={t('Contact')} icon={Mail}
            aside={<AddBtn label={t('Email')} onClick={() => set('emails', [...form.emails, { type: 'home', value: '' }])} />}
          >
            {form.emails.length === 0 && <EmptyRow text={t('No email addresses')} />}
            {form.emails.map((em, i) => (
              <ValueRow
                key={i}
                type={em.type}
                typeOptions={EMAIL_TYPES.map((o) => ({ value: o.value, label: t(o.label) }))}
                value={em.value}
                placeholder="name@example.com"
                inputType="email"
                onType={(t) => set('emails', form.emails.map((x, j) => j === i ? { ...x, type: t as ContactEmail['type'] } : x))}
                onValue={(v) => set('emails', form.emails.map((x, j) => j === i ? { ...x, value: v } : x))}
                onRemove={() => set('emails', form.emails.filter((_, j) => j !== i))}
              />
            ))}

            <div className="pt-1" />
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-muted-strong">{t('Phone numbers')}</span>
              <AddBtn label={t('Phone')} onClick={() => set('phones', [...form.phones, { type: 'mobile', value: '' }])} />
            </div>
            {form.phones.length === 0 && <EmptyRow text={t('No phone numbers')} />}
            {form.phones.map((ph, i) => (
              <ValueRow
                key={i}
                type={ph.type}
                typeOptions={PHONE_TYPES.map((o) => ({ value: o.value, label: t(o.label) }))}
                value={ph.value}
                placeholder="+49 170 1234567"
                inputType="tel"
                onType={(t) => set('phones', form.phones.map((x, j) => j === i ? { ...x, type: t as ContactPhone['type'] } : x))}
                onValue={(v) => set('phones', form.phones.map((x, j) => j === i ? { ...x, value: v } : x))}
                onRemove={() => set('phones', form.phones.filter((_, j) => j !== i))}
              />
            ))}
            <Field label={t('Website')}><Input value={form.url} onChange={(e) => set('url', e.target.value)} placeholder="https://example.com" /></Field>
          </FormSection>

          {/* Work */}
          <FormSection title={t('Work')} icon={Briefcase}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('Company')}><Input value={form.org} onChange={(e) => set('org', e.target.value)} placeholder="Acme Inc." /></Field>
              <Field label={t('Job title')}><Input value={form.title} onChange={(e) => set('title', e.target.value)} placeholder="Product Manager" /></Field>
            </div>
          </FormSection>

          {/* Address */}
          <FormSection title={t('Address')} icon={MapPin} aside={<AddBtn label={t('Address')} onClick={() => set('addresses', [...form.addresses, emptyAddress()])} />}>
            {form.addresses.length === 0 && <EmptyRow text={t('No addresses')} />}
            {form.addresses.map((a, i) => (
              <div key={i} className="space-y-2 rounded-xl bg-foreground/[0.03] p-3 ring-1 ring-inset ring-foreground/8">
                <div className="flex items-center gap-2">
                  <div className="w-28"><Select value={a.type} onValueChange={(ty) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, type: ty as ContactAddress['type'] } : x))} options={ADDR_TYPES.map((o) => ({ value: o.value, label: t(o.label) }))} /></div>
                  <button type="button" onClick={() => set('addresses', form.addresses.filter((_, j) => j !== i))} className="ml-auto grid size-8 place-items-center rounded-lg text-muted transition hover:bg-foreground/10 hover:text-danger" aria-label={t('Remove address')}><X className="size-4" /></button>
                </div>
                <Input value={a.street} onChange={(e) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, street: e.target.value } : x))} placeholder={t('Street and number')} />
                <div className="grid gap-2 sm:grid-cols-3">
                  <Input value={a.code} onChange={(e) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, code: e.target.value } : x))} placeholder={t('Postal code')} />
                  <Input value={a.city} onChange={(e) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, city: e.target.value } : x))} placeholder={t('City')} className="sm:col-span-2" />
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                  <Input value={a.region} onChange={(e) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, region: e.target.value } : x))} placeholder={t('Region / state')} />
                  <Input value={a.country} onChange={(e) => set('addresses', form.addresses.map((x, j) => j === i ? { ...x, country: e.target.value } : x))} placeholder={t('Country')} />
                </div>
              </div>
            ))}
          </FormSection>

          {/* Personal */}
          <FormSection title={t('Personal')} icon={Cake}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('Birthday')} hint={t('Appears in your Birthday calendar')}><DatePicker value={form.bday} onChange={(v) => set('bday', v)} placeholder={t('Pick a date')} /></Field>
              <Field label={t('Categories')} hint={t('Press Enter or comma to add')}>
                <TagInput value={form.categories} onChange={(v) => set('categories', v)} placeholder={t('Friends, Colleagues…')} />
              </Field>
            </div>
          </FormSection>

          {/* Notes */}
          <FormSection title={t('Notes')} icon={StickyNote}>
            <Textarea value={form.note} onChange={(e) => set('note', e.target.value)} placeholder={t('Anything worth remembering…')} />
          </FormSection>

          {form.hasPhoto && (
            <p className="flex items-center gap-2 rounded-xl bg-foreground/[0.03] p-3 text-xs text-muted ring-1 ring-inset ring-foreground/8">
              <ImageIcon className="size-4 shrink-0 text-muted-strong" />
              {t('This contact has a photo and other fields set on another device — they’ll be kept when you save.')}
            </p>
          )}
        </form>
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        onOpenChange={(o) => !o && setToDelete(null)}
        title={t('Delete contact?')}
        description={t('"{name}" will be removed.', { name: toDelete?.fn || t('This contact') })}
        confirmLabel={t('Delete')}
        danger
        loading={del.isPending}
        onConfirm={async () => {
          try {
            await del.mutateAsync({ ab: ab!, uri: toDelete!.uri })
            toast.success(t('Contact deleted'))
          } catch (err) {
            toast.error(t('Could not delete'), err instanceof ApiError ? err.message : undefined)
          }
          setToDelete(null)
        }}
      />

      <ConfirmDialog
        open={deleteBookOpen}
        onOpenChange={setDeleteBookOpen}
        title={t('Delete address book?')}
        description={t('"{name}" and all of its contacts will be permanently removed. This cannot be undone.', { name: current?.display_name ?? t('This address book') })}
        confirmLabel={t('Delete address book')}
        danger
        loading={delBook.isPending}
        onConfirm={async () => {
          const uri = ab!
          try {
            await delBook.mutateAsync(uri)
            // Switch to the first remaining book straight away (don't wait for refetch).
            setAb(books?.find((b) => b.uri !== uri)?.uri ?? '')
            toast.success(t('Address book deleted'))
          } catch (err) {
            toast.error(t('Could not delete address book'), err instanceof ApiError ? err.message : undefined)
          }
          setDeleteBookOpen(false)
        }}
      />

      <Modal
        open={createBookOpen}
        onOpenChange={setCreateBookOpen}
        title={t('New address book')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setCreateBookOpen(false)}>{t('Cancel')}</Button>
            <Button
              loading={createBook.isPending}
              onClick={async () => {
                if (!newBookName.trim()) return toast.error(t('Name required'), t('Enter an address book name.'))
                try {
                  const res = await createBook.mutateAsync({ display_name: newBookName.trim() })
                  setAb(res.uri)
                  toast.success(t('Address book created'))
                  setCreateBookOpen(false)
                } catch (err) {
                  toast.error(t('Could not create address book'), err instanceof ApiError ? err.message : undefined)
                }
              }}
            >
              {t('Create')}
            </Button>
          </>
        }
      >
        <Field label={t('Name')}>
          <Input value={newBookName} onChange={(e) => setNewBookName(e.target.value)} placeholder={t('e.g. Work, Personal…')} autoFocus />
        </Field>
      </Modal>
    </div>
  )
}

function AddBtn({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="inline-flex items-center gap-1 text-xs font-medium text-accent transition hover:opacity-80">
      <Plus className="size-3.5" /> {label}
    </button>
  )
}

function EmptyRow({ text }: { text: string }) {
  return <p className="rounded-lg bg-foreground/[0.02] px-3 py-2 text-xs text-muted">{text}</p>
}

function ValueRow({
  type, typeOptions, value, placeholder, inputType, onType, onValue, onRemove,
}: {
  type: string
  typeOptions: { value: string; label: string }[]
  value: string
  placeholder: string
  inputType?: string
  onType: (t: string) => void
  onValue: (v: string) => void
  onRemove: () => void
}) {
  const t = useT()
  return (
    <div className="flex items-center gap-2">
      <div className="w-28 shrink-0"><Select value={type} onValueChange={onType} options={typeOptions} /></div>
      <Input type={inputType} value={value} onChange={(e) => onValue(e.target.value)} placeholder={placeholder} className="flex-1" />
      <button type="button" onClick={onRemove} className="grid size-9 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-foreground/10 hover:text-danger" aria-label={t('Remove')}><X className="size-4" /></button>
    </div>
  )
}
