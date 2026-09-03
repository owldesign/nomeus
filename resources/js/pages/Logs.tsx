import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import type { LogEntry, LogSource } from '@/api/types';
import Button, { ConfirmInline } from '@/components/Button';
import Chip, { type Tint } from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import { Toggle, ToggleChip } from '@/components/Field';
import { INPUT_SM, LABEL, PageHeader } from '@/components/Panel';
import { useClearLog, useLogSources, useLogTail } from '@/hooks/useApi';

const SEVERITIES = ['error', 'warning', 'info', 'debug'] as const;
type Severity = (typeof SEVERITIES)[number];
const sevTint: Record<Severity, Tint> = { error: 'fail', warning: 'warn', info: 'info', debug: 'neutral' };

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
    parts.push(<a key={`${m.index}-${ref.text}`} href={ref.url!} className="text-info hover:underline" title={`open ${ref.file}:${ref.line}`}>{m[0]}</a>);
    last = m.index! + m[0].length;
  }
  parts.push(text.slice(last));
  return <>{parts.map((p, i) => <Fragment key={i}>{p}</Fragment>)}</>;
}

/** Spec §8: baseline row — chevron · ms timestamp · level chip · message ranked by level · repeat count; the trace as an inset well. */
function Entry({ e }: { e: LogEntry }) {
  const [open, setOpen] = useState(false);
  const detail = [e.context, e.trace].filter(Boolean).join('\n');
  const lines = detail ? detail.split('\n').length : 0;
  return (
    <div className="border-b border-line/55 px-4 py-[9px] hover:bg-raised/50">
      <div className="flex items-baseline gap-3">
        <button type="button" className="w-3 shrink-0 text-2xs text-faint" onClick={() => detail && setOpen(!open)} aria-label={open ? 'collapse' : 'expand'}>{detail ? (open ? '▾' : '▸') : ''}</button>
        <span className="shrink-0 text-xs text-dim">{e.ts ?? '—'}</span>
        <Chip tint={sevTint[e.severity]} className="shrink-0 uppercase">{e.level}</Chip>
        <span className={`min-w-0 flex-1 break-words ${e.severity === 'error' ? 'text-text' : 'text-mid'}`}><Linked text={e.message} entry={e} /></span>
        {detail && <button type="button" className="shrink-0 text-2xs text-faint hover:text-mid" onClick={() => setOpen(!open)}>+{lines}</button>}
      </div>
      {open && detail && (
        <pre className="mt-2 ml-6 max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-inset px-3 py-2 text-xs leading-[1.8] text-mid"><Linked text={detail} entry={e} /></pre>
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
    <div className="flex h-[calc(100vh-7rem)] flex-col">
      <PageHeader title="Logs" summary={current ? <span className="truncate" title={current.path}>{current.group} / {current.label}{meta ? ` · ${kb(meta.size)}${meta.truncated ? ' · showing the tail' : ''}` : ''}</span> : ''} />
      <div className="grid min-h-0 flex-1 grid-cols-[220px_1fr] overflow-hidden rounded-lg border border-line bg-panel shadow-panel">
        <aside className="min-h-0 overflow-auto border-r border-line/55 p-3">
          {groups.length === 0 && (sources.isLoading ? <p className="text-dim">reading…</p> : <EmptyState title="No logs yet" line="no site has written storage/logs/*.log" />)}
          {groups.map(([group, files]) => (
            <div key={group} className="mb-3">
              <div className={`${LABEL} mb-1`}>{group}</div>
              <ul className="space-y-0.5">
                {files.map((f) => (
                  <li key={f.path}>
                    <button
                      type="button"
                      onClick={() => setPath(f.path)}
                      className={`flex w-full items-baseline justify-between gap-2 rounded-sm px-2 py-1 text-left ${path === f.path ? 'bg-inset text-lantern' : 'text-mid hover:bg-raised hover:text-text'}`}
                      title={f.path}
                    >
                      <span className="truncate">{f.label}</span>
                      <span className="shrink-0 text-2xs text-faint">{kb(f.size)}</span>
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </aside>

        <section className="flex min-h-0 flex-col">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line/55 px-3 py-2">
            <div className="flex gap-1.5">
              {SEVERITIES.map((s) => (
                <ToggleChip key={s} on={severity === s} onClick={() => setSeverity(severity === s ? null : s)}>
                  {s} <span className={counts[s] ? (severity === s ? 'text-lantern' : 'text-mid') : 'text-faint'}>{counts[s]}</span>
                </ToggleChip>
              ))}
            </div>
            <input aria-label="Search" className={`${INPUT_SM} w-48`} placeholder="search" value={q} onChange={(e) => setQ(e.target.value)} />
            <Toggle on={following} onChange={setFollowing} label="follow" />
            <Button onClick={() => refresh()}>reload</Button>
            {current && (
              <span className="ml-auto">
                <ConfirmInline trigger="clear" question={`truncate ${current.label}?`} action="clear" disabled={clear.isPending} onConfirm={() => clear.mutate(current.path, { onSuccess: () => refresh() })} />
              </span>
            )}
          </div>
          {error && <p className="px-3 py-2 text-fail">{error}</p>}
          <div ref={list} className="min-h-0 flex-1 overflow-auto">
            {shown.map((e, i) => <Entry key={`${i}-${e.ts}`} e={e} />)}
            {path && shown.length === 0 && <EmptyState title={entries.length ? 'Nothing matches the filter' : 'Empty'} line={entries.length ? 'clear the level or the search' : 'this file has no entries yet'} />}
            <div ref={bottom} />
          </div>
        </section>
      </div>
    </div>
  );
}
