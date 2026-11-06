import { useState } from 'react'

export function ActiveBadge({ active }) {
  return (
    <span
      className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${
        active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-600'
      }`}
    >
      {active ? 'Aktif' : 'Nonaktif'}
    </span>
  )
}

export function Field({ label, children }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="text-sm">{children || '-'}</dd>
    </div>
  )
}

// NIK dan telepon disamarkan dulu: layar yang kebetulan dilihat orang lain tidak membocorkannya.
export function MaskedValue({ value }) {
  const [shown, setShown] = useState(false)

  if (!value) return <span className="text-slate-400">-</span>

  const masked =
    value.length > 8 ? `${value.slice(0, 4)}${'•'.repeat(value.length - 8)}${value.slice(-4)}` : '••••'

  return (
    <span className="inline-flex items-center gap-2">
      <span className="font-mono">{shown ? value : masked}</span>
      <button
        type="button"
        onClick={() => setShown((current) => !current)}
        aria-pressed={shown}
        className="text-xs text-slate-500 underline"
      >
        {shown ? 'Sembunyikan' : 'Tampilkan'}
      </button>
    </span>
  )
}
