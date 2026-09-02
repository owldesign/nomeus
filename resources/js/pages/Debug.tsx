import { useEffect, useMemo, useRef, useState } from 'react';
import type { DumpEntry, DumpKind } from '@/api/types';
import { useClearDumps, useDumpRequests, useDumps, useDumpsHeader, useDumpsStatus, useSetCapture } from '@/hooks/useApi';

const KINDS: (DumpKind | 'all')[] = ['all', 'dump', 'query', 'job', 'view', 'request', 'log'];
const label: Record<DumpKind | 'all', string> = { all: 'All', dump: 'Dumps', query: 'Queries', job: 'Jobs', view: 'Views', request: 'Requests', log: 'Logs' };
const kindClass: Record<DumpKind, string> = { dump: 'text-gold', query: 'text-blue', job: 'text-green', view: 'text-dim', request: 'text-green', log: 'text-dim' };

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
          <code className="whitespace-pre-wrap break-words text-fg">{p.sql}</code>
          <div className="text-dim">{Array.isArray(p.bindings) && p.bindings.length ? `bindings ${JSON.stringify(p.bindings)} · ` : ''}{p.ms} ms · {p.connection}</div>
        </div>
      );
    case 'job':
      return <div><span className={p.status === 'failed' ? 'text-red' : 'text-fg'}>{p.status}</span> {p.name} <span className="text-dim">queue {p.queue}{p.ms ? ` · ${p.ms} ms` : ''}{p.exception ? ` · ${p.exception}` : ''}</span></div>;
    case 'view':
      return <div className="text-fg">{p.name} <span className="text-dim">{p.path}</span></div>;
    case 'request':
      return (
        <div>
          <span className="text-fg">{p.method} {p.url}</span> <span className={p.status >= 400 ? 'text-red' : 'text-dim'}>→ {p.status}</span> <span className="text-dim">{p.ms ? `${p.ms} ms` : ''}</span>
          {p.response && <details className="mt-1"><summary className="cursor-pointer text-dim">response</summary><pre className="max-h-64 overflow-auto whitespace-pre-wrap text-dim">{p.response}</pre></details>}
        </div>
      );
    case 'log':
      return <div><span className={['error', 'critical', 'alert', 'emergency'].includes(p.level) ? 'text-red' : p.level === 'warning' ? 'text-gold' : 'text-blue'}>{String(p.level).toUpperCase()}</span> <span className="text-fg">{p.message}</span>{p.context && Object.keys(p.context).length > 0 && <pre className="text-dim">{JSON.stringify(p.context)}</pre>}</div>;
    default:
      return <pre className="whitespace-pre-wrap text-fg">{e.text}</pre>;
  }
}

function Row({ e, showRequest }: { e: DumpEntry; showRequest: boolean }) {
  const where = e.file ? `${e.file.split('/').slice(-2).join('/')}${e.line ? `:${e.line}` : ''}` : null;
  return (
    <div className="border-b border-dashed border-line px-3 py-2">
      <div className="mb-1 flex flex-wrap items-baseline gap-x-3 text-dim">
        <span>{e.created_at.slice(11, 23)}</span>
        <span className={kindClass[e.kind]}>{e.kind}</span>
        {where && (e.url ? <a href={e.url} className="text-blue hover:underline" title={e.file!}>{where}</a> : <span title={e.file!}>{where}</span>)}
        {showRequest && (e.uri ? <span>{e.method} {e.uri}</span> : e.command ? <span>$ {e.command}</span> : null)}
      </div>
      {e.kind === 'dump' && e.html ? <DumpHtml html={e.html} /> : <Payload e={e} />}
    </div>
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

  return (
    <div className="flex h-[calc(100vh-7rem)] flex-col border border-line bg-panel">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-3 py-2">
        <button
          type="button"
          title={s?.capture ? 'capture on — click to send dumps back to the browser/terminal' : 'capture off — click to route dumps here'}
          className={`inline-flex items-center gap-2 rounded-sm border px-2 py-0.5 ${s?.capture ? 'border-green text-green' : 'border-line text-dim hover:text-fg'}`}
          disabled={setCapture.isPending}
          onClick={() => setCapture.mutate(!s?.capture)}
        >
          <span className={`inline-block h-2 w-2 rounded-full ${s?.capture ? 'animate-pulse bg-green' : 'bg-mute'}`} /> {s?.capture ? 'capturing' : 'capture'}
        </button>
        <span className="inline-flex items-center gap-1 text-dim">
          <span className={`inline-block h-2 w-2 rounded-full ${s?.running ? 'bg-green' : 'bg-red'}`} /> server {s?.instance ? (s.running ? `on ${s.port}` : 'stopped') : 'not created'}
        </span>
        <label className="inline-flex items-center gap-1 text-dim"><input type="checkbox" checked={latestOnly && !pinned} onChange={(e) => { setPinned(null); setLatestOnly(e.target.checked); }} /> latest request only</label>
        <select aria-label="Request" className="max-w-64 border border-line bg-bg px-2 py-0.5 text-fg" value={pinned ?? ''} onChange={(e) => setPinned(e.target.value || null)}>
          <option value="">{latestOnly ? '(latest)' : '(all requests)'}</option>
          {(requests.data ?? []).map((r) => (
            <option key={r.request_key} value={r.request_key}>{r.uri ? `${r.method} ${r.uri}` : `$ ${r.command}`} · {r.n}</option>
          ))}
        </select>
        <input aria-label="Search" className="w-48 border border-line bg-bg px-2 py-0.5" placeholder="search" value={q} onChange={(e) => setQ(e.target.value)} />
        <button type="button" className="text-dim hover:text-gold" onClick={() => refresh()}>reload</button>
        <button type="button" className="ml-auto text-dim hover:text-red" disabled={clear.isPending} onClick={() => { if (confirm('Delete all stored dumps and events?')) clear.mutate(undefined, { onSuccess: () => refresh() }); }}>clear</button>
      </div>

      <div className="flex gap-1 border-b border-line px-3 py-1">
        {KINDS.map((k) => (
          <button key={k} type="button" onClick={() => setKind(k)} className={`rounded-sm px-2 py-0.5 ${kind === k ? 'bg-bg text-gold' : 'text-dim hover:text-fg'}`}>
            {label[k]} <span className="text-dim">{k === 'all' ? Object.values(counts).reduce((a, b) => a + (b ?? 0), 0) : counts[k] ?? 0}</span>
          </button>
        ))}
      </div>

      {notReady && (
        <div className="border-b border-line bg-bg px-3 py-2 text-gold">
          {!s.prepend || Object.values(s.ini).some((v) => !v.current)
            ? <>prepend ini missing for a PHP version — <code>devkit dumps:install --restart</code>. </>
            : null}
          {!s.instance ? <>no dump server yet — <code>devkit services:create dumps</code>. </> : !s.running ? <>dump server stopped — start it on the Services page. </> : null}
        </div>
      )}

      <div className="min-h-0 flex-1 overflow-auto">
        {shown.map((e) => <Row key={e.id} e={e} showRequest={!requestKey} />)}
        {shown.length === 0 && (
          <p className="p-3 text-dim">{entries.length ? 'nothing matches' : s?.capture ? 'waiting — dump() something' : 'turn capture on, then dump() something'}</p>
        )}
      </div>
    </div>
  );
}
