export const MONTHS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
]

const idr = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

export const rupiah = (value) => idr.format(value ?? 0)

export const monthName = (month) => MONTHS[month - 1] ?? ''

export function fmtDate(iso) {
  if (!iso) return '-'

  return new Date(`${iso.slice(0, 10)}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

export const toIso = (date) => date.toLocaleDateString('en-CA')

export const todayIso = () => toIso(new Date())

export const monthStartIso = () => {
  const now = new Date()

  return toIso(new Date(now.getFullYear(), now.getMonth(), 1))
}
