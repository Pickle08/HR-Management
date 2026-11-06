import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import RequireRole from './RequireRole'

const auth = vi.hoisted(() => ({ role: 'hr' }))

vi.mock('./useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: auth.role } }) }))

function renderGuard(roles) {
  return render(
    <MemoryRouter>
      <Routes>
        <Route element={<RequireRole roles={roles} />}>
          <Route path="/" element={<p>isi halaman</p>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  )
}

describe('RequireRole', () => {
  it('renders the page for an allowed role', () => {
    auth.role = 'hr'
    renderGuard(['hr', 'super_admin'])

    expect(screen.getByText('isi halaman')).toBeInTheDocument()
  })

  it('shows the forbidden screen for other roles, and for an empty role list', () => {
    auth.role = 'finance'
    renderGuard(['hr', 'super_admin'])

    expect(screen.getByText('Akses ditolak')).toBeInTheDocument()
    expect(screen.queryByText('isi halaman')).not.toBeInTheDocument()

    renderGuard([])

    expect(screen.getAllByText('Akses ditolak')).toHaveLength(2)
  })
})
