import { describe, it, expect, beforeEach, vi } from 'vitest'
import { api, getToken, setToken, clearToken } from '../api.js'

describe('api token helpers', () => {
  beforeEach(() => {
    localStorage.removeItem('blitto_token')
  })

  it('setToken then getToken round-trips', () => {
    setToken('abc123')
    expect(getToken()).toBe('abc123')
  })

  it('clearToken removes the token', () => {
    setToken('abc123')
    clearToken()
    expect(getToken()).toBeNull()
  })
})

describe('api requests', () => {
  beforeEach(() => {
    localStorage.removeItem('blitto_token')
    vi.restoreAllMocks()
  })

  it('login POSTs credentials to /api/auth/login', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ access_token: 't', token_type: 'bearer' }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const result = await api.login('a@b.c', 'pw')

    expect(fetchMock).toHaveBeenCalledWith('/api/auth/login', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ email: 'a@b.c', password: 'pw' }),
    }))
    expect(result.access_token).toBe('t')
  })

  it('includes the Authorization header when a token exists', async () => {
    setToken('my-token')
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ email: 'a@b.c' }),
    })
    vi.stubGlobal('fetch', fetchMock)

    await api.me()

    const [, opts] = fetchMock.mock.calls[0]
    expect(opts.headers.Authorization).toBe('Bearer my-token')
  })

  it('omits the Authorization header when no token', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => [],
    })
    vi.stubGlobal('fetch', fetchMock)

    await api.applications()

    const [, opts] = fetchMock.mock.calls[0]
    expect(opts.headers.Authorization).toBeUndefined()
  })

  it('throws an Error with the detail message on a failed response', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      statusText: 'Unauthorized',
      json: async () => ({ detail: 'Invalid credentials' }),
    })
    vi.stubGlobal('fetch', fetchMock)

    await expect(api.login('a@b.c', 'bad')).rejects.toThrow('Invalid credentials')
  })

  it('falls back to statusText when there is no JSON detail', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Server Error',
      json: async () => {
        throw new Error('not json')
      },
    })
    vi.stubGlobal('fetch', fetchMock)

    await expect(api.me()).rejects.toThrow('Server Error')
  })

  it('returns null for 204 No Content', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 204,
      json: async () => {
        throw new Error('should not be called')
      },
    })
    vi.stubGlobal('fetch', fetchMock)

    expect(await api.applications()).toBeNull()
  })
})
