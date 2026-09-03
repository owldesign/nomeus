import { useEffect, useMemo, useRef, useState } from 'react';
import type { DumpEntry, DumpKind } from '@/api/types';
import type { XdebugMode } from '@/api/types';
import Button, { ConfirmInline } from '@/components/Button';
import Chip, { type Tint } from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import { ToggleChip, Toggle } from '@/components/Field';
import Led from '@/components/Led';
import Panel, { INPUT_SM, PageHeader } from '@/components/Panel';
import Tabs from '@/components/Tabs';
import TaskProgress from '@/components/TaskProgress';
import { useClearDumps, useDumpRequests, useDumps, useDumpsHeader, useDumpsStatus, useRefetchAfterTask, useSetCapture, useXdebug, useXdebugAction } from '@/hooks/useApi';

const KINDS: (DumpKind | 'all')[] = ['all', 'dump', 'query', 'job', 'view', 'request', 'log'];
const label: Record<DumpKind | 'all', string> = { all: 'All', dump: 'Dumps', query: 'Queries', job: 'Jobs', view: 'Views', request: 'Requests', log: 'Logs' };
const kindTint: Record<DumpKind, Tint> = { dump: 'lantern', query: 'info', job: 'ok', view: 'neutral', request: 'info', log: 'neutral' };

declare global {
  interface Window { Sfdump?: (id: string, options?: Record<string, unknown>) => void }
}

/** Symfony's dump css + js, injected once so the rendered dumps fold/unfold like in the browser. */
function useSfDumpAssets() {
  const { data: header } = useDumpsHeader();
  const done = useRef(false);
  useEffect(() => {
    if (!header || done.current) return;
    done.current = true;
    const doc = new DOMParser().parseFromString(header, 'text/html');
    doc.querySelectorAll('style').forEach((s) => { const el = document.createElement('style'); el.textContent = s.textContent; document.head.appendChild(el); });
    doc.querySelectorAll('script').forEach((s) => { const el = document.createElement('script'); el.textContent = s.textContent; document.head.appendChild(el); });
  }, [header]);
}

