import { useQuery } from '@tanstack/react-query'
import { api } from '../../lib/api'

const FIVE_MINUTES = 5 * 60 * 1000
const ONE_HOUR = 60 * 60 * 1000

const fetchList = (url, params) => async () => (await api.get(url, { params })).data.data

export const useDepartments = () =>
  useQuery({ queryKey: ['lookup', 'departments'], queryFn: fetchList('/api/departments'), staleTime: FIVE_MINUTES })

export const usePositions = () =>
  useQuery({ queryKey: ['lookup', 'positions'], queryFn: fetchList('/api/positions'), staleTime: FIVE_MINUTES })

// Hanya atasan aktif dengan akun aktif: daftar pilihan yang pasti lolos validasi backend.
export const useManagers = () =>
  useQuery({ queryKey: ['lookup', 'managers'], queryFn: fetchList('/api/employees/managers'), staleTime: FIVE_MINUTES })

const PARENT_PARAM = { regencies: 'province_code', districts: 'regency_code', villages: 'district_code' }

export function useRegions(level, parent = null) {
  const needsParent = level !== 'provinces'

  return useQuery({
    queryKey: ['regions', level, parent],
    enabled: !needsParent || Boolean(parent),
    staleTime: ONE_HOUR,
    queryFn: fetchList(`/api/regions/${level}`, needsParent ? { [PARENT_PARAM[level]]: parent } : undefined),
  })
}
