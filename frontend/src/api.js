const TOKEN_KEY = 'blitto_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

// --- Token lifecycle (issue #41) ---

// Seconds of remaining lifetime below which a token counts as expired.
const EXPIRY_SKEW_SEC = 300

export function tokenExp(token) {
  try {
    const [, payload] = token.split('.')
    return JSON.parse(atob(payload)).exp ?? null
  } catch {
    return null
  }
}

export function isTokenExpired(token, skewSec = EXPIRY_SKEW_SEC) {
  if (!token) return true
  const exp = tokenExp(token)
  if (!exp) return true
  return Date.now() / 1000 >= exp - skewSec
}

// Single-flight refresh: concurrent requests share one /auth/refresh call.
let refreshPromise = null

export function refreshToken() {
  if (!refreshPromise) {
    refreshPromise = (async () => {
      const token = getToken()
      const res = await fetch('/api/auth/refresh', {
        method: 'POST',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      })
      if (!res.ok) throw new Error('Token refresh failed')
      const data = await res.json()
      setToken(data.access_token)
      return data.access_token
    })().finally(() => {
      refreshPromise = null
    })
  }
  return refreshPromise
}

function announceUnauthorized() {
  clearToken()
  // App.jsx listens and routes back to /login — no silent failures.
  window.dispatchEvent(new CustomEvent('blitto:unauthorized'))
}

async function request(path, { method = 'GET', body, params } = {}) {
  let token = getToken()
  // Proactive refresh: never send a token that dies mid-session.
  if (token && isTokenExpired(token)) {
    try {
      token = await refreshToken()
    } catch {
      // Fall through with the stale token; the 401 path below handles it.
    }
  }
  const headers = { 'Content-Type': 'application/json' }
  if (token) headers['Authorization'] = `Bearer ${token}`

  let url = `/api${path}`
  if (params) {
    const qs = new URLSearchParams()
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) qs.set(k, v)
    })
    const qsStr = qs.toString()
    if (qsStr) url += `?${qsStr}`
  }

  const res = await fetch(url, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })

  if (!res.ok) {
    let detail = res.statusText
    try {
      detail = (await res.json()).detail ?? detail
    } catch {
      /* ignore */
    }
    // Expired/invalid session: drop it and tell the app to route to login.
    if (res.status === 401 && path !== '/auth/login') {
      announceUnauthorized()
    }
    // Attach the HTTP status so callers can distinguish error types
    // (401 wrong credentials vs 403 forbidden vs 429 throttled, ...).
    const error = new Error(detail)
    error.status = res.status
    throw error
  }
  if (res.status === 204) return null
  return res.json()
}

export const api = {
  // Auth
  login: (email, password) =>
    request('/auth/login', { method: 'POST', body: { email, password } }),
  me: () => request('/auth/me'),
  register: (data) =>
    request('/auth/register', { method: 'POST', body: data }),

  // Applications
  applications: () => request('/applications'),
  createApplication: (data) =>
    request('/applications', { method: 'POST', body: data }),
  changeStatus: (appId, newStatus) =>
    request(`/applications/${appId}/status`, {
      method: 'POST',
      params: { new_status: newStatus },
    }),
  updateApplication: (appId, fields) =>
    request(`/applications/${appId}`, { method: 'PATCH', body: fields }),
  applicationHistory: (appId) =>
    request(`/applications/${appId}/history`),

  // Documents
  documents: (appId) =>
    request(`/applications/${appId}/documents`),
  uploadDocument: (appId, filename, body) => {
    const token = getToken()
    return fetch(`/api/applications/${appId}/documents?filename=${encodeURIComponent(filename)}`, {
      method: 'POST',
      headers: token ? { 'Authorization': `Bearer ${token}` } : {},
      body,
    }).then((res) => {
      if (!res.ok) throw new Error('Upload failed')
      return res.json()
    })
  },
  downloadDocument: async (appId, docId) => {
    const token = getToken()
    const res = await fetch(`/api/applications/${appId}/documents/${docId}/download`, {
      headers: token ? { 'Authorization': `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error('Download failed')
    const blob = await res.blob()
    const disposition = res.headers.get('Content-Disposition') || ''
    const match = disposition.match(/filename="?(.+?)"?$/)
    const filename = match ? match[1] : 'document.bin'
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  },
  deleteDocument: (appId, docId) =>
    request(`/applications/${appId}/documents/${docId}`, { method: 'DELETE' }),

  // Prosecution / Office Actions
  officeActions: (appId) =>
    request(`/applications/${appId}/office-actions`),
  receiveOfficeAction: (appId, kind, body) =>
    request(`/applications/${appId}/office-actions`, {
      method: 'POST',
      params: { kind, body },
    }),

  // Analytics
  portfolioSummary: () => request('/analytics/portfolio'),

  // Notifications (for current user)
  notifications: () => request('/notifications'),

  // User Management (admin only)
  users: () => request('/users'),

  // Send notification to patent inventor (admin only)
  sendNotification: (appId, subject, body) =>
    request(`/applications/${appId}/notify`, {
      method: 'POST',
      params: { subject, body },
    }),

  // Send status change notification to inventor
  notifyStatusChange: (recipientEmail, applicationRef, newStatus) =>
    request('/notify/status-change', {
      method: 'POST',
      params: { recipient_email: recipientEmail, application_ref: applicationRef, new_status: newStatus },
    }),

  // Filing Workflow
  markFiled: (appId) =>
    request(`/admin/filing/${appId}/file`, { method: 'POST' }),
  acknowledgeNipo: (appId) =>
    request(`/admin/filing/${appId}/acknowledge`, { method: 'POST' }),
  recordDefectSheet: (appId, sheetNumber, description) =>
    request(`/admin/filing/${appId}/defect-sheets`, {
      method: 'POST',
      params: { sheet_number: sheetNumber, description },
    }),
  listDefectSheets: (appId) =>
    request(`/admin/filing/${appId}/defect-sheets`),
  markGranted: (appId, patentNumber) =>
    request(`/admin/filing/${appId}/grant`, {
      method: 'POST',
      params: { patent_number: patentNumber },
    }),
  markRejected: (appId) =>
    request(`/admin/filing/${appId}/reject`, { method: 'POST' }),
  filingStatus: (appId) =>
    request(`/admin/filing/${appId}/status`),

  // Vault
  vaultStatus: () => request('/vault/status'),
  vaultUnlock: (masterKey) =>
    request('/vault/unlock', { method: 'POST', body: { master_key: masterKey } }),
  vaultLock: () => request('/vault/lock', { method: 'POST' }),
}
