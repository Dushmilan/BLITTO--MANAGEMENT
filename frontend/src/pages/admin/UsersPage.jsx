import { useState, useEffect } from 'react'
import { api } from '../../api.js'
import Badge from '../../components/ui/Badge.jsx'
import Card from '../../components/ui/Card.jsx'
import DataTable from '../../components/ui/DataTable.jsx'
import SearchInput from '../../components/ui/SearchInput.jsx'

const ROLE_COLORS = {
  admin: 'bg-copper/10 text-copper',
  attorney: 'bg-status-filed/10 text-status-filed',
  paralegal: 'bg-status-examination/10 text-status-examination',
  inventor: 'bg-status-granted/10 text-status-granted',
}

export default function UsersPage() {
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  useEffect(() => {
    loadUsers()
  }, [])

  async function loadUsers() {
    setLoading(true)
    try {
      const data = await api.users()
      setUsers(data || [])
    } catch (err) {
      console.error(err)
    } finally {
      setLoading(false)
    }
  }

  const filtered = users.filter((u) => {
    if (!search) return true
    const s = search.toLowerCase()
    return (
      u.email?.toLowerCase().includes(s) ||
      u.role?.toLowerCase().includes(s)
    )
  })

  const columns = [
    {
      key: 'email',
      label: 'Email',
      render: (val) => <span className="font-medium font-sans">{val}</span>,
    },
    {
      key: 'role',
      label: 'Role',
      render: (val) => (
        <span className={`inline-flex items-center px-xs py-xxs rounded text-caption-bold font-sans capitalize ${ROLE_COLORS[val] || 'bg-ivory-200 text-slate'}`}>
          {val}
        </span>
      ),
    },
    {
      key: 'created_at',
      label: 'Created',
      render: (val) => (
        <span className="font-mono text-body-sm text-steel">
          {val ? new Date(val).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '\u2014'}
        </span>
      ),
    },
  ]

  return (
    <div className="space-y-xxl">
      <div className="animate-slide-up">
        <h1 className="font-display text-heading-1 text-ink mb-xs">Users</h1>
        <p className="text-body-md text-steel font-sans">
          Manage user accounts and roles
        </p>
      </div>

      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-lg animate-slide-up stagger-2">
        <p className="text-body-md text-steel font-sans">
          {users.length} {users.length === 1 ? 'user' : 'users'} registered
        </p>
      </div>

      <div className="animate-slide-up stagger-2">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder="Search users..."
          className="max-w-[400px]"
        />
      </div>

      <div className="animate-slide-up stagger-3">
        {loading ? (
          <div className="flex items-center justify-center py-section">
            <div className="flex items-center gap-sm text-steel">
              <svg className="animate-spin" width="20" height="20" viewBox="0 0 20 20" fill="none">
                <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" opacity="0.3" />
                <path d="M10 2a8 8 0 015.66 2.34" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
              </svg>
              <span className="font-sans text-body-sm">Loading users...</span>
            </div>
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={filtered}
            emptyMessage="No users found."
          />
        )}
      </div>
    </div>
  )
}
