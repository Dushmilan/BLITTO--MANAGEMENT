import { useEffect, useRef } from 'react'

export default function Modal({ open, onClose, title, children, size = 'md' }) {
  const overlayRef = useRef(null)

  useEffect(() => {
    if (open) {
      document.body.style.overflow = 'hidden'
    } else {
      document.body.style.overflow = ''
    }
    return () => { document.body.style.overflow = '' }
  }, [open])

  useEffect(() => {
    function handleKeyDown(e) {
      if (e.key === 'Escape' && open) onClose()
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [open, onClose])

  if (!open) return null

  const sizes = {
    sm: 'max-w-[420px]',
    md: 'max-w-[560px]',
    lg: 'max-w-[720px]',
    xl: 'max-w-[900px]',
  }

  return (
    <div
      ref={overlayRef}
      className="fixed inset-0 z-50 flex items-center justify-center p-xl animate-fade-in"
      onClick={(e) => { if (e.target === overlayRef.current) onClose() }}
    >
      {/* Backdrop */}
      <div className="absolute inset-0 bg-navy/60 backdrop-blur-sm" />

      {/* Dialog */}
      <div className={`relative bg-canvas rounded-xl border border-hairline shadow-editorial-xl w-full ${sizes[size]} animate-scale-in`}>
        {/* Header */}
        <div className="px-xl py-lg border-b border-hairline flex items-center justify-between">
          <h2 className="font-display text-heading-4 text-ink">{title}</h2>
          <button
            onClick={onClose}
            className="text-muted hover:text-ink transition-colors p-xs rounded-md hover:bg-ivory-200"
            aria-label="Close"
          >
            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5">
              <path d="M4 4l10 10M14 4L4 14" />
            </svg>
          </button>
        </div>

        {/* Body */}
        <div className="px-xl py-xl max-h-[70vh] overflow-y-auto">
          {children}
        </div>
      </div>
    </div>
  )
}
