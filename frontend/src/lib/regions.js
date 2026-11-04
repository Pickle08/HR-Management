import { useQuery } from '@tanstack/react-query'
import { api } from './api'

// Data wilayah jarang berubah, jadi cukup diambil sekali per sesi.
export function useRegions(level, params, enabled = true) {
  return useQuery({
    queryKey: ['regions', level, params ?? null],
    queryFn: async () => (await api.get(`/api/regions/${level}`, { params })).data.data,
    enabled,
    staleTime: Infinity,
  })
}
