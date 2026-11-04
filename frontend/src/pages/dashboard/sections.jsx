import { Card, Empty, Stat, StatRow } from '../../components/ui'
import { fmtDate, monthName, rupiah } from '../../lib/format'
import { ATTENDANCE_LABEL, EMPLOYMENT_LABEL, ROLE_LABEL, STAGE_LABEL } from '../../lib/labels'

function Row({ label, value, strong }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-600">{label}</dt>
      <dd className={strong ? 'font-semibold' : ''}>{value}</dd>
    </div>
  )
}

function AttendanceStats({ byStatus, notRecorded }) {
  return (
    <StatRow>
      {Object.entries(ATTENDANCE_LABEL).map(([key, label]) => (
        <Stat key={key} label={label} value={byStatus[key]} />
      ))}
      {notRecorded !== undefined && (
        <Stat label="Belum tercatat" value={notRecorded} tone={notRecorded > 0 ? 'warn' : 'default'} />
      )}
    </StatRow>
  )
}

function PayrollProcess({ data }) {
  return (
    <>
      <p className="mb-3 text-sm text-slate-600">
        {monthName(data.period.month)} {data.period.year}
      </p>
      <StatRow>
        <Stat label="Draft" value={data.draft} tone={data.draft > 0 ? 'warn' : 'default'} />
        <Stat label="Final (termasuk koreksi)" value={data.finalized} />
        <Stat label="Belum dibuat" value={data.missing} tone={data.missing > 0 ? 'warn' : 'default'} />
      </StatRow>
    </>
  )
}