function DumpHtml({ html }: { html: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const body = useMemo(() => html.replace(/<script>.*?<\/script>/s, ''), [html]);
  const call = useMemo(() => /Sfdump\("([^"]+)"(?:,\s*(\{.*?\}))?\)/s.exec(html), [html]);
  useEffect(() => {
    if (!call || !window.Sfdump || !ref.current) return;
    try { window.Sfdump(call[1], call[2] ? JSON.parse(call[2]) : {}); } catch { /* header not loaded yet; the next render retries */ }
  }, [call, body]);
  return <div ref={ref} className="sf-dump-host overflow-auto" dangerouslySetInnerHTML={{ __html: body }} />;
}

function Payload({ e }: { e: DumpEntry }) {
  const p = (e.payload ?? {}) as Record<string, any>;
  switch (e.kind) {
    case 'query':
      return (
        <div>
          <code className="whitespace-pre-wrap break-words text-text">{p.sql}</code>
          <div className="text-xs text-dim">{Array.isArray(p.bindings) && p.bindings.length ? `bindings ${JSON.stringify(p.bindings)} · ` : ''}{p.ms} ms · {p.connection}</div>
        </div>
      );
    case 'job':
      return <div><span className={p.status === 'failed' ? 'text-fail' : 'text-text'}>{p.status}</span> {p.name} <span className="text-dim">queue {p.queue}{p.ms ? ` · ${p.ms} ms` : ''}{p.exception ? ` · ${p.exception}` : ''}</span></div>;
    case 'view':
      return <div className="text-text">{p.name} <span className="text-faint">{p.path}</span></div>;
    case 'request':
      return (
        <div>
          <span className="text-text">{p.method} {p.url}</span> <span className={p.status >= 400 ? 'text-fail' : 'text-dim'}>→ {p.status}</span> <span className="text-dim">{p.ms ? `${p.ms} ms` : ''}</span>
          {p.response && <details className="mt-1"><summary className="cursor-pointer text-xs text-dim">response</summary><pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-inset p-2 text-xs text-mid">{p.response}</pre></details>}
        </div>
      );
    case 'log':
      return <div><Chip tint={['error', 'critical', 'alert', 'emergency'].includes(p.level) ? 'fail' : p.level === 'warning' ? 'warn' : 'info'}>{String(p.level).toUpperCase()}</Chip> <span className="text-text">{p.message}</span>{p.context && Object.keys(p.context).length > 0 && <pre className="mt-1 text-xs text-dim">{JSON.stringify(p.context)}</pre>}</div>;
    default:
      return <pre className="m-0 whitespace-pre-wrap text-text">{e.text}</pre>;
  }
}

/** Spec §9: header row (timestamp · kind chip · source link), the body in an inset well; a lantern wash on arrival. */
function Row({ e, showRequest, fresh }: { e: DumpEntry; showRequest: boolean; fresh: boolean }) {
  const where = e.file ? `${e.file.split('/').slice(-2).join('/')}${e.line ? `:${e.line}` : ''}` : null;
  return (
    <div className={`border-b border-line/55 px-4 py-3 ${fresh ? 'animate-arrive' : ''}`}>
      <div className="mb-2 flex flex-wrap items-baseline gap-x-3 text-xs">
        <span className="text-dim">{e.created_at.slice(11, 23)}</span>
        <Chip tint={kindTint[e.kind]}>{e.kind}</Chip>
        {showRequest && (e.uri ? <span className="text-faint">{e.method} {e.uri}</span> : e.command ? <span className="text-faint">$ {e.command}</span> : null)}
        {where && <span className="ml-auto">{e.url ? <a href={e.url} className="text-info hover:underline" title={e.file!}>{where}</a> : <span className="text-dim" title={e.file!}>{where}</span>}</span>}
      </div>
      <div className="rounded-md bg-inset px-3 py-2 text-sm">
        {e.kind === 'dump' && e.html ? <DumpHtml html={e.html} /> : <Payload e={e} />}
      </div>
    </div>
  );
}

const MODES: { mode: XdebugMode; hint: string }[] = [
  { mode: 'off', hint: 'not loaded — zero cost' },
  { mode: 'on', hint: 'every request connects to the IDE — use while stepping' },
  { mode: 'trigger', hint: 'loaded; starts with the browser helper / ?XDEBUG_TRIGGER=1 / XDEBUG_TRIGGER=1 for CLI' },
  { mode: 'detect', hint: 'follows the IDE: on while it listens, off when it stops (php-fpm restarts on each change)' },
];

const MODE_TINT: Record<XdebugMode, Tint> = { off: 'neutral', on: 'lantern', trigger: 'info', detect: 'ok' };

/** Xdebug per PHP version; mode changes and installs run as tasks (brew, fpm restart). */
function XdebugPanel() {
  const { data } = useXdebug();
  const act = useXdebugAction();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  if (!data) return null;
  const versions = Object.entries(data.versions);
  const run = (a: Parameters<typeof act.mutate>[0]) => act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });

  return (
    <Panel className="mb-4" title={<button type="button" className="hover:text-lantern" onClick={() => setOpen(!open)}>{open ? '▾' : '▸'} xdebug</button>}
      actions={<>
        <span className="inline-flex items-center gap-1.5 text-xs text-dim"><Led state={data.ide_listening ? 'running' : 'stopped'} /> IDE {data.ide_listening ? `listening on ${data.port}` : `not listening on ${data.port}`}</span>
        {data.watcher.installed && <span className="inline-flex items-center gap-1.5 text-xs text-dim"><Led state={data.watcher.running ? 'running' : 'crashing'} /> detect watcher {data.watcher.running ? `pid ${data.watcher.pid}` : 'not running'}</span>}
        {!open && versions.filter(([, v]) => v.installed).map(([php, v]) => <span key={php} className="inline-flex items-center gap-1.5 text-xs text-dim">php {php} <Chip tint={MODE_TINT[v.mode]}>{v.mode}{v.mode === 'detect' ? ` → ${v.effective}` : ''}</Chip></span>)}
        {taskId && <TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 3000); }} />}
      </>}>
      {open && (
        <div>
          {versions.map(([php, v]) => (
            <div key={php} className="flex flex-wrap items-center gap-3 border-b border-line/55 px-4 py-2 last:border-0">
              <span className="w-28 text-text">php {php}{data.linked === php ? <span className="text-faint"> linked</span> : null}</span>
              {v.installed ? (
                <span className="inline-flex gap-1.5">
                  {MODES.map((m) => (
                    <ToggleChip key={m.mode} on={v.mode === m.mode} disabled={act.isPending || taskId !== null} onClick={() => run({ action: 'mode', version: php, mode: m.mode })}>
                      <span title={m.hint}>{m.mode}{m.mode === 'detect' && v.mode === 'detect' ? ` → ${v.effective}` : ''}</span>
                    </ToggleChip>
                  ))}
                </span>
              ) : (
                <Button disabled={act.isPending || taskId !== null} onClick={() => run({ action: 'install', version: php })}>install xdebug</Button>
              )}
              <span className="text-xs text-faint">
                {v.installed ? MODES.find((m) => m.mode === v.mode)?.hint : 'shivammathur/extensions/xdebug@' + php}
                {v.tap_ini && <span className="text-fail"> · formula ini is back — set the mode again to re-quarantine</span>}
                {v.installed && v.mode === 'on' && !data.ide_listening && <span className="text-warn"> · nothing listening: ~200 ms per request</span>}
              </span>
            </div>
          ))}
          {versions.length === 0 && <p className="px-4 py-2 text-dim">no brew php versions</p>}
        </div>
      )}
      {act.isError && <div className="px-4 pb-2 text-fail">{String(act.error)}</div>}
    </Panel>
  );
}

