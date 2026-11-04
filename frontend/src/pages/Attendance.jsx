import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/useAuth'
import EmployeePicker from '../components/EmployeePicker'
import { Badge, Empty, ErrorBox, Field, Pagination, Skeleton, inputClass } from '../components/ui'
import { api } from '../lib/api'
import { fmtDate, monthStartIso, todayIso } from '../lib/format'
import { ATTENDANCE_LABEL } from '../lib/labels'
import { isHrAdmin } from '../lib/roles'

const TONE = { hadir: 'good', alpa: 'bad' }

function AttendanceForm({ record, onClose }) {
  const queryClient = useQueryClient()
  const editing = Boolean(record)
  const [form, setForm] = useState({
    employee_id: record?.employee?.id ? String(record.employee.id) : '',
    date: record ? record.date.slice(0, 10) : todayIso(),
    status: record?.status ?? 'hadir',
    check_in: record?.check_in ?? '',
    check_out: record?.check_out ?? '',
    note: record?.note ?? '',
  })

  const save = useMutation({
    mutationFn: (body) =>
      editing ? api.put(`/api/attendances/${record.id}`, body) : api.post('/api/attendances', body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendances'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      onClose()
    },
  })

  const validation = save.error?.response?.status === 422
  const errors = validation ? (save.error.response.data.errors ?? {}) : {}
  const general =
    save.isError && !validation ? (save.error?.response?.data?.message ?? 'Gagal menyimpan. Coba lagi.') : ''
  const err = (field) => errors[field]?.[0]
  const set = (field) => (e) => setForm({ ...form, [field]: e.target.value })
  const hadir = form.status === 'hadir'

  function submit(e) {
    e.preventDefault()

    const body = {
      status: form.status,
      check_in: hadir && form.check_in ? form.check_in : null,
      check_out: hadir && form.check_out ? form.check_out : null,
      note: form.note.trim() || null,
    }

    if (!editing) {
      body.employee_id = Number(form.employee_id)
      body.date = form.date
    }

    save.mutate(body)
  }

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">{editing ? 'Koreksi absensi' : 'Catat absensi'}</h2>

      {general && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {general}
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {editing ? (
          <div className="text-sm">
            <p className="text-slate-500">Karyawan</p>
            <p>
              {record.employee?.employee_number} · {record.employee?.full_name}
            </p>
            <p className="mt-1 text-slate-500">Tanggal</p>
            <p>{fmtDate(record.date)}</p>
          </div>
        ) : (
          <>
            <EmployeePicker value={form.employee_id} onChange={(id) => setForm({ ...form, employee_id: id })} error={err('employee_id')} />
            <Field label="Tanggal" error={err('date')}>
              <input type="date" max={todayIso()} className={inputClass} value={form.date} onChange={set('date')} required />
            </Field>
          </>
        )}

        <Field label="Status" error={err('status')}>
          <select className={inputClass} value={form.status} onChange={set('status')} required>
            {Object.entries(ATTENDANCE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </Field>

        {hadir && (
          <>
            <Field label="Jam masuk" error={err('check_in')}>
              <input type="time" className={inputClass} value={form.check_in} onChange={set('check_in')} />
            </Field>
            <Field label="Jam pulang" error={err('check_out')}>
              <input type="time" className={inputClass} value={form.check_out} onChange={set('check_out')} />
            </Field>
          </>
        )}
      </div>

      <Field label="Catatan" error={err('note')} hint="Bisa berisi info sensitif; hanya HR dan pemilik yang melihatnya.">
        <textarea className={inputClass} rows={2} maxLength={500} value={form.note} onChange={set('note')} />
      </Field>

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

export default function Attendance() {
  const { user } = useAuth()
  const [from, setFrom] = useState(monthStartIso())
  const [to, setTo] = useState(todayIso())
  const [page, setPage] = useState(1)
  const [formState, setFormState] = useState(null)
  const canManage = isHrAdmin(user.role)
  const rangeInvalid = Boolean(from && to && to < from)

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['attendances', user.id, { from, to, page }],
    queryFn: async () =>
      (await api.get('/api/attendances', { params: { from: from || undefined, to: to || undefined, page } })).data,
    enabled: !rangeInvalid,
    placeholderData: keepPreviousData,
  })

  const rows = data?.data ?? []
  const showEmployee = user.role !== 'karyawan'
  const showNote = rows.some((row) => 'note' in row)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Absensi</h1>
        {canManage && !formState && (
          <button onClick={() => setFormState({ record: null })} className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
            Catat absensi
          </button>
        )}
      </div>

      {formState && (
        <AttendanceForm
          key={formState.record?.id ?? 'new'}
          record={formState.record}
          onClose={() => setFormState(null)}
        />
      )}

      <div className="flex flex-wrap items-end gap-3">
        <Field label="Dari">
          <input
            type="date"
            className={inputClass}
            value={from}
            onChange={(e) => {
              setFrom(e.target.value)
              setPage(1)
            }}
          />
        </Field>
        <Field label="Sampai">
          <input
            type="date"
            className={inputClass}
            value={to}
            onChange={(e) => {
              setTo(e.target.value)
              setPage(1)
            }}
          />
        </Field>
        {isFetching && <span className="pb-2 text-xs text-slate-500">Memperbarui...</span>}
      </div>

      {rangeInvalid && (
        <p className="rounded bg-amber-50 p-3 text-sm text-amber-800" role="alert">
          Tanggal akhir tidak boleh sebelum tanggal awal.
        </p>
      )}

      {!rangeInvalid && isLoading && <Skeleton />}
      {!rangeInvalid && error && !data && <ErrorBox error={error} onRetry={refetch} />}

      {data && !rangeInvalid && (
        <>
          {rows.length === 0 ? (
            <Empty>Tidak ada catatan absensi pada rentang ini.</Empty>
          ) : (
            <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Tanggal</th>
                    {showEmployee && <th className="px-3 py-2">Karyawan</th>}
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Masuk</th>
                    <th className="px-3 py-2">Pulang</th>
                    {showNote && <th className="px-3 py-2">Catatan</th>}
                    {canManage && <th className="px-3 py-2" />}
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-3 py-2">{fmtDate(row.date)}</td>
                      {showEmployee && (
                        <td className="px-3 py-2">
                          {row.employee?.full_name}
                          <span className="block text-xs text-slate-500">{row.employee?.employee_number}</span>
                        </td>
                      )}
                      <td className="px-3 py-2">
                        <Badge tone={TONE[row.status] ?? 'default'}>{ATTENDANCE_LABEL[row.status] ?? row.status}</Badge>
                      </td>
                      <td className="px-3 py-2">{row.check_in ?? '-'}</td>
                      <td className="px-3 py-2">{row.check_out ?? '-'}</td>
                      {showNote && <td className="px-3 py-2">{row.note ?? '-'}</td>}
                      {canManage && (
                        <td className="px-3 py-2 text-right">
                          <button onClick={() => setFormState({ record: row })} className="text-slate-900 underline">
                            Koreksi
                          </button>
                        </td>
                      )}
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
