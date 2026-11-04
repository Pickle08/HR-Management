import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/useAuth'
import { api } from './api'

export function useLeaveTypes() {
  const { user } = useAuth()

  return useQuery({
    queryKey: ['leave-types', user.id],
    queryFn: async () => (await api.get('/api/leave-types')).data.data,
    staleTime: 60_000,
  })
}
