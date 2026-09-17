import { Navigate, useLocation } from 'react-router-dom'

const STAFF_ROLES = ['admin', 'attorney', 'paralegal']

// Role-guard for routes. Unauthenticated users go to /login; users with the
// wrong role go to their own home (inventors -> /user, staff -> /admin)
// instead of seeing a blank page or someone else's UI.
export default function ProtectedRoute({ user, allowedRoles, children }) {
  const location = useLocation()

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }
  if (!allowedRoles.includes(user.role)) {
    const home = STAFF_ROLES.includes(user.role) ? '/admin' : '/user'
    return <Navigate to={home} replace />
  }
  return children
}
