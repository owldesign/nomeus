import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, api, del, post } from '@/api/client';
import type { BrewService, DoctorReport, NodeState, DumpEntry, DumpKind, DumpRequest, DumpsStatus, Enqueued, LogEntry, LogSource, LogTail, MailMessage, MailPage, MailStatus, PhpState, ServiceInstance, ServiceType, Site, SiteDetail, Status, Task, XdebugMode, XdebugStatus } from '@/api/types';

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
  | { name: string; action: 'secure' | 'unsecure' | 'unisolate' | 'init' }
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
    qc.invalidateQueries({ queryKey: ['xdebug'] });
    qc.invalidateQueries({ queryKey: ['node'] });
    qc.invalidateQueries({ queryKey: ['dumps', 'status'] });
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

export function useLogSources() {
  return useQuery({
    queryKey: ['logs', 'sources'],
    queryFn: async () => (await api<{ data: LogSource[] }>('/logs/sources')).data,
    refetchInterval: 10000,
  });
}

/**
 * Incremental tail of one file. First read = the last 64 KB; then every 2 s asks for what was
 * appended since the last offset. A truncate/rotation (offset > size) comes back as reset=true.
 */
export function useLogTail(path: string | null, following: boolean) {
  const [entries, setEntries] = useState<LogEntry[]>([]);
  const [meta, setMeta] = useState<{ size: number; truncated: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const offset = useRef<number | null>(null);
  const inflight = useRef(false);

  const poll = useCallback(async (fresh = false) => {
    if (!path || inflight.current) return;
    inflight.current = true;
    try {
      const q = new URLSearchParams({ path });
      if (!fresh && offset.current !== null) q.set('offset', String(offset.current));
      const { data } = await api<{ data: LogTail }>(`/logs/tail?${q.toString()}`);
      offset.current = data.offset;
      setMeta({ size: data.size, truncated: data.truncated });
      setEntries((prev) => (fresh || data.reset ? data.entries : data.entries.length ? [...prev, ...data.entries].slice(-2000) : prev));
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : String(e));
    } finally {
      inflight.current = false;
    }
  }, [path]);

  useEffect(() => {
    offset.current = null;
    setEntries([]);
    setMeta(null);
    void poll(true);
  }, [path, poll]);

  useEffect(() => {
    if (!path || !following) return;
    const t = setInterval(() => void poll(), 2000);
    return () => clearInterval(t);
  }, [path, following, poll]);

  return { entries, meta, error, refresh: () => poll(true) };
}

export function useClearLog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (path: string) => del<{ cleared: string }>(`/logs?path=${encodeURIComponent(path)}`),
    onSettled: () => qc.invalidateQueries({ queryKey: ['logs', 'sources'] }),
  });
}

export function useDumpsStatus() {
  return useQuery({
    queryKey: ['dumps', 'status'],
    queryFn: async () => (await api<{ data: DumpsStatus }>('/dumps/status')).data,
    refetchInterval: 3000,
  });
}

export function useDumpsHeader() {
  return useQuery({
    queryKey: ['dumps', 'header'],
    queryFn: async () => (await api<{ header: string }>('/dumps/header')).header,
    staleTime: Infinity,
  });
}

export function useDumpRequests() {
  return useQuery({
    queryKey: ['dumps', 'requests'],
    queryFn: async () => (await api<{ data: DumpRequest[] }>('/dumps/requests')).data,
    refetchInterval: 3000,
  });
}

/** Newest 200 first, then everything with id > last every 1.5 s. Resets when the filters change. */
export function useDumps(kind: DumpKind | 'all', requestKey: string | null) {
  const [entries, setEntries] = useState<DumpEntry[]>([]);
  const [counts, setCounts] = useState<Partial<Record<DumpKind, number>>>({});
  const last = useRef<number | null>(null);
  const busy = useRef(false);

  const poll = useCallback(async (fresh = false) => {
    if (busy.current) return;
    busy.current = true;
    try {
      const q = new URLSearchParams();
      if (kind !== 'all') q.set('kind', kind);
      if (requestKey) q.set('request', requestKey);
      if (!fresh && last.current !== null) q.set('after', String(last.current));
      const res = await api<{ data: DumpEntry[]; counts: Partial<Record<DumpKind, number>> }>(`/dumps?${q.toString()}`);
      if (res.data.length) last.current = res.data[res.data.length - 1].id;
      setCounts(res.counts);
      setEntries((prev) => (fresh ? res.data : res.data.length ? [...prev, ...res.data].slice(-1000) : prev));
    } finally {
      busy.current = false;
    }
  }, [kind, requestKey]);

  useEffect(() => {
    last.current = null;
    setEntries([]);
    void poll(true);
  }, [poll]);

  useEffect(() => {
    const t = setInterval(() => void poll(), 1500);
    return () => clearInterval(t);
  }, [poll]);

  return { entries, counts, refresh: () => poll(true), reset: () => { last.current = null; setEntries([]); } };
}

export function useSetCapture() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (on: boolean) => post<{ capture: boolean }>('/dumps/capture', { on }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['dumps', 'status'] }),
  });
}

export function useClearDumps() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => del<{ cleared: number }>('/dumps'),
    onSettled: () => qc.invalidateQueries({ queryKey: ['dumps'] }),
  });
}

export function useXdebug() {
  return useQuery({
    queryKey: ['xdebug'],
    queryFn: async () => (await api<{ data: XdebugStatus }>('/xdebug')).data,
    refetchInterval: 3000,
  });
}

export function useXdebugAction() {
  return useMutation({
    mutationFn: (a: { action: 'install'; version: string } | { action: 'mode'; version: string; mode: XdebugMode }) =>
      a.action === 'install'
        ? post<Enqueued>('/xdebug/install', { version: a.version })
        : post<Enqueued>('/xdebug/mode', { version: a.version, mode: a.mode }),
  });
}

export function useDoctor() {
  return useQuery({
    queryKey: ['doctor'],
    queryFn: async () => (await api<{ data: DoctorReport }>('/doctor')).data,
    refetchInterval: 30000,
  });
}

export function useSelfUpdate() {
  return useMutation({
    mutationFn: (opts: { check?: boolean; noBuild?: boolean } = {}) => post<Enqueued>('/update', { check: !!opts.check, no_build: !!opts.noBuild }),
  });
}

export interface NewSiteRequest {
  name: string;
  dir?: string;
  starter: 'laravel' | 'empty' | 'from';
  from?: string;
  php?: string;
  db?: 'postgresql' | 'mysql' | 'mariadb' | 'none';
  redis?: boolean;
  services?: string[];
  mail?: boolean;
  secure?: boolean;
  skip_scripts?: boolean;
}

export function useNewSite() {
  return useMutation({ mutationFn: (body: NewSiteRequest) => post<Enqueued>('/sites/new', body) });
}

export function useNode() {
  return useQuery({
    queryKey: ['node'],
    queryFn: async () => (await api<{ data: NodeState }>('/node')).data,
    refetchInterval: 15000,
  });
}

export function useNodeAction() {
  return useMutation({
    mutationFn: (a: { action: 'install'; version: string; default?: boolean } | { action: 'use'; version: string; site?: string; default?: boolean }) =>
      a.action === 'install'
        ? post<Enqueued>('/node/install', { version: a.version, default: !!a.default })
        : post<Enqueued>('/node/use', { version: a.version, site: a.site, default: !!a.default }),
  });
}

/** Run a fix the doctor proposed, as a task. The server only accepts commands the doctor itself would print. */
export function useDoctorFix() {
  return useMutation({ mutationFn: (command: string) => post<Enqueued>('/doctor/fix', { command }) });
}
