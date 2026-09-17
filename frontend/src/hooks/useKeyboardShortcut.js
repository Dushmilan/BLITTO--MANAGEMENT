import { useEffect } from 'react'

// Reusable global-shortcut hook.
//
// Shortcuts: 'Escape' | 'ctrl+k' | '?' ... modifiers ctrl/shift/alt/meta,
// e.g. useKeyboardShortcut('ctrl+k', focusSearch).
// - Matching is case-insensitive; bare '?' means shift+/ on most layouts.
// - While focus is inside an input/textarea/select (or contentEditable),
//   shortcuts are skipped unless `allowInInputs: true` — so typing never
//   triggers app shortcuts. Escape always fires (used to close modals/menus).
export function useKeyboardShortcut(shortcut, handler, options = {}) {
  const { allowInInputs = false } = options

  useEffect(() => {
    const parts = shortcut.toLowerCase().split('+')
    const wantKey = parts[parts.length - 1]
    const wantCtrl = parts.includes('ctrl')
    const wantShift = parts.includes('shift')
    const wantAlt = parts.includes('alt')
    const wantMeta = parts.includes('meta')

    function onKeyDown(e) {
      if (e.key.toLowerCase() !== wantKey) return
      if (!!e.ctrlKey !== wantCtrl) return
      if (!!e.metaKey !== wantMeta) return
      if (!!e.altKey !== wantAlt) return
      // Shift is implicit for printable keys like '?'; only enforce when asked.
      if (wantShift && !e.shiftKey) return

      const tag = e.target?.tagName
      const typing =
        tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target?.isContentEditable
      if (typing && !allowInInputs && e.key !== 'Escape') return

      handler(e)
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [shortcut, handler, allowInInputs])
}
