import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Badge, Empty, ErrorBox, Field, Skeleton, inputClass } from '../../components/ui'
import { api } from '../../lib/api'
import { useLeaveTypes } from '../../lib/leave'

function TypeForm({ type, onClose }) {
  const queryClient = useQueryClient()
  const editing = Boolean(type)
  const [form, setForm] = useState({
    name: type?.name ?? '',
    annual_quota: type?.annual_quota ?? 12,
    is_active: type?.is_active ?? true,
  })

  const save = useMutation({
    mutationFn: (body) => (editing ? api.put(`/api/leave-types/${type.id}`, body) : api.post('/api/leave-types', body)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-types'] })
      onClose()
    },
  })

  const validation = save.error?.response?.status === 422
  const errors = validation ? (save.error.response.data.errors ?? {}) : {}
  const general =
    save.isError && !validation ? (save.error?.response?.data?.message ?? 'Gagal menyimpan. Coba lagi.') : ''
  const err = (field) => errors[field]?.[0]

  function submit(e) {
    e.preventDefault()
    save.mutate({
      name: form.name.trim(),
      annual_quota: Number(form.annual_quota),
      is_active: form.is_active,
    })
  }

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">{editing ? 'Ubah jenis cuti' : 'Tambah jenis cuti'}</h2>

      {general && (
        <p className="rounded bg-red-50 p-3 text-sm text-red-700" role="alert">
          {general}
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Nama" error={err('name')}>
          <input className={inputClass} maxLength={100} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
        </Field>
        <Field label="Jatah tahunan (hari)" error={err('annual_quota')}>
          <input type="number" min="0" max="365" className={inputClass} value={form.annual_quota} onChange={(e) => setForm({ ...form, annual_quota: e.target.value })} required />
        </Field>
      </div>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
        Aktif (bisa dipilih saat mengajukan cuti)
      </label>

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

export default function Types() {
  const { data, isLoading, error, refetch } = useLeaveTypes()
  const [editing, setEditing] = useState(null)

  if (isLoading) return <Skeleton />
  if (error) return <ErrorBox error={error} onRetry={refetch} />

  return (
    <div className="space-y-4">
      {!editing && (
        <div className="flex justify-end">
          <button onClick={() => setEditing({ type: null })} className="rounded bg-slate-900 px-3 py-2 text-sm text-white">
            Tambah jenis cuti
          </button>
        </div>
      )}

      {editing && <TypeForm key={editing.type?.id ?? 'new'} type={editing.type} onClose={() => setEditing(null)} />}

      {data.length === 0 ? (
        <Empty>Belum ada jenis cuti.</Empty>
      ) : (
        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Nama</th>
                <th className="px-3 py-2">Jatah tahunan</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {data.map((type) => (
                <tr key={type.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2">{type.name}</td>
                  <td className="px-3 py-2">{type.annual_quota} hari</td>
                  <td className="px-3 py-2">
                    <Badge tone={type.is_active ? 'good' : 'bad'}>{type.is_active ? 'Aktif' : 'Nonaktif'}</Badge>
                  </td>
                  <td className="px-3 py-2 text-right">
                    <button onClick={() => setEditing({ type })} className="underline">
                      Ubah
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