export default function Debug() {
  useSfDumpAssets();
  const status = useDumpsStatus();
  const requests = useDumpRequests();
  const setCapture = useSetCapture();
  const clear = useClearDumps();
  const [kind, setKind] = useState<DumpKind | 'all'>('all');
  const [latestOnly, setLatestOnly] = useState(true);
  const [pinned, setPinned] = useState<string | null>(null);   // an explicitly chosen request
  const [q, setQ] = useState('');
  const requestKey = pinned ?? (latestOnly ? status.data?.latest_request ?? null : null);
  const { entries, counts, refresh } = useDumps(kind, requestKey);

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const list = needle ? entries.filter((e) => `${e.text}\n${e.file ?? ''}\n${e.uri ?? ''}`.toLowerCase().includes(needle)) : entries;
    return [...list].reverse();   // newest first, like Herd
  }, [entries, q]);

  const s = status.data;
  const notReady = s && (!s.prepend || Object.values(s.ini).some((v) => !v.current) || !s.instance || !s.running);
  const seen = useRef<Set<number>>(new Set());
  const freshIds = useMemo(() => {
    const fresh = new Set<number>();
    for (const e of shown) if (!seen.current.has(e.id)) { fresh.add(e.id); seen.current.add(e.id); }
    return fresh;
  }, [shown]);
  const total = Object.values(counts).reduce((a, b) => a + (b ?? 0), 0);

  return (
    <div className="flex h-[calc(100vh-7rem)] flex-col">
      <PageHeader title="Debug" summary={<span className="inline-flex items-center gap-1.5"><Led state={s?.running ? 'running' : s?.instance ? 'stopped' : 'missing'} /> dump server {s?.instance ? (s.running ? `on ${s.port}` : 'stopped') : 'not created'}</span>} />
      <XdebugPanel />
      <Panel className="flex min-h-0 flex-1 flex-col">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line/55 px-3 py-2">
          <Toggle on={!!s?.capture} disabled={setCapture.isPending} onChange={(v) => setCapture.mutate(v)} label={<span title={s?.capture ? 'capture on — dumps come here' : 'capture off — dumps print as usual'}>{s?.capture ? 'capturing' : 'capture'}</span>} />
          <Toggle on={latestOnly && !pinned} onChange={(v) => { setPinned(null); setLatestOnly(v); }} label="latest request only" />
          <select aria-label="Request" className={`${INPUT_SM} max-w-64`} value={pinned ?? ''} onChange={(e) => setPinned(e.target.value || null)}>
            <option value="">{latestOnly ? '(latest)' : '(all requests)'}</option>
            {(requests.data ?? []).map((r) => (
              <option key={r.request_key} value={r.request_key}>{r.uri ? `${r.method} ${r.uri}` : `$ ${r.command}`} · {r.n}</option>
            ))}
          </select>
          <input aria-label="Search" className={`${INPUT_SM} w-48`} placeholder="search" value={q} onChange={(e) => setQ(e.target.value)} />
          <Button onClick={() => refresh()}>reload</Button>
          <span className="ml-auto"><ConfirmInline trigger="clear" question="delete all stored dumps and events?" action="clear" disabled={clear.isPending} onConfirm={() => clear.mutate(undefined, { onSuccess: () => refresh() })} /></span>
        </div>

        <div className="px-3">
          <Tabs tabs={KINDS.map((k) => ({ id: k, label: label[k], count: k === 'all' ? total : counts[k] ?? 0 }))} active={kind} onChange={setKind} />
        </div>

        {notReady && (
          <div className="border-b border-line/55 bg-warn/8 px-4 py-2 text-xs text-warn">
            {!s.prepend || Object.values(s.ini).some((v) => !v.current)
              ? <>prepend ini missing for a PHP version — <code>nomeus dumps:install --restart</code>. </>
              : null}
            {!s.instance ? <>no dump server yet — <code>nomeus services:create dumps</code>. </> : !s.running ? <>dump server stopped — start it on the Services page. </> : null}
          </div>
        )}

        <div className="min-h-0 flex-1 overflow-auto">
          {shown.map((e) => <Row key={e.id} e={e} showRequest={!requestKey} fresh={freshIds.has(e.id)} />)}
          {shown.length === 0 && (
            entries.length
              ? <EmptyState title="Nothing matches" line="clear the search or pick another tab" />
              : s?.capture
                ? <EmptyState title="Waiting" line="dump() or dd() something in any site — it lands here with the request's queries, jobs, views and logs" />
                : <EmptyState title="Capture is off" line="turn it on, then dump() something" command="nomeus dumps:capture on" />
          )}
        </div>
      </Panel>
    </div>
  );
}
