import { useState } from 'react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../../auth/useAuth'
import { Badge, Dialog, Empty, ErrorBox, Field, Pagination, Skeleton, inputClass } from '../../components/ui'
import { api } from '../../lib/api'
import { fmtDate, todayIso } from '../../lib/format'
import { useLeaveTypes } from '../../lib/leave'
import { LEAVE_STATUS_LABEL, LEAVE_STATUS_TONE } from '../../lib/labels'

function refresh(queryClient) {
  queryClient.invalidateQueries({ queryKey: ['leave-requests'] })
  queryClient.invalidateQueries({ queryKey: ['leave-balances'] })
  queryClient.invalidateQueries({ queryKey: ['dashboard'] })
}

function SubmitForm({ onClose }) {
  const queryClient = useQueryClient()
  const types = useLeaveTypes()
  const [form, setForm] = useState({
    leave_type_id: '',
    start_date: todayIso(),
    end_date: todayIso(),
    reason: '',
  })

  const submit = useMutation({
    mutationFn: (body) => api.post('/api/leave-requests', body),
    onSuccess: () => {
      refresh(queryClient)
      onClose()
    },
  })

  const status = submit.error?.response?.status
  const errors = status === 422 ? (submit.error.response.data.errors ?? {}) : {}
  const known = ['leave_type_id', 'start_date', 'end_date', 'reason']
  const extra = Object.entries(errors)
    .filter(([key]) => !known.includes(key))
    .flatMap(([, messages]) => messages)

  let general = ''
  if (submit.isError && status !== 422) {
    general =
      status === 403
        ? 'Akun Anda tidak punya data karyawan aktif, jadi tidak bisa mengajukan cuti.'
        : (submit.error?.response?.data?.message ?? 'Gagal mengajukan. Coba lagi.')
  }

  const err = (field) => errors[field]?.[0]
  const set = (field) => (e) => setForm({ ...form, [field]: e.target.value })

  function handleSubmit(e) {
    e.preventDefault()
    submit.mutate({
      leave_type_id: Number(form.leave_type_id),
      start_date: form.start_date,
      end_date: form.end_date,
      reason: form.reason.trim() || null,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">Ajukan cuti</h2>

      {(general || extra.length > 0) && (
        <div className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {general && <p>{general}</p>}
          {extra.map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-3">
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
        <Field label="Mulai" error={err('start_date')}>
          <input type="date" min={todayIso()} className={inputClass} value={form.start_date} onChange={set('start_date')} required />
        </Field>
        <Field label="Selesai" error={err('end_date')}>
          <input type="date" min={form.start_date} className={inputClass} value={form.end_date} onChange={set('end_date')} required />
        </Field>
      </div>

      <Field label="Alasan" error={err('reason')} hint="Boleh dikosongkan. Akhir pekan tidak dihitung.">
        <textarea className={inputClass} rows={2} maxLength={500} value={form.reason} onChange={set('reason')} />
      </Field>

      <div className="flex gap-2">
        <button type="submit" disabled={submit.isPending} className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
          {submit.isPending ? 'Mengirim...' : 'Ajukan'}
        </button>
        <button type="button" onClick={onClose} className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
          Batal
        </button>
      </div>
    </form>
  )
}

const ACTION_TITLE = { approve: 'Setujui cuti', reject: 'Tolak cuti', cancel: 'Batalkan cuti' }

function DecisionDialog({ row, action, onClose }) {
  const queryClient = useQueryClient()
  const [note, setNote] = useState('')

  const run = useMutation({
    mutationFn: () => api.post(`/api/leave-requests/${row.id}/${action}`, { note: note.trim() || undefined }),
    onSuccess: () => {
      refresh(queryClient)
      onClose()
    },
  })

  const status = run.error?.response?.status
  const noteError = status === 422 ? run.error.response.data.errors?.note?.[0] : ''
  const general = run.isError && status !== 422 ? (run.error?.response?.data?.message ?? 'Aksi gagal. Coba lagi.') : ''

  const hint =
    action === 'reject'
      ? 'Wajib diisi untuk penolakan.'
      : action === 'cancel'
        ? 'Wajib diisi bila Anda membatalkan pengajuan orang lain.'
        : 'Boleh dikosongkan.'

  return (
    <Dialog title={ACTION_TITLE[action]} onClose={onClose}>
      <p className="text-sm text-slate-600">
        {row.employee?.full_name} · {row.leave_type?.name} · {fmtDate(row.start_date)} – {fmtDate(row.end_date)} ({row.days} hari)
      </p>

      {general && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {general}
        </p>
      )}

      <Field label="Catatan" error={noteError} hint={hint}>
        <textarea className={inputClass} rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>

      <div className="flex justify-end gap-2">
        <button onClick={onClose} className="rounded border border-slate-300 bg-white px-4 py-2 text-sm">
          Kembali
        </button>
        <button onClick={() => run.mutate()} disabled={run.isPending} className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">
          {run.isPending ? 'Memproses...' : 'Konfirmasi'}
        </button>
      </div>
    </Dialog>
  )
}

export default function Requests() {
  const { user } = useAuth()
  const thisYear = new Date().getFullYear()
  const [status, setStatus] = useState(user.role === 'atasan' ? 'pending' : '')
  const [year, setYear] = useState('')
  const [page, setPage] = useState(1)
  const [showForm, setShowForm] = useState(false)
  const [dialog, setDialog] = useState(null)
  const showEmployee = user.role !== 'karyawan'
  const years = [thisYear - 2, thisYear - 1, thisYear, thisYear + 1]

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['leave-requests', user.id, { status, year, page }],
    queryFn: async () =>
      (await api.get('/api/leave-requests', { params: { status: status || undefined, year: year || undefined, page } })).data,
    placeholderData: keepPreviousData,
  })

  const rows = data?.data ?? []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Status">
            <select
              className={inputClass}
              value={status}
              onChange={(e) => {
                setStatus(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Semua</option>
              {Object.entries(LEAVE_STATUS_LABEL).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Tahun">
            <select
              className={inputClass}
              value={year}
              onChange={(e) => {
                setYear(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Semua</option>
              {years.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </select>
          </Field>
          {isFetching && <span className="pb-2 text-xs text-slate-500">Memperbarui...</span>}
        </div>

        {!showForm && (
          <button onClick={() => setShowForm(true)} className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
            Ajukan cuti
          </button>
        )}
      </div>

      {showForm && <SubmitForm onClose={() => setShowForm(false)} />}

      {isLoading && <Skeleton />}
      {error && !data && <ErrorBox error={error} onRetry={refetch} />}

      {data && (
        <>
          {rows.length === 0 ? (
            <Empty>Tidak ada pengajuan cuti yang cocok.</Empty>
          ) : (
            <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    {showEmployee && <th className="px-3 py-2">Karyawan</th>}
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Periode</th>
                    <th className="px-3 py-2">Hari</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Keterangan</th>
                    <th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-slate-100 align-top last:border-0">
                      {showEmployee && (
                        <td className="px-3 py-2">
                          {row.employee?.full_name}
                          <span className="block text-xs text-slate-500">{row.employee?.employee_number}</span>
                        </td>
                      )}
                      <td className="px-3 py-2">{row.leave_type?.name}</td>
                      <td className="px-3 py-2">
                        {fmtDate(row.start_date)} – {fmtDate(row.end_date)}
                      </td>
                      <td className="px-3 py-2">{row.days}</td>
                      <td className="px-3 py-2">
                        <Badge tone={LEAVE_STATUS_TONE[row.status] ?? 'default'}>
                          {LEAVE_STATUS_LABEL[row.status] ?? row.status}
                        </Badge>
                      </td>
                      <td className="max-w-xs px-3 py-2 text-xs text-slate-600">
                        {row.reason && <p>Alasan: {row.reason}</p>}
                        {row.decision?.note && (
                          <p>
                            Keputusan{row.decision.by ? ` (${row.decision.by.name})` : ''}: {row.decision.note}
                          </p>
                        )}
                        {row.cancellation && (
                          <p>
                            Dibatalkan{row.cancellation.by ? ` oleh ${row.cancellation.by.name}` : ''}
                            {row.cancellation.note ? `: ${row.cancellation.note}` : ''}
                          </p>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right">
                        {row.can_decide && (
                          <>
                            <button onClick={() => setDialog({ row, action: 'approve' })} className="mr-2 underline">
                              Setujui
                            </button>
                            <button onClick={() => setDialog({ row, action: 'reject' })} className="mr-2 underline">
                              Tolak
                            </button>
                          </>
                        )}
                        {row.can_cancel && (
                          <button onClick={() => setDialog({ row, action: 'cancel' })} className="underline">
                            Batalkan
                          </button>
                        )}
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

      {dialog && <DecisionDialog key={`${dialog.row.id}-${dialog.action}`} row={dialog.row} action={dialog.action} onClose={() => setDialog(null)} />}
    </div>
  )
}
