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

async function request(path, { method = 'GET', body, params } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  const token = getToken()
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
    throw new Error(detail)
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

  // Docketing / Deadlines
  deadlines: (applicationId) =>
    request('/deadlines', { params: applicationId ? { application_id: applicationId } : {} }),
  addDeadline: (appId, deadlineType, dueDate) =>
    request(`/applications/${appId}/deadlines`, {
      method: 'POST',
      params: { type: deadlineType, due_date: dueDate },
    }),

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
  deadlineReport: () => request('/analytics/deadlines'),

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

  // Vault
  vaultStatus: () => request('/vault/status'),
  vaultUnlock: (masterKey) =>
    request('/vault/unlock', { method: 'POST', body: { master_key: masterKey } }),
  vaultLock: () => request('/vault/lock', { method: 'POST' }),
}
