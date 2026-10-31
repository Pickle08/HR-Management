import { useAuth } from '../auth/AuthContext'

export default function Dashboard() {
  const { user, logout } = useAuth()

  return (
    <div className="p-6">
      <h1 className="text-xl font-semibold">Halo, {user.name}</h1>
      <p className="text-slate-600">Role: {user.role}</p>
      <button onClick={logout} className="mt-4 rounded bg-slate-900 px-4 py-2 text-white">
        Keluar
      </button>
    </div>
  )
}
