import { useState } from 'react'
import { Link } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/useAuth'
import { Badge, Empty, ErrorBox, Pagination, Skeleton, inputClass } from '../components/ui'
import { api } from '../lib/api'
import { useDebounced } from '../lib/hooks'
import { EMPLOYMENT_LABEL } from '../lib/labels'
import { isHrAdmin } from '../lib/roles'

export default function Employees() {
  const { user } = useAuth()
  const [input, setInput] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(input.trim())

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    // user.id di key: daftar satu user tidak pernah dipakai ulang untuk user lain.
    queryKey: ['employees', user.id, { search, page }],
    queryFn: async () =>
      (await api.get('/api/employees', { params: { search: search || undefined, page } })).data,
    placeholderData: keepPreviousData,
  })

  if (isLoading) return <Skeleton />
  if (error && !data) return <ErrorBox error={error} onRetry={refetch} />

  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Karyawan</h1>
        {isHrAdmin(user.role) && (
          <Link to="/employees/new" className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
            Tambah karyawan
          </Link>
        )}
      </div>

      <div className="flex items-center gap-3">
        <input
          type="search"
          aria-label="Cari karyawan"
          placeholder="Cari nama atau nomor karyawan"
          value={input}
          onChange={(e) => {
            setInput(e.target.value)
            setPage(1)
          }}
          className={`${inputClass} max-w-sm`}
        />
        {isFetching && <span className="text-xs text-slate-500">Memperbarui...</span>}
      </div>

      {error && <ErrorBox error={error} onRetry={refetch} />}

      {rows.length === 0 ? (
        <Empty>Tidak ada karyawan yang cocok.</Empty>
      ) : (
        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Nomor</th>
                <th className="px-3 py-2">Nama</th>
                <th className="px-3 py-2">Departemen</th>
                <th className="px-3 py-2">Jabatan</th>
                <th className="px-3 py-2">Status kerja</th>
                <th className="px-3 py-2">Aktif</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((employee) => (
                <tr key={employee.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2">{employee.employee_number}</td>
                  <td className="px-3 py-2">
                    <Link to={`/employees/${employee.id}`} className="font-medium text-slate-900 underline">
                      {employee.full_name}
                    </Link>
                  </td>
                  <td className="px-3 py-2">{employee.department?.name ?? '-'}</td>
                  <td className="px-3 py-2">{employee.position?.name ?? '-'}</td>
                  <td className="px-3 py-2">
                    {EMPLOYMENT_LABEL[employee.employment_status] ?? employee.employment_status}
                  </td>
                  <td className="px-3 py-2">
                    <Badge tone={employee.is_active ? 'good' : 'bad'}>
                      {employee.is_active ? 'Aktif' : 'Nonaktif'}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination meta={data.meta} onPage={setPage} />
    </div>
  )
}
