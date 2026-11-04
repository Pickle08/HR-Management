import { Field, inputClass } from './ui'
import { useRegions } from '../lib/regions'

const ORDER = ['province_code', 'regency_code', 'district_code', 'village_code']

export default function RegionPicker({ value, onChange, errors = {} }) {
  const provinces = useRegions('provinces')
  const regencies = useRegions('regencies', { province_code: value.province_code }, Boolean(value.province_code))
  const districts = useRegions('districts', { regency_code: value.regency_code }, Boolean(value.regency_code))
  const villages = useRegions('villages', { district_code: value.district_code }, Boolean(value.district_code))

  // Mengganti satu tingkat mengosongkan semua tingkat di bawahnya.
  function pick(field, next) {
    const below = Object.fromEntries(ORDER.slice(ORDER.indexOf(field) + 1).map((key) => [key, '']))
    onChange({ ...value, ...below, [field]: next })
  }

  const levels = [
    { field: 'province_code', label: 'Provinsi', query: provinces, locked: false },
    { field: 'regency_code', label: 'Kabupaten/Kota', query: regencies, locked: !value.province_code },
    { field: 'district_code', label: 'Kecamatan', query: districts, locked: !value.regency_code },
    { field: 'village_code', label: 'Desa/Kelurahan', query: villages, locked: !value.district_code },
  ]

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {levels.map(({ field, label, query, locked }) => (
        <Field key={field} label={label} error={errors[field]?.[0]}>
          <select
            className={inputClass}
            value={value[field]}
            disabled={locked || query.isLoading}
            onChange={(e) => pick(field, e.target.value)}
          >
            <option value="">{query.isLoading ? 'Memuat...' : `Pilih ${label.toLowerCase()}`}</option>
            {(query.data ?? []).map((item) => (
              <option key={item.code} value={item.code}>
                {item.name}
              </option>
            ))}
          </select>
          {query.isError && <span className="mt-1 block text-xs text-red-600">Gagal memuat daftar wilayah.</span>}
        </Field>
      ))}
    </div>
  )
}
