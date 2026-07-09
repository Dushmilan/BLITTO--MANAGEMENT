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
}
