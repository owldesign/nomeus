import { useEffect, useRef, useState } from 'react';
import { useTask } from '@/hooks/useApi';
import { ApiError } from '@/api/client';
import type { Task } from '@/api/types';
import Chip from '@/components/Chip';

/**
 * One enqueued task (spec §5). Queued: a quiet chip. Running: the loudest element on the page — lantern frame,
 * marching stripe, the log streaming into an inset well with a caret, elapsed time. Done: one quiet line.
 * Failed: the chip, the command, and the last stderr line inline (usually saves opening the log).
 */
export default function TaskProgress({ id, onFinished, compact = false }: { id: string; onFinished?: (t: Task) => void; compact?: boolean }) {
  const { data, isError, error, failureCount } = useTask(id, { onFinished });
  const persistent = failureCount >= 12; // ≈ 10 s of consecutive failures
  const httpError = error instanceof ApiError;
  const [elapsed, setElapsed] = useState(0);
  const started = useRef(Date.now());
  const wellRef = useRef<HTMLPreElement>(null);

  useEffect(() => {
    if (data?.status === 'done' || data?.status === 'failed') return;
    const t = setInterval(() => setElapsed(Math.round((Date.now() - started.current) / 1000)), 1000);
    return () => clearInterval(t);
  }, [data?.status]);
  useEffect(() => { if (wellRef.current) wellRef.current.scrollTop = wellRef.current.scrollHeight; }, [data?.log]);

  if (httpError || (isError && persistent)) {
    const why = error instanceof ApiError ? `${error.status} ${error.message}` : String(error);
    return <span className="text-fail">can't reach the api ({why}). {data ? `${data.label} may have finished; ` : ''}check from a terminal: nomeus status · nomeus tasks</span>;
  }
  if (!data) {
    return <span className="inline-flex items-center gap-2"><Chip tint="neutral">queued</Chip><span className="text-faint text-xs">{isError ? 'waiting for nginx…' : ''}</span></span>;
  }
  if (data.status === 'failed') {
    const tail = (data.log ?? '').trim().split('\n').filter(Boolean).slice(-1)[0] ?? 'no output';
    return (
      <span className="inline-flex flex-wrap items-center gap-2">
        <Chip tint="fail">failed{data.exit_code !== null ? ` · exit ${data.exit_code}` : ''}</Chip>
        <span className="text-mid">{data.label}</span>
        <span className="text-dim text-xs">{tail}</span>
        <a className="text-xs" href={`/tasks#${data.id}`}>log</a>
      </span>
    );
  }
  if (data.status === 'done') {
    return (
      <span className="inline-flex items-center gap-2">
        <Chip tint="ok">done</Chip>
        <span className="text-mid">{data.label}</span>
        <span className="text-faint text-xs">{elapsed ? `${elapsed}s · ` : ''}<a href={`/tasks#${data.id}`}>log</a></span>
      </span>
    );
  }
  // running
  const lines = (data.log ?? '').split('\n').filter(Boolean).slice(compact ? -3 : -12);
  return (
    <div className="my-1 w-full max-w-3xl rounded-md border border-lantern/35 bg-lantern/4">
      <div className="flex items-center gap-2 px-3 py-1.5">
        <Chip tint="lantern" glow>running</Chip>
        <span className="text-mid">{data.label}</span>
        {isError && <span className="text-faint text-xs">(nginx restarting)</span>}
        <span className="ml-auto text-faint text-xs">{elapsed}s</span>
      </div>
      <div className="np-stripe" />
      <pre ref={wellRef} className={`m-0 overflow-auto bg-inset px-3 py-2 text-xs leading-[1.7] text-mid ${compact ? 'max-h-16' : 'max-h-48'}`}>
        {lines.join('\n')}{lines.length ? '\n' : ''}<span className="text-lantern animate-blink">▍</span>
      </pre>
    </div>
  );
}
