import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../auth/useAuth'
import EmployeePicker from '../../components/EmployeePicker'
import { Empty, ErrorBox, Field, Pagination, Skeleton, inputClass } from '../../components/ui'
import { api } from '../../lib/api'
import { useLeaveTypes } from '../../lib/leave'

function SetBalanceForm({ year, onClose }) {
  const queryClient = useQueryClient()
  const types = useLeaveTypes()
  const [form, setForm] = useState({ employee_id: '', leave_type_id: '', year: String(year), entitled_days: '' })

  const save = useMutation({
    mutationFn: (body) => api.put('/api/leave-balances', body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-balances'] })
      onClose()
    },
  })

  const validation = save.error?.response?.status === 422
  const errors = validation ? (save.error.response.data.errors ?? {}) : {}
  const general =
    save.isError && !validation ? (save.error?.response?.data?.message ?? 'Gagal menyimpan. Coba lagi.') : ''
  const err = (field) => errors[field]?.[0]
  const set = (field) => (e) => setForm({ ...form, [field]: e.target.value })

  function submit(e) {
    e.preventDefault()
    save.mutate({
      employee_id: Number(form.employee_id),
      leave_type_id: Number(form.leave_type_id),
      year: Number(form.year),
      entitled_days: Number(form.entitled_days),
    })
  }

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">Atur jatah cuti</h2>

      {general && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {general}
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <EmployeePicker value={form.employee_id} onChange={(id) => setForm({ ...form, employee_id: id })} error={err('employee_id')} />
        <Field label="Jenis cuti" error={err('leave_type_id')}>
          <select className={inputClass} value={form.leave_type_id} onChange={set('leave_type_id')} required>
            <option value="">Pilih jenis cuti</option>
            {(types.data ?? []).map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Tahun" error={err('year')}>
          <input type="number" min="2000" max="2100" className={inputClass} value={form.year} onChange={set('year')} required />
        </Field>
        <Field label="Jatah (hari)" error={err('entitled_days')}>
          <input type="number" min="0" max="365" className={inputClass} value={form.entitled_days} onChange={set('entitled_days')} required />
        </Field>
      </div>

      <div className="flex gap-2">
        <button type="submit" disabled={save.isPending} className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
          {save.isPending ? 'Menyimpan...' : 'Simpan'}
        </button>
        <button type="button" onClick={onClose} className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
          Batal
        </button>
      </div>
    </form>
  )
}

export default function Balances({ canManage }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const thisYear = new Date().getFullYear()
  const [year, setYear] = useState(thisYear)
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [notice, setNotice] = useState('')
  const [actionError, setActionError] = useState('')
  const years = [thisYear - 2, thisYear - 1, thisYear, thisYear + 1]
  const showEmployee = user.role !== 'karyawan'

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['leave-balances', user.id, { year, page }],
    queryFn: async () => (await api.get('/api/leave-balances', { params: { year, page } })).data,
    placeholderData: keepPreviousData,
  })

  const generate = useMutation({
    mutationFn: () => api.post('/api/leave-balances/generate', { year }),
    onSuccess: (res) => {
      setActionError('')
      setNotice(`${res.data.created} jatah baru dibuat untuk ${year}.`)
      queryClient.invalidateQueries({ queryKey: ['leave-balances'] })
    },
    onError: (err) => {
      setNotice('')
      setActionError(err.response?.data?.message ?? 'Gagal membuat jatah. Coba lagi.')
    },
  })

  function handleGenerate() {
    const message = `Buat jatah cuti ${year} untuk semua karyawan aktif dan jenis cuti aktif? Jatah yang sudah ada tidak diubah.`

    if (window.confirm(message)) generate.mutate()
  }

  const rows = data?.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-end gap-3">
          <Field label="Tahun">
            <select
              className={inputClass}
              value={year}
              onChange={(e) => {
                setYear(Number(e.target.value))
                setPage(1)
                setNotice('')
              }}
            >
              {years.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </select>
          </Field>
          {isFetching && <span className="pb-2 text-xs text-slate-500">Memperbarui...</span>}
        </div>

        {canManage && (
          <div className="flex gap-2">
            <button onClick={handleGenerate} disabled={generate.isPending} className="rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-50">
              Buat jatah {year}
            </button>
            {!showForm && (
              <button onClick={() => setShowForm(true)} className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
                Atur jatah
              </button>
            )}
          </div>
        )}
      </div>

      {notice && <p className="rounded bg-green-50 p-3 text-sm text-green-800">{notice}</p>}
      {actionError && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {actionError}
        </p>
      )}

      {showForm && canManage && <SetBalanceForm year={year} onClose={() => setShowForm(false)} />}

      {isLoading && <Skeleton />}
      {error && !data && <ErrorBox error={error} onRetry={refetch} />}

      {data && (
        <>
          {rows.length === 0 ? (
            <Empty>Belum ada jatah cuti untuk tahun {year}.</Empty>
          ) : (
            <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    {showEmployee && <th className="px-3 py-2">Karyawan</th>}
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Jatah</th>
                    <th className="px-3 py-2">Terpakai</th>
                    <th className="px-3 py-2">Menunggu</th>
                    <th className="px-3 py-2">Sisa</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-slate-100 last:border-0">
                      {showEmployee && (
                        <td className="px-3 py-2">
                          {row.employee?.full_name}
                          <span className="block text-xs text-slate-500">{row.employee?.employee_number}</span>
                        </td>
                      )}
                      <td className="px-3 py-2">{row.leave_type?.name}</td>
                      <td className="px-3 py-2">{row.entitled_days}</td>
                      <td className="px-3 py-2">{row.used_days ?? '-'}</td>
                      <td className="px-3 py-2">{row.pending_days ?? '-'}</td>
                      <td className={`px-3 py-2 font-medium ${row.remaining_days < 0 ? 'text-red-600' : ''}`}>
                        {row.remaining_days ?? '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <Pagination meta={data.meta} onPage={setPage} />
        </>
      )}
    </div>
  )
}
