import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, del, post } from '@/api/client';
import type { Enqueued, Site, SiteDetail, Status, Task } from '@/api/types';

export function useStatus() {
  return useQuery({
    queryKey: ['status'],
    queryFn: () => api<Status>('/status'),
    refetchInterval: 5000,
  });
}

export function useSites() {
  return useQuery({
    queryKey: ['sites'],
    queryFn: async () => (await api<{ data: Site[] }>('/sites')).data,
    refetchInterval: 10000,
  });
}

export function useSite(name: string | null) {
  return useQuery({
    queryKey: ['sites', name],
    queryFn: async () => (await api<{ data: SiteDetail }>(`/sites/${name}`)).data,
    enabled: name !== null,
  });
}

export function useTasks() {
  return useQuery({
    queryKey: ['tasks'],
    queryFn: async () => (await api<{ data: Task[] }>('/tasks')).data,
    refetchInterval: 3000,
  });
}

/**
 * Poll one task until it finishes. Valet restarts nginx while some tasks run, so a fetch
 * failing mid-poll is expected: keep retrying on the interval rather than giving up.
 */
export function useTask(id: string | null, opts?: { onFinished?: (task: Task) => void }) {
  const query = useQuery({
    queryKey: ['tasks', id],
    queryFn: async () => (await api<{ data: Task }>(`/tasks/${id}`)).data,
    enabled: id !== null,
    refetchInterval: (q) => (q.state.data && isFinished(q.state.data) ? false : 800),
    retry: 30,
    retryDelay: 800,
  });

  const finished = query.data ? isFinished(query.data) : false;
  useEffect(() => {
    if (finished && query.data) opts?.onFinished?.(query.data);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [finished, query.data?.id]);

  return query;
}

export const isFinished = (t: Task) => t.status === 'done' || t.status === 'failed';

export type SiteAction =
  | { name: string; action: 'secure' | 'unsecure' | 'unisolate' }
  | { name: string; action: 'isolate'; php: string }
  | { name: string; action: 'unlink' };

/** Enqueue a per-site action. The caller polls the returned task; the list refetches once it ends. */
export function useSiteAction() {
  return useMutation({
    mutationFn: (a: SiteAction) => {
      if (a.action === 'unlink') return del<Enqueued>(`/sites/${a.name}/link`);
      if (a.action === 'isolate') return post<Enqueued>(`/sites/${a.name}/isolate`, { php: a.php });
      return post<Enqueued>(`/sites/${a.name}/${a.action}`);
    },
  });
}

export function useLinkSite() {
  return useMutation({
    mutationFn: (body: { name: string; path: string }) => post<Enqueued>('/sites/link', body),
  });
}

/** Refetch sites/status after a task ends — used by both the Sites rows and the link form. */
export function useRefetchAfterTask() {
  const qc = useQueryClient();
  return () => {
    qc.invalidateQueries({ queryKey: ['sites'] });
    qc.invalidateQueries({ queryKey: ['status'] });
    qc.invalidateQueries({ queryKey: ['tasks'] });
  };
}
