import { useTask } from '@/hooks/useApi';
import { ApiError } from '@/api/client';
import type { Task } from '@/api/types';

/**
 * Inline progress for one enqueued task: label while running, outcome when done.
 * A fetch failing during the poll is normal for a few seconds (nginx restarting) — but an
 * HTTP error, or failures that don't stop, mean the dashboard itself is broken and are said so.
 */
export default function TaskProgress({ id, onFinished }: { id: string; onFinished?: (t: Task) => void }) {
  const { data, isError, error, failureCount } = useTask(id, { onFinished });
  const persistent = failureCount >= 12; // ≈ 10 s of consecutive failures
  const httpError = error instanceof ApiError;

  if (httpError || (isError && persistent)) {
    const why = error instanceof ApiError ? `${error.status} ${error.message}` : String(error);
    return (
      <span className="text-red">
        can't reach the api ({why}). {data ? `${data.label} may have finished; ` : ''}check from a terminal: devkit status · devkit tasks
      </span>
    );
  }

  if (!data) return <span className="text-dim">{isError ? 'waiting for nginx…' : 'queued…'}</span>;
  if (data.status === 'failed') {
    const tail = (data.log ?? '').trim().split('\n').slice(-3).join(' ');
    return <span className="text-red">{data.label} failed{data.exit_code !== null ? ` (exit ${data.exit_code})` : ''}: {tail || 'no output'}</span>;
  }
  if (data.status === 'done') return <span className="text-green">{data.label} done</span>;
  return <span className="text-dim">running {data.label}…{isError ? ' (nginx restarting)' : ''}</span>;
}
