import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { visibleNav } from '../auth/nav'
import { ROLE_LABEL } from '../lib/labels'

export default function Layout() {
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)

  const linkClass = ({ isActive }) =>
    `block rounded px-3 py-2 text-sm ${
      isActive ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-200'
    }`

  const nav = (
    <nav className="space-y-1" aria-label="Menu utama">
      {visibleNav(user.role).map((item) => (
        <NavLink
          key={item.path}
          to={item.path}
          end={item.path === '/'}
          onClick={() => setOpen(false)}
          className={linkClass}
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  )

  return (
    <div className="min-h-screen bg-slate-100 md:flex">
      <aside className="hidden w-60 shrink-0 border-r border-slate-200 bg-white p-4 md:block">
        <p className="mb-4 text-lg font-semibold">HRM</p>
        {nav}
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
          <button
            onClick={() => setOpen((value) => !value)}
            aria-expanded={open}
            className="rounded border border-slate-300 px-3 py-1 text-sm md:hidden"
          >
            Menu
          </button>
          <span className="hidden md:block" />

          <div className="flex min-w-0 items-center gap-3 text-sm">
            <div className="min-w-0 text-right">
              <p className="truncate font-medium">{user.name}</p>
              <p className="text-xs text-slate-500">{ROLE_LABEL[user.role] ?? user.role}</p>
            </div>
            <button onClick={logout} className="rounded bg-slate-900 px-3 py-1.5 text-white">
              Keluar
            </button>
          </div>
        </header>

        {open && <div className="border-b border-slate-200 bg-white p-3 md:hidden">{nav}</div>}

        <main className="flex-1 p-4 md:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
