import { useState } from 'react'
import { useAuth } from '../auth/useAuth'
import { Tabs } from '../components/ui'
import Balances from './leave/Balances'
import Requests from './leave/Requests'
import Types from './leave/Types'

export default function Leave() {
  const { user } = useAuth()
  const [tab, setTab] = useState('requests')
  // Hanya soal tampilan: yang menolak tetap policy di backend.
  const canManage = user.role === 'hr'

  const tabs = [
    { key: 'requests', label: 'Pengajuan' },
    { key: 'balances', label: 'Saldo' },
    ...(canManage ? [{ key: 'types', label: 'Jenis cuti' }] : []),
  ]

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Cuti</h1>
      <Tabs tabs={tabs} value={tab} onChange={setTab} />
      {tab === 'requests' && <Requests />}
      {tab === 'balances' && <Balances canManage={canManage} />}
      {tab === 'types' && canManage && <Types />}
    </div>
  )
}
