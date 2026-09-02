import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { Status } from '@/api/types';

export function useStatus() {
  return useQuery({
    queryKey: ['status'],
    queryFn: () => api<Status>('/status'),
    refetchInterval: 5000,
  });
}
