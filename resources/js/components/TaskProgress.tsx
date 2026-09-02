import { useTask } from '@/hooks/useApi';
import type { Task } from '@/api/types';

/**
 * Inline progress for one enqueued task: label while running, outcome when done.
 * Fetch errors during the poll are normal (nginx restarting) and shown as such, not as failure.
 */
export default function TaskProgress({ id, onFinished }: { id: string; onFinished?: (t: Task) => void }) {
  const { data, isError } = useTask(id, { onFinished });

  if (!data) return <span className="text-dim">{isError ? 'waiting for nginx…' : 'queued…'}</span>;
  if (data.status === 'failed') {
    const tail = (data.log ?? '').trim().split('\n').slice(-3).join(' ');
    return <span className="text-red">{data.label} failed{data.exit_code !== null ? ` (exit ${data.exit_code})` : ''}: {tail || 'no output'}</span>;
  }
  if (data.status === 'done') return <span className="text-green">{data.label} done</span>;
  return <span className="text-dim">running {data.label}…{isError ? ' (nginx restarting)' : ''}</span>;
}
