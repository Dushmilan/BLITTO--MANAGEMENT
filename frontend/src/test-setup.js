import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach } from 'vitest'

// Node 25 ships an experimental global `localStorage` that shadows jsdom's and
// throws unless `--localstorage-file` is set. Install a simple in-memory
// implementation so component/API tests have a reliable Web Storage API.
class MemoryStorage {
  constructor() {
    this.store = new Map()
  }
  getItem(key) {
    return this.store.has(key) ? this.store.get(key) : null
  }
  setItem(key, value) {
    this.store.set(key, String(value))
  }
  removeItem(key) {
    this.store.delete(key)
  }
  clear() {
    this.store.clear()
  }
  key(i) {
    const keys = Array.from(this.store.keys())
    return i < 0 || i >= keys.length ? null : keys[i]
  }
  get length() {
    return this.store.size
  }
}

Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  value: new MemoryStorage(),
})

beforeEach(() => {
  globalThis.localStorage.clear()
})

// Unmount React trees and reset the DOM between tests.
afterEach(() => {
  cleanup()
})
