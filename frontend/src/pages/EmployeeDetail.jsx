import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/useAuth'
import { Badge, Card, ErrorBox, Skeleton } from '../components/ui'
import { api } from '../lib/api'
import { fmtDate } from '../lib/format'
import { EMPLOYMENT_LABEL } from '../lib/labels'
import { isHrAdmin } from '../lib/roles'

function Row({ label, children }) {
  return (
    <div className="grid grid-cols-3 gap-2 py-2 text-sm">
      <dt className="text-slate-500">{label}</dt>
      <dd className="col-span-2 break-words">{children || '-'}</dd>
    </div>
  )
}

export default function EmployeeDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [actionError, setActionError] = useState('')

  const { data: employee, isLoading, error, refetch } = useQuery({
    queryKey: ['employee', user.id, id],
    queryFn: async () => (await api.get(`/api/employees/${id}`)).data.data,
  })

  const toggle = useMutation({
    mutationFn: (action) => api.post(`/api/employees/${id}/${action}`),
    onSuccess: () => {
      setActionError('')
      queryClient.invalidateQueries({ queryKey: ['employee'] })
      queryClient.invalidateQueries({ queryKey: ['employees'] })
    },
    onError: (err) => setActionError(err.response?.data?.message ?? 'Aksi gagal. Coba lagi.'),
  })

  if (isLoading) return <Skeleton />
  if (error) return <ErrorBox error={error} onRetry={refetch} />

  const canManage = isHrAdmin(user.role)
  const sensitive = 'nik' in employee
  const region = employee.region
  const fullAddress = [employee.address, region?.village, region?.district, region?.regency, region?.province]
    .filter(Boolean)
    .join(', ')

  function handleToggle() {
    const message = employee.is_active
      ? 'Nonaktifkan karyawan ini? Akun loginnya juga akan dikunci.'
      : 'Aktifkan kembali karyawan ini? Akun loginnya tidak otomatis dibuka.'

    if (window.confirm(message)) toggle.mutate(employee.is_active ? 'deactivate' : 'activate')
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <Link to="/employees" className="text-sm text-slate-500 underline">
            ← Daftar karyawan
          </Link>
          <h1 className="text-xl font-semibold">{employee.full_name}</h1>
        </div>
        {canManage && (
          <div className="flex gap-2">
            <Link to={`/employees/${employee.id}/edit`} className="rounded border border-slate-300 bg-white px-3 py-2 text-sm">
              Ubah
            </Link>
            <button
              onClick={handleToggle}
              disabled={toggle.isPending}
              className="rounded bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-50"
            >
              {employee.is_active ? 'Nonaktifkan' : 'Aktifkan'}
            </button>
          </div>
        )}
      </div>

      {actionError && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {actionError}
        </p>
      )}

      <Card title="Data kerja">
        <dl className="divide-y divide-slate-100">
          <Row label="Nomor karyawan">{employee.employee_number}</Row>
          <Row label="Status">
            <Badge tone={employee.is_active ? 'good' : 'bad'}>{employee.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
          </Row>
          <Row label="Status kerja">{EMPLOYMENT_LABEL[employee.employment_status] ?? employee.employment_status}</Row>
          <Row label="Departemen">{employee.department?.name}</Row>
          <Row label="Jabatan">{employee.position?.name}</Row>
          <Row label="Atasan">{employee.manager?.full_name}</Row>
          <Row label="Tanggal masuk">{fmtDate(employee.hire_date)}</Row>
          <Row label="Tanggal keluar">{employee.end_date ? fmtDate(employee.end_date) : ''}</Row>
        </dl>
      </Card>

      {sensitive && (
        <Card title="Data pribadi">
          <dl className="divide-y divide-slate-100">
            <Row label="NIK">{employee.nik}</Row>
            <Row label="Telepon">{employee.phone}</Row>
            <Row label="Tanggal lahir">{employee.birth_date ? fmtDate(employee.birth_date) : ''}</Row>
            <Row label="Alamat">{fullAddress}</Row>
          </dl>
        </Card>
      )}
    </div>
  )
}
