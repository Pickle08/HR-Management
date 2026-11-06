import { Outlet } from 'react-router-dom'
import { useAuth } from './useAuth'

function Forbidden() {
  return (
    <div className="rounded-lg bg-white p-6 text-center shadow-sm">
      <p className="text-lg font-semibold">Akses ditolak</p>
      <p className="mt-1 text-sm text-slate-600">Peran Anda tidak punya akses ke halaman ini.</p>
    </div>
  )
}

export default function RequireRole({ roles }) {
  const { user } = useAuth()

  return roles.includes(user.role) ? <Outlet /> : <Forbidden />
}
