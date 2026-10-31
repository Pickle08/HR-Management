import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from './AuthContext'

export default function ProtectedRoute() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) return <p className="p-6 text-slate-500">Memuat...</p>
  if (!user) return <Navigate to="/login" state={{ from: location }} replace />

  return <Outlet />
}
