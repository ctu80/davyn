// Pick the right keyboard-shortcut wording for the user's OS. The command
// palette listens for both ⌘ (Mac) and Ctrl (Windows/Linux), so we only need
// this to display the matching hint instead of always showing the Mac symbol.
const ua = typeof navigator !== 'undefined' ? `${navigator.platform} ${navigator.userAgent}` : ''
export const isMac = /Mac|iPhone|iPad|iPod/i.test(ua)

/** Modifier label for shortcut badges: "⌘" on Mac, "Ctrl" elsewhere. */
export const modKey = isMac ? '⌘' : 'Ctrl'
