import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setToken, getToken, clearToken, isTokenExpired, api } from './api.js'

function jwt(exp) {
  const b64 = (o) => btoa(JSON.stringify(o)).replace(/=/g, '')
  return `${b64({ alg: 'HS256' })}.${b64({ exp })}.${b64({ sig: 1 })}`
}

describe('api token handling (issue #41)', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.unstubAllGlobals()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  it('detects expired, missing and malformed tokens', () => {
    expect(isTokenExpired(null)).toBe(true)
    expect(isTokenExpired('garbage')).toBe(true)
    expect(isTokenExpired(jwt(Date.now() / 1000 - 10))).toBe(true)
    expect(isTokenExpired(jwt(Date.now() / 1000 + 3600))).toBe(false)
  })

  it('treats tokens expiring within the skew window as expired', () => {
    expect(isTokenExpired(jwt(Date.now() / 1000 + 60), 300)).toBe(true)
    expect(isTokenExpired(jwt(Date.now() / 1000 + 600), 300)).toBe(false)
  })

  it('refreshes proactively before an expiring token is used', async () => {
    setToken(jwt(Date.now() / 1000 + 60))
    const fresh = jwt(Date.now() / 1000 + 3600)
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ access_token: fresh }) })
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ ok: true }) })
    vi.stubGlobal('fetch', fetchMock)
    await api.me()
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock.mock.calls[0][0]).toBe('/api/auth/refresh')
    expect(getToken()).toBe(fresh)
  })

  it('clears the session and announces on 401', async () => {
    setToken(jwt(Date.now() / 1000 + 3600))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 401, statusText: 'Unauthorized', json: async () => ({ detail: 'Invalid or expired token' }) }))
    const onUnauthorized = vi.fn()
    window.addEventListener('blitto:unauthorized', onUnauthorized)
    try {
      await expect(api.me()).rejects.toMatchObject({ status: 401 })
    } finally {
      window.removeEventListener('blitto:unauthorized', onUnauthorized)
    }
    expect(getToken()).toBeNull()
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
  })
})
