import { useState, useEffect, useRef, useCallback } from 'react'

export default function Dropdown({ trigger, items, className = '' }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)

  const close = useCallback(() => setOpen(false), [])

  useEffect(() => {
    if (!open) return
    function handleKeyDown(e) {
      if (e.key === 'Escape') close()
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [open, close])

  useEffect(() => {
    if (!open) return
    function handleClickOutside(e) {
      if (ref.current && !ref.current.contains(e.target)) close()
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [open, close])

  function handleItemClick(item) {
    item.onClick?.()
    close()
  }

  return (
    <div ref={ref} className={`relative inline-block ${className}`}>
      <div
        onClick={() => setOpen((prev) => !prev)}
        className="cursor-pointer"
        aria-expanded={open}
        aria-haspopup="true"
      >
        {trigger}
      </div>
      {open && (
        <div className="absolute right-0 z-50 mt-1 min-w-[180px] bg-canvas rounded-md border border-hairline shadow-editorial-md py-1 animate-scale-in" role="menu">
          {items.map((item) => (
            <button
              key={item.label}
              type="button"
              role="menuitem"
              onClick={() => handleItemClick(item)}
              className="w-full text-left px-md py-sm text-body-sm font-sans text-ink hover:bg-ivory-200 transition-colors duration-150 flex items-center gap-sm"
            >
              {item.icon && <span className="text-steel">{item.icon}</span>}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
