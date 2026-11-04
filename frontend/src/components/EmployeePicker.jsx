import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/useAuth'
import { inputClass } from './ui'
import { api } from '../lib/api'
import { useDebounced } from '../lib/hooks'

export default function EmployeePicker({ label = 'Karyawan', value, onChange, error }) {
  const { user } = useAuth()
  const [input, setInput] = useState('')
  const [picked, setPicked] = useState(null)
  const search = useDebounced(input.trim())

  const { data, isFetching } = useQuery({
    queryKey: ['employee-picker', user.id, search],
    queryFn: async () =>
      (await api.get('/api/employees', { params: { search: search || undefined } })).data.data,
    staleTime: 30_000,
  })

  const options = (data ?? []).filter((employee) => employee.is_active)
  const list = picked && !options.some((employee) => employee.id === picked.id) ? [picked, ...options] : options

  function handleChange(e) {
    setPicked(list.find((employee) => String(employee.id) === e.target.value) ?? null)
    onChange(e.target.value)
  }

  return (
    <div className="text-sm">
      <span className="text-slate-700">{label}</span>
      <input
        type="search"
        aria-label={`Cari ${label.toLowerCase()}`}
        placeholder="Cari nama atau nomor karyawan"
        value={input}
        onChange={(e) => setInput(e.target.value)}
        className={inputClass}
      />
      <select aria-label={label} className={inputClass} value={value} onChange={handleChange} required>
        <option value="">{isFetching ? 'Memuat...' : 'Pilih karyawan'}</option>
        {list.map((employee) => (
          <option key={employee.id} value={employee.id}>
            {employee.employee_number} · {employee.full_name}
          </option>
        ))}
      </select>
      {error && (
        <span className="mt-1 block text-xs text-red-600" role="alert">
          {error}
        </span>
      )}
    </div>
  )
}
