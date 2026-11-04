import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthContext'
import { ErrorBox, SectionTitle, Skeleton } from '../components/ui'
import { api } from '../lib/api'
import { fmtDate, MONTHS } from '../lib/format'
import { AdminSection, FinanceSection, HrSection, MeSection, TeamSection } from './dashboard/sections'

function PeriodPicker({ value, onChange }) {
  const thisYear = new Date().getFullYear()
  const years = Array.from({ length: 7 }, (_, i) => thisYear - 5 + i)
  const field = 'rounded border border-slate-300 bg-white px-2 py-1'

  return (
    <div className="flex items-center gap-2 text-sm">
      <label htmlFor="period-month" className="text-slate-600">
        Periode payroll
      </label>
      <select
        id="period-month"
        className={field}
        value={value.month}
        onChange={(e) => onChange({ ...value, month: Number(e.target.value) })}
      >
        {MONTHS.map((name, i) => (
          <option key={name} value={i + 1}>
            {name}
          </option>
        ))}
      </select>
      <select
        aria-label="Tahun"
        className={field}
        value={value.year}
        onChange={(e) => onChange({ ...value, year: Number(e.target.value) })}
      >
        {years.map((year) => (
          <option key={year} value={year}>
            {year}
          </option>
        ))}
      </select>
    </div>
  )
}

export default function Dashboard() {
  const { user } = useAuth()
  const today = new Date()
  const [period, setPeriod] = useState({ year: today.getFullYear(), month: today.getMonth() + 1 })
  const usesPeriod = user.role === 'hr' || user.role === 'finance'

  const { data, isLoading, error, refetch } = useQuery({
    // user.id di key: data personal satu user tidak pernah dipakai ulang untuk user lain.
    queryKey: ['dashboard', user.id, usesPeriod ? period : null],
    queryFn: async () => {
      const res = await api.get('/api/dashboard', { params: usesPeriod ? period : undefined })
      return res.data.data
    },
    placeholderData: keepPreviousData,
  })

  if (isLoading) return <Skeleton />
  if (error && !data) return <ErrorBox error={error} onRetry={refetch} />

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold">Dashboard</h1>
          <p className="text-sm text-slate-500">{fmtDate(data.date)}</p>
        </div>
        {usesPeriod && <PeriodPicker value={period} onChange={setPeriod} />}
      </div>

      {error && <ErrorBox error={error} onRetry={refetch} />}

      {data.team !== undefined && <TeamSection team={data.team} />}
      {data.hr && <HrSection hr={data.hr} />}
      {data.finance && <FinanceSection finance={data.finance} />}
      {data.admin && <AdminSection admin={data.admin} />}

      {data.role !== 'karyawan' && <SectionTitle>Data saya</SectionTitle>}
      <MeSection me={data.me} />
    </div>
  )
}
