import { Fragment, useState } from 'react';
import type { Task } from '@/api/types';
import { useTask, useTasks } from '@/hooks/useApi';

const statusColor: Record<Task['status'], string> = {
  queued: 'text-dim',
  running: 'text-gold',
  done: 'text-green',
  failed: 'text-red',
};

function duration(t: Task) {
  if (!t.started_at) return '';
  const end = t.finished_at ? new Date(t.finished_at) : new Date();
  const s = Math.max(0, Math.round((end.getTime() - new Date(t.started_at).getTime()) / 1000));
  return `${s}s`;
}

function Log({ id }: { id: string }) {
  const { data } = useTask(id);
  return (
    <pre className="mt-2 max-h-80 overflow-auto border border-line bg-bg p-3 text-dim">
      {data?.log?.trim() || '(no output)'}
    </pre>
  );
}

export default function Tasks() {
  const tasks = useTasks();
  const [open, setOpen] = useState<string | null>(null);

  return (
    <div className="max-w-5xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="text-[15px] font-semibold">Tasks</h1>
        <span className="text-dim">background commands run by dashboard actions</span>
      </div>

      {tasks.data && tasks.data.length === 0 && (
        <p className="text-dim">Nothing yet. Secure, isolate or link a site and it shows up here.</p>
      )}

      {tasks.data && tasks.data.length > 0 && (
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-line text-left text-dim">
              <th className="py-1 pr-4 font-normal">when</th>
              <th className="py-1 pr-4 font-normal">status</th>
              <th className="py-1 pr-4 font-normal">command</th>
              <th className="py-1 pr-4 font-normal">took</th>
              <th className="py-1 font-normal"></th>
            </tr>
          </thead>
          <tbody>
            {tasks.data.map((t) => (
              <Fragment key={t.id}>
                <tr className="border-b border-dashed border-line">
                  <td className="py-2 pr-4 whitespace-nowrap text-dim">{new Date(t.created_at).toLocaleTimeString()}</td>
                  <td className={`py-2 pr-4 ${statusColor[t.status]}`}>{t.status}</td>
                  <td className="py-2 pr-4">{t.label}{t.cwd && <span className="text-dim"> in {t.cwd}</span>}</td>
                  <td className="py-2 pr-4 text-dim">{duration(t)}</td>
                  <td className="py-2">
                    <button type="button" className="text-blue hover:underline" onClick={() => setOpen(open === t.id ? null : t.id)}>
                      {open === t.id ? 'hide' : 'log'}
                    </button>
                  </td>
                </tr>
                {open === t.id && (
                  <tr>
                    <td colSpan={5} className="pb-3"><Log id={t.id} /></td>
                  </tr>
                )}
              </Fragment>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
