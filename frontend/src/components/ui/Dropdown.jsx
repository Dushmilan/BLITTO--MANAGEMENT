import { useState, useEffect, useRef, useCallback, useId } from 'react'

// Menu-button dropdown (issues #16, #17).
// - ArrowDown/Up move between items (wrapping, separators skipped),
//   Home/End jump, Enter/Space activate, Escape closes and refocuses
//   the trigger. Tab closes the menu (WAI-ARIA menu-button dismissal).
// - The trigger hit-area meets the 44px touch minimum.
export default function Dropdown({ trigger, items, className = '' }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const triggerRef = useRef(null)
  const itemRefs = useRef([])
  const menuId = useId()

  const close = useCallback(() => setOpen(false), [])

  const focusableIndexes = items
    .map((item, i) => (item.type === 'separator' ? null : i))
    .filter((i) => i !== null)

  // Focus the first item whenever the menu opens.
  useEffect(() => {
    if (open && focusableIndexes.length > 0) {
      itemRefs.current[focusableIndexes[0]]?.focus()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open ])

  function focusTrigger() {
    const inner = triggerRef.current?.querySelector('button, a, input, [tabindex]')
    if (inner) inner.focus()
    else triggerRef.current?.focus()
  }

  function moveFocus(currentIndex, delta) {
    const order = focusableIndexes
    const pos = order.indexOf(currentIndex)
    const next = order[(pos + delta + order.length) % order.length]
    itemRefs.current[next]?.focus()
  }

  function handleMenuKeyDown(e, index) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      moveFocus(index, 1)
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      moveFocus(index, -1)
    } else if (e.key === 'Home') {
      e.preventDefault()
      itemRefs.current[focusableIndexes[0]]?.focus()
    } else if (e.key === 'End') {
      e.preventDefault()
      itemRefs.current[focusableIndexes[focusableIndexes.length - 1]]?.focus()
    } else if (e.key === 'Escape') {
      e.preventDefault()
      close()
      focusTrigger()
    } else if (e.key === 'Tab') {
      e.preventDefault()
      close()
      focusTrigger()
    }
  }

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
    focusTrigger()
  }

  return (
    <div ref={ref} className={`relative inline-block ${className}`} onMouseDown={(e) => e.stopPropagation()} onClick={(e) => e.stopPropagation()}>
      <div
        ref={triggerRef}
        tabIndex={-1}
        onClick={() => setOpen((prev) => !prev)}
        className="touch-target cursor-pointer inline-flex items-center justify-center"
        aria-expanded={open}
        aria-haspopup="menu"
        aria-controls={menuId}
      >
        {trigger}
      </div>
      {open && (
        <div id={menuId} className="absolute right-0 z-50 mt-1 min-w-[180px] bg-canvas dark:bg-navy-800 rounded-md border border-hairline dark:border-hairline-dark shadow-editorial-md py-1 animate-scale-in" role="menu">
          {items.map((item, i) =>
            item.type === 'separator' ? (
              <div key={`sep-${i}`} role="separator" aria-hidden="true" className="my-1 border-t border-hairline" />
            ) : (
              <button
                key={item.label}
                ref={(el) => { itemRefs.current[i] = el }}
                type="button"
                role="menuitem"
                onClick={() => handleItemClick(item)}
                onKeyDown={(e) => handleMenuKeyDown(e, i)}
                className="touch-target w-full text-left px-md py-sm text-body-sm font-sans text-ink dark:text-white hover:bg-ivory-200 dark:hover:bg-white/10 focus-visible:bg-ivory-200 dark:focus-visible:bg-white/10 focus-visible:outline-none transition-colors duration-150 flex items-center gap-sm"
              >
                {item.icon && <span className="text-steel dark:text-white/60" aria-hidden="true">{item.icon}</span>}
                {item.label}
              </button>
            )
          )}
        </div>
      )}
    </div>
  )
}