export function MeSection({ me }) {
  if (!me) {
    return (
      <Card title="Data pribadi">
        <Empty>Akun ini belum terhubung ke data karyawan. Hubungi HR.</Empty>
      </Card>
    )
  }

  const { employee, leave, attendance_this_month: att, latest_payslip: slip } = me
  const netAfterAdjustments = slip ? slip.net + slip.adjustments_net : 0

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Profil">
        <p className="text-lg font-semibold">{employee.full_name}</p>
        <p className="text-sm text-slate-600">
          {employee.employee_number} · {employee.position ?? '-'} · {employee.department ?? '-'}
        </p>
      </Card>

      <Card title="Absensi bulan ini">
        <AttendanceStats byStatus={att} />
      </Card>

      <Card title="Saldo cuti tahun ini">
        {leave.balances.length === 0 ? (
          <Empty>Jatah cuti belum diatur. Hubungi HR.</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-500">
                  <th className="pb-2 font-medium">Jenis</th>
                  <th className="pb-2 text-right font-medium">Jatah</th>
                  <th className="pb-2 text-right font-medium">Terpakai</th>
                  <th className="pb-2 text-right font-medium">Menunggu</th>
                  <th className="pb-2 text-right font-medium">Sisa</th>
                </tr>
              </thead>
              <tbody>
                {leave.balances.map((b) => (
                  <tr key={b.leave_type} className="border-t border-slate-100">
                    <td className="py-1.5">{b.leave_type}</td>
                    <td className="py-1.5 text-right">{b.entitled_days}</td>
                    <td className="py-1.5 text-right">{b.used_days}</td>
                    <td className="py-1.5 text-right">{b.pending_days}</td>
                    <td className="py-1.5 text-right font-semibold">{b.remaining_days}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="mt-3 space-y-1 text-sm text-slate-600">
          <p>Pengajuan menunggu keputusan: {leave.pending_requests}</p>
          <p>
            Cuti berikutnya:{' '}
            {leave.next_leave
              ? `${fmtDate(leave.next_leave.start_date)} – ${fmtDate(leave.next_leave.end_date)} (${leave.next_leave.days} hari)`
              : '-'}
          </p>
        </div>
      </Card>

      <Card title="Slip gaji terakhir">
        {slip ? (
          <>
            <p className="mb-2 text-sm text-slate-600">
              {monthName(slip.month)} {slip.year}
            </p>
            <dl className="space-y-1 text-sm">
              <Row label="Bruto" value={rupiah(slip.gross)} />
              <Row label="Potongan" value={rupiah(slip.deductions)} />
              <Row label="Neto" value={rupiah(slip.net)} strong />
              {slip.adjustments_net !== 0 && (
                <>
                  <Row label="Koreksi" value={rupiah(slip.adjustments_net)} />
                  <Row label="Neto setelah koreksi" value={rupiah(netAfterAdjustments)} strong />
                </>
              )}
            </dl>
          </>
        ) : (
          <Empty>Belum ada slip yang final.</Empty>
        )}
      </Card>
    </div>
  )
}

export function TeamSection({ team }) {
  if (!team) {
    return (
      <Card title="Tim">
        <Empty>Akun ini belum terhubung ke data karyawan.</Empty>
      </Card>
    )
  }

  const att = team.attendance_today

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Tim saya">
        <StatRow>
          <Stat label="Anggota aktif" value={team.size} />
          <Stat
            label="Cuti menunggu keputusan"
            value={team.pending_leave.count}
            tone={team.pending_leave.count > 0 ? 'warn' : 'default'}
          />
        </StatRow>
      </Card>

      <Card title="Absensi tim hari ini">
        <AttendanceStats byStatus={att.by_status} notRecorded={att.not_recorded} />
      </Card>

      <Card title="Pengajuan cuti menunggu (terlama dulu)">
        {team.pending_leave.oldest.length === 0 ? (
          <Empty>Tidak ada pengajuan yang menunggu.</Empty>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {team.pending_leave.oldest.map((r) => (
              <li key={r.id} className="py-2">
                <p className="font-medium">{r.employee.full_name}</p>
                <p className="text-slate-600">
                  {r.leave_type} · {fmtDate(r.start_date)} – {fmtDate(r.end_date)} · {r.days} hari
                </p>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Sedang cuti hari ini">
        {team.on_leave_today.length === 0 ? (
          <Empty>Tidak ada.</Empty>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {team.on_leave_today.map((r) => (
              <li key={r.employee.id} className="py-2">
                <p className="font-medium">{r.employee.full_name}</p>
                <p className="text-slate-600">sampai {fmtDate(r.end_date)}</p>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

export function HrSection({ hr }) {
  const { employees, leave, attendance_today: att, recruitment, payroll } = hr

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Karyawan">
        <StatRow>
          <Stat label="Aktif" value={employees.active} />
          <Stat label="Nonaktif" value={employees.inactive} />
          {Object.entries(employees.by_employment_status).map(([key, value]) => (
            <Stat key={key} label={EMPLOYMENT_LABEL[key] ?? key} value={value} />
          ))}
        </StatRow>
        <table className="mt-4 w-full text-sm">
          <thead>
            <tr className="text-left text-slate-500">
              <th className="pb-2 font-medium">Departemen</th>
              <th className="pb-2 text-right font-medium">Aktif</th>
            </tr>
          </thead>
          <tbody>
            {employees.by_department.map((d) => (
              <tr key={d.id} className="border-t border-slate-100">
                <td className="py-1.5">{d.name}</td>
                <td className="py-1.5 text-right">{d.active_employees}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      <Card title="Cuti">
        <StatRow>
          <Stat
            label="Menunggu keputusan"
            value={leave.pending_count}
            tone={leave.pending_count > 0 ? 'warn' : 'default'}
          />
          <Stat label="Sedang cuti hari ini" value={leave.on_leave_today} />
        </StatRow>
      </Card>

      <Card title="Absensi hari ini">
        <AttendanceStats byStatus={att.by_status} notRecorded={att.not_recorded} />
      </Card>

      <Card title="Rekrutmen">
        <StatRow>
          <Stat label="Lowongan dibuka" value={recruitment.open_openings} />
          {Object.entries(STAGE_LABEL).map(([key, label]) => (
            <Stat key={key} label={label} value={recruitment.pipeline[key]} />
          ))}
          <Stat
            label="Diterima, belum jadi karyawan"
            value={recruitment.accepted_awaiting_conversion}
            tone={recruitment.accepted_awaiting_conversion > 0 ? 'warn' : 'default'}
          />
        </StatRow>
      </Card>

      <Card title="Proses payroll">
        <PayrollProcess data={payroll} />
      </Card>
    </div>
  )
}

export function FinanceSection({ finance }) {
  const totals = finance.finalized_totals

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Proses payroll">
        <PayrollProcess data={finance} />
      </Card>

      <Card title="Total payroll final">
        <dl className="space-y-1 text-sm">
          <Row label="Bruto" value={rupiah(totals.gross)} />
          <Row label="Potongan" value={rupiah(totals.deductions)} />
          <Row label="Neto" value={rupiah(totals.net)} strong />
        </dl>
        <p className="mt-3 text-xs text-slate-500">
          Payroll asli dan koreksinya dijumlahkan. Draft tidak ikut.
        </p>
      </Card>
    </div>
  )
}

export function AdminSection({ admin }) {
  const { accounts } = admin

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card title="Akun">
        <StatRow>
          <Stat label="Total" value={accounts.total} />
          <Stat label="Aktif" value={accounts.active} />
          <Stat label="Terkunci" value={accounts.locked} tone={accounts.locked > 0 ? 'warn' : 'default'} />
        </StatRow>
        <ul className="mt-4 space-y-1 text-sm">
          {Object.entries(accounts.by_role).map(([role, count]) => (
            <li key={role} className="flex justify-between">
              <span className="text-slate-600">{ROLE_LABEL[role] ?? role}</span>
              <span>{count}</span>
            </li>
          ))}
        </ul>
      </Card>

      <Card title="Perlu tindakan">
        <StatRow>
          <Stat
            label="Karyawan aktif tanpa akun"
            value={admin.employees_without_account}
            tone={admin.employees_without_account > 0 ? 'warn' : 'default'}
          />
          <Stat
            label="Cuti menunggu keputusan Anda"
            value={admin.leave_awaiting_decision}
            tone={admin.leave_awaiting_decision > 0 ? 'warn' : 'default'}
          />
        </StatRow>
      </Card>
    </div>
  )
}
