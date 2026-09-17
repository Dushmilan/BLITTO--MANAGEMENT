import { useState } from 'react'
import Modal from './Modal.jsx'
import Button from './Button.jsx'

// Accessible replacement for native confirm()/alert() (issue #26).
// Focus trap + Escape come from Modal. `danger` styles the confirm
// button distinctly; `requireConfirm` adds an explicit checkbox gate
// for irreversible actions (issue #28).
export default function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  danger = false,
  requireConfirm = '',
  onConfirm,
  onCancel,
}) {
  const [checked, setChecked] = useState(false)

  if (!open) return null

  function handleCancel() {
    setChecked(false)
    onCancel()
  }

  function handleConfirm() {
    setChecked(false)
    onConfirm()
  }

  return (
    <Modal open={open} onClose={handleCancel} title={title} size="sm">
      <p className="text-body-md text-steel font-sans mb-md">{message}</p>
      {requireConfirm && (
        <label className="flex items-start gap-sm mb-lg cursor-pointer">
          <input
            type="checkbox"
            checked={checked}
            onChange={(e) => setChecked(e.target.checked)}
            className="mt-1 h-4 w-4 accent-copper"
          />
          <span className="text-body-sm text-ink font-sans">{requireConfirm}</span>
        </label>
      )}
      <div className="flex justify-end gap-sm">
        <Button variant="secondary" size="sm" onClick={handleCancel}>
          {cancelLabel}
        </Button>
        <Button
          variant={danger ? 'danger' : 'primary'}
          size="sm"
          onClick={handleConfirm}
          disabled={!!requireConfirm && !checked}
        >
          {confirmLabel}
        </Button>
      </div>
    </Modal>
  )
}
