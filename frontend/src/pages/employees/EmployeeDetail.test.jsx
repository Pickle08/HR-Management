import { fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../../lib/api'
import { renderWithProviders } from '../../test/utils'
import EmployeeDetail from './EmployeeDetail'

const auth = vi.hoisted(() => ({ role: 'hr' }))

vi.mock('../../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../auth/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: auth.role } }) }))

const base = {
  id: 5,
  employee_number: 'EMP-005',
  full_name: 'Kiki Karyawan',
  employment_status: 'tetap',
  hire_date: '2024-01-15',
  end_date: null,
  is_active: true,
  manager_id: 1,
  department: { id: 1, name: 'Operasional' },
  position: { id: 1, name: 'Staff' },
  manager: { id: 1, employee_number: 'EMP-001', full_name: 'Budi Atasan' },
}

const personal = {
  nik: '3201010101010001',
  phone: '081234567890',
  birth_date: '1995-05-20',
  address: 'Jl. Contoh No. 1',
}

function show(employee) {
  api.get.mockResolvedValue({ data: { data: employee } })

  return renderWithProviders(<EmployeeDetail />, { route: '/employees/5', path: '/employees/:id' })
}

describe('EmployeeDetail', () => {
  beforeEach(() => {
    api.get.mockReset()
    api.post.mockReset()
  })

  afterEach(() => vi.restoreAllMocks())

  it('masks nik and phone until revealed', async () => {
    auth.role = 'hr'
    show({ ...base, ...personal })

    expect(await screen.findByText('3201••••••••0001')).toBeInTheDocument()
    expect(screen.queryByText('3201010101010001')).not.toBeInTheDocument()

    fireEvent.click(screen.getAllByRole('button', { name: 'Tampilkan' })[0])

    expect(screen.getByText('3201010101010001')).toBeInTheDocument()
  })

  it('shows no personal card when the backend did not send personal fields', async () => {
    auth.role = 'atasan'
    show(base)

    expect(await screen.findByText('Data kepegawaian')).toBeInTheDocument()
    expect(screen.getByText('Budi Atasan')).toBeInTheDocument()
    expect(screen.queryByText('Data pribadi')).not.toBeInTheDocument()
  })

  it('offers no activate/deactivate button to roles that cannot manage', async () => {
    auth.role = 'atasan'
    show(base)

    await screen.findByText('Kiki Karyawan')

    expect(screen.queryByRole('button', { name: 'Nonaktifkan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Aktifkan' })).not.toBeInTheDocument()
  })

  it('asks for confirmation, then deactivates and updates the screen', async () => {
    auth.role = 'hr'
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    api.post.mockResolvedValue({ data: { data: { ...base, is_active: false } } })

    show(base)

    const button = await screen.findByRole('button', { name: 'Nonaktifkan' })

    fireEvent.click(button) // dibatalkan
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(api.post).not.toHaveBeenCalled()

    fireEvent.click(button) // disetujui
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/api/employees/5/deactivate'))

    expect(await screen.findByText('Nonaktif')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aktifkan' })).toBeInTheDocument()
  })

  it('shows the backend refusal message when deactivation is rejected', async () => {
    auth.role = 'hr'
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    api.post.mockRejectedValue({ response: { status: 422, data: { message: 'Karyawan masih punya tim aktif.' } } })

    show(base)

    fireEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Karyawan masih punya tim aktif.')
  })
})
