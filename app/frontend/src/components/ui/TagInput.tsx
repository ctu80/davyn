import { useState } from 'react'
import { X } from 'lucide-react'
import { inputClass } from './Input'
import { cn } from '@/lib/cn'

/** Free-form tag/category editor. Enter or comma commits a tag. */
export function TagInput({
  value,
  onChange,
  placeholder = 'Add tag…',
}: {
  value: string[]
  onChange: (next: string[]) => void
  placeholder?: string
}) {
  const [draft, setDraft] = useState('')

  function commit(raw: string) {
    const t = raw.trim()
    if (t && !value.includes(t)) onChange([...value, t])
    setDraft('')
  }

  return (
    <div className={cn(inputClass, 'flex min-h-10 flex-wrap items-center gap-1.5 py-2')}>
      {value.map((tag) => (
        <span key={tag} className="inline-flex items-center gap-1 rounded-md bg-accent/12 px-2 py-0.5 text-xs font-medium text-accent">
          {tag}
          <button type="button" onClick={() => onChange(value.filter((t) => t !== tag))} className="opacity-70 transition hover:opacity-100" aria-label={`Remove ${tag}`}>
            <X className="size-3" />
          </button>
        </span>
      ))}
      <input
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commit(draft) }
          else if (e.key === 'Backspace' && draft === '' && value.length) onChange(value.slice(0, -1))
        }}
        onBlur={() => draft && commit(draft)}
        placeholder={value.length ? '' : placeholder}
        className="min-w-20 flex-1 bg-transparent text-sm outline-none placeholder:text-muted/70"
      />
    </div>
  )
}
