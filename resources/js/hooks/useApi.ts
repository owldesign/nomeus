import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, del, post } from '@/api/client';
import type { BrewService, Enqueued, MailMessage, MailPage, MailStatus, PhpState, ServiceInstance, ServiceType, Site, SiteDetail, Status, Task } from '@/api/types';

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
    retry: 15,
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
    qc.invalidateQueries({ queryKey: ['php'] });
    qc.invalidateQueries({ queryKey: ['services'] });
  };
}

export function usePhp() {
  return useQuery({
    queryKey: ['php'],
    queryFn: async () => (await api<{ data: PhpState }>('/php')).data,
    refetchInterval: 10000,
  });
}

/** One-off: re-run `brew outdated` (server caches it 10 min) and replace the cached PHP state. */
export function useCheckPhpUpdates() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async () => (await api<{ data: PhpState }>('/php?fresh=1')).data,
    onSuccess: (data) => qc.setQueryData(['php'], data),
  });
}

export type PhpAction = { version: string; action: 'use' | 'install' | 'update' };

export function usePhpAction() {
  return useMutation({
    mutationFn: (a: PhpAction) => post<Enqueued>(`/php/${a.version}/${a.action}`),
  });
}

export function useServices() {
  return useQuery({
    queryKey: ['services'],
    queryFn: async () => (await api<{ data: ServiceInstance[] }>('/services')).data,
    refetchInterval: 5000,
  });
}

export function useServiceTypes() {
  return useQuery({
    queryKey: ['services', 'types'],
    queryFn: async () => (await api<{ data: ServiceType[] }>('/services/types')).data,
    staleTime: 60000,
  });
}

export function useService(name: string | null, lines = 80) {
  return useQuery({
    queryKey: ['services', name, lines],
    queryFn: async () => (await api<{ data: ServiceInstance }>(`/services/${name}?lines=${lines}`)).data,
    enabled: name !== null,
    refetchInterval: 4000,
  });
}

export type ServiceAction =
  | { name: string; action: 'start' | 'stop' | 'restart' }
  | { name: string; action: 'clone'; newName: string; port?: number }
  | { name: string; action: 'delete'; keepData: boolean };

export function useServiceAction() {
  return useMutation({
    mutationFn: (a: ServiceAction) => {
      if (a.action === 'clone') return post<Enqueued>(`/services/${a.name}/clone`, { name: a.newName, port: a.port });
      if (a.action === 'delete') return del<Enqueued>(`/services/${a.name}${a.keepData ? '?keep_data=1' : ''}`);
      return post<Enqueued>(`/services/${a.name}/${a.action}`);
    },
  });
}

export function useCreateService() {
  return useMutation({
    mutationFn: (body: { type: string; version?: string; name?: string; port?: number; start?: boolean; site?: string }) =>
      post<Enqueued>('/services', body),
  });
}

export function useAdoptable() {
  return useQuery({
    queryKey: ['services', 'adoptable'],
    queryFn: async () => (await api<{ data: BrewService[] }>('/services/adoptable')).data,
    refetchInterval: 15000,
  });
}

export function useAdopt() {
  return useMutation({
    mutationFn: (body: { formula: string; name?: string; port?: number }) => post<Enqueued>('/services/adopt', body),
  });
}

export function useMailStatus() {
  return useQuery({
    queryKey: ['mail', 'status'],
    queryFn: async () => (await api<{ data: MailStatus }>('/mail/status')).data,
    refetchInterval: 5000,
  });
}

export function useMailTags(enabled: boolean) {
  return useQuery({
    queryKey: ['mail', 'tags'],
    queryFn: async () => (await api<{ data: string[] }>('/mail/tags')).data,
    enabled,
    refetchInterval: 5000,
  });
}

export function useMailMessages(tag: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['mail', 'messages', tag],
    queryFn: async () => (await api<{ data: MailPage }>(`/mail/messages${tag ? `?tag=${encodeURIComponent(tag)}` : ''}`)).data,
    enabled,
    refetchInterval: 4000,
  });
}

export function useMailMessage(id: string | null) {
  return useQuery({
    queryKey: ['mail', 'message', id],
    queryFn: async () => (await api<{ data: MailMessage }>(`/mail/messages/${encodeURIComponent(id!)}`)).data,
    enabled: id !== null,
    staleTime: 60000,
  });
}

export function useDeleteMail() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (tag: string | null) => del<{ deleted: number }>(`/mail/messages${tag ? `?tag=${encodeURIComponent(tag)}` : ''}`),
    onSettled: () => qc.invalidateQueries({ queryKey: ['mail'] }),
  });
}
