import { useEffect, useState } from 'react'

// Returns `value` delayed by `delay` ms, restarting the timer on every
// change — for search inputs that filter large datasets (issue #34).
export function useDebounce(value, delay = 300) {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}
