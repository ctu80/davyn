// Copy text to the clipboard, robust across contexts.
//
// navigator.clipboard only works in "secure contexts" (HTTPS or localhost).
// Davyn is often reached over plain HTTP on a LAN IP/hostname, where that API
// is undefined — so we fall back to a hidden <textarea> + execCommand('copy'),
// which still works on non-secure origins. Returns true on success.
export async function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text)
      return true
    } catch {
      /* fall through to the legacy path */
    }
  }
  try {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.setAttribute('readonly', '')
    ta.style.position = 'fixed'
    ta.style.top = '0'
    ta.style.left = '0'
    ta.style.width = '1px'
    ta.style.height = '1px'
    ta.style.padding = '0'
    ta.style.opacity = '0'
    // Append inside the open dialog (if any). A modal focus-trap (Radix Dialog)
    // would otherwise yank focus back to the dialog and clear the textarea
    // selection before execCommand runs — so the copy would silently do nothing
    // even though the command reports success. Hosting the textarea within the
    // trap keeps the selection valid.
    const host = (document.activeElement?.closest('[role="dialog"]') as HTMLElement | null) ?? document.body
    const restore = document.activeElement as HTMLElement | null
    host.appendChild(ta)
    ta.focus()
    ta.select()
    ta.setSelectionRange(0, text.length)
    const ok = document.execCommand('copy')
    host.removeChild(ta)
    restore?.focus?.()
    return ok
  } catch {
    return false
  }
}
