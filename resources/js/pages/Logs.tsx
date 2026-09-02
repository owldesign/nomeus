import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { LogEntry, LogSource } from '@/api/types';
import { useClearLog, useLogSources, useLogTail } from '@/hooks/useApi';

const SEVERITIES = ['error', 'warning', 'info', 'debug'] as const;
type Severity = (typeof SEVERITIES)[number];
const sevClass: Record<Severity, string> = { error: 'text-red', warning: 'text-gold', info: 'text-blue', debug: 'text-dim' };

function kb(n: number) {
  return n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(0)} KB` : `${(n / 1048576).toFixed(1)} MB`;
}

/** Trace text with each file:line reference turned into an IDE link (when the IDE has a URL scheme). */
function Linked({ text, entry }: { text: string; entry: LogEntry }) {
  const refs = entry.refs.filter((r) => r.url);
  if (refs.length === 0) return <>{text}</>;
  const pattern = new RegExp(refs.map((r) => r.text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
  const parts: (string | JSX.Element)[] = [];
  let last = 0;
  for (const m of text.matchAll(pattern)) {
    const ref = refs.find((r) => r.text === m[0])!;
    parts.push(text.slice(last, m.index));
    parts.push(<a key={`${m.index}-${ref.text}`} href={ref.url!} className="text-blue hover:underline" title={`open ${ref.file}:${ref.line}`}>{m[0]}</a>);
    last = m.index! + m[0].length;
  }
  parts.push(text.slice(last));
  return <>{parts.map((p, i) => <Fragment key={i}>{p}</Fragment>)}</>;
}

function Entry({ e }: { e: LogEntry }) {
  const [open, setOpen] = useState(false);
  const detail = [e.context, e.trace].filter(Boolean).join('\n');
  return (
    <div className="border-b border-dashed border-line px-3 py-1.5">
      <div className="flex items-baseline gap-3">
        <span className="shrink-0 text-dim">{e.ts ?? '—'}</span>
        <span className={`w-16 shrink-0 uppercase ${sevClass[e.severity]}`}>{e.level}</span>
        <span className="min-w-0 flex-1 break-words text-fg"><Linked text={e.message} entry={e} /></span>
        {detail && (
          <button type="button" className="shrink-0 text-dim hover:text-gold" onClick={() => setOpen(!open)}>{open ? 'hide' : `+${detail.split('\n').length}`}</button>
        )}
      </div>
      {open && detail && (
        <pre className="mt-1 max-h-96 overflow-auto whitespace-pre-wrap border-l-2 border-line pl-3 text-dim"><Linked text={detail} entry={e} /></pre>
      )}
    </div>
  );
}

export default function Logs() {
  const sources = useLogSources();
  const [path, setPath] = useState<string | null>(null);
  const [following, setFollowing] = useState(true);
  const [severity, setSeverity] = useState<Severity | null>(null);
  const [q, setQ] = useState('');
  const clear = useClearLog();
  const { entries, meta, error, refresh } = useLogTail(path, following);
  const bottom = useRef<HTMLDivElement>(null);
  const list = useRef<HTMLDivElement>(null);

  // default to the most recently written source
  useEffect(() => {
    if (path === null && sources.data && sources.data.length > 0) {
      setPath([...sources.data].sort((a, b) => b.mtime - a.mtime)[0].path);
    }
  }, [sources.data, path]);

  const groups = useMemo(() => {
    const g = new Map<string, LogSource[]>();
    for (const s of sources.data ?? []) g.set(s.group, [...(g.get(s.group) ?? []), s]);
    return [...g.entries()].sort(([a], [b]) => (a === 'valet' ? 1 : b === 'valet' ? -1 : a.localeCompare(b)));
  }, [sources.data]);

  const counts = useMemo(() => {
    const c: Record<Severity, number> = { error: 0, warning: 0, info: 0, debug: 0 };
    for (const e of entries) c[e.severity]++;
    return c;
  }, [entries]);

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return entries.filter((e) => (!severity || e.severity === severity) && (!needle || `${e.message}\n${e.context ?? ''}\n${e.trace}`.toLowerCase().includes(needle)));
  }, [entries, severity, q]);

  // auto-scroll while following, unless the user scrolled up to read
  useEffect(() => {
    const el = list.current;
    if (!following || !el) return;
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    if (nearBottom) bottom.current?.scrollIntoView({ block: 'end' });
  }, [shown, following]);

  const current = sources.data?.find((s) => s.path === path);

  return (
    <div className="grid h-[calc(100vh-7rem)] grid-cols-[220px_1fr] border border-line bg-panel">
      <aside className="min-h-0 overflow-auto border-r border-line p-3">
        {groups.length === 0 && <p className="text-dim">{sources.isLoading ? 'reading…' : 'No logs yet: no site has written storage/logs/*.log.'}</p>}
        {groups.map(([group, files]) => (
          <div key={group} className="mb-3">
            <div className="mb-1 text-dim">{group}</div>
            <ul className="space-y-0.5">
              {files.map((f) => (
                <li key={f.path}>
                  <button
                    type="button"
                    onClick={() => setPath(f.path)}
                    className={`flex w-full items-baseline justify-between gap-2 rounded-sm px-2 py-0.5 text-left ${path === f.path ? 'bg-bg text-gold' : 'hover:text-gold'}`}
                    title={f.path}
                  >
                    <span className="truncate">{f.label}</span>
                    <span className="shrink-0 text-dim">{kb(f.size)}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </aside>

      <section className="flex min-h-0 flex-col">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line px-3 py-2">
          <span className="truncate text-dim" title={current?.path}>{current ? `${current.group} / ${current.label}` : '—'}{meta ? ` · ${kb(meta.size)}${meta.truncated ? ' · showing the tail' : ''}` : ''}</span>
          <div className="flex gap-2">
            {SEVERITIES.map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => setSeverity(severity === s ? null : s)}
                className={`rounded-sm border px-2 py-0.5 ${severity === s ? 'border-gold text-gold' : 'border-line text-dim hover:text-fg'}`}
              >
                {s} <span className={counts[s] ? sevClass[s] : ''}>{counts[s]}</span>
              </button>
            ))}
          </div>
          <input aria-label="Search" className="w-48 border border-line bg-bg px-2 py-0.5" placeholder="search" value={q} onChange={(e) => setQ(e.target.value)} />
          <label className="inline-flex items-center gap-1 text-dim"><input type="checkbox" checked={following} onChange={(e) => setFollowing(e.target.checked)} /> follow</label>
          <button type="button" className="text-dim hover:text-gold" onClick={() => refresh()}>reload</button>
          {current && (
            <button
              type="button"
              className="ml-auto text-dim hover:text-red"
              disabled={clear.isPending}
              onClick={() => { if (confirm(`Truncate ${current.path}?`)) clear.mutate(current.path, { onSuccess: () => refresh() }); }}
            >
              clear
            </button>
          )}
        </div>
        {error && <p className="px-3 py-2 text-red">{error}</p>}
        <div ref={list} className="min-h-0 flex-1 overflow-auto">
          {shown.map((e, i) => <Entry key={`${i}-${e.ts}`} e={e} />)}
          {path && shown.length === 0 && <p className="p-3 text-dim">{entries.length ? 'nothing matches the filter' : 'empty'}</p>}
          <div ref={bottom} />
        </div>
      </section>
    </div>
  );
}
