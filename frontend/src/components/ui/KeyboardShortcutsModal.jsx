import Modal from './Modal.jsx'

const SHORTCUTS = [
  { keys: 'Ctrl/⌘ + K', action: 'Focus the search box' },
  { keys: 'Esc', action: 'Close dialogs and menus' },
  { keys: '↑ / ↓', action: 'Move between menu items' },
  { keys: 'Enter / Space', action: 'Activate the focused row, menu item or button' },
  { keys: '?', action: 'Open this help' },
]

// Discoverable shortcut reference (issue #45), opened with '?'.
export default function KeyboardShortcutsModal({ open, onClose }) {
  return (
    <Modal open={open} onClose={onClose} title="Keyboard shortcuts" size="sm">
      <ul className="space-y-sm">
        {SHORTCUTS.map((s) => (
          <li key={s.keys} className="flex items-center justify-between gap-lg">
            <span className="text-body-sm text-steel dark:text-white/60 font-sans">{s.action}</span>
            <kbd className="px-sm py-xxs rounded border border-hairline dark:border-hairline-dark bg-ivory-200 dark:bg-white/10 font-mono text-body-sm text-ink dark:text-white whitespace-nowrap">
              {s.keys}
            </kbd>
          </li>
        ))}
      </ul>
    </Modal>
  )
}
