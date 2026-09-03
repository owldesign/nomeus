import { Fragment, useEffect, useState } from 'react';
import type { Task } from '@/api/types';
import Button from '@/components/Button';
import Chip, { type Tint } from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import Panel, { PageHeader } from '@/components/Panel';
import Table, { CELL, CELL_FIRST, CELL_LAST, rowClass } from '@/components/Table';
import { useTask, useTasks } from '@/hooks/useApi';

const TINT: Record<Task['status'], Tint> = { queued: 'neutral', running: 'lantern', done: 'ok', failed: 'fail' };

function duration(t: Task) {
  if (!t.started_at) return '';
  const end = t.finished_at ? new Date(t.finished_at) : new Date();
  const s = Math.max(0, Math.round((end.getTime() - new Date(t.started_at).getTime()) / 1000));
  return `${s}s`;
}

/** The task's log as an inset well (spec §5); still polling while it runs. */
function Log({ id }: { id: string }) {
  const { data } = useTask(id);
  const running = data?.status === 'running' || data?.status === 'queued';
  return (
    <pre className="m-0 max-h-80 overflow-auto rounded-md bg-inset px-3 py-2 text-xs leading-[1.7] text-mid">
      {data?.log?.trim() || <span className="text-faint">(no output)</span>}
      {running && <span className="text-lantern animate-blink">▍</span>}
    </pre>
  );
}

export default function Tasks() {
  const tasks = useTasks();
  const [open, setOpen] = useState<string | null>(() => window.location.hash.slice(1) || null);
  useEffect(() => { if (open) window.history.replaceState(null, '', `#${open}`); }, [open]);
  const running = tasks.data?.filter((t) => t.status === 'running').length ?? 0;

  return (
    <div className="max-w-5xl">
      <PageHeader title="Tasks" summary="background commands run by dashboard actions" actions={running > 0 && <Chip tint="lantern" glow>{running} running</Chip>} />

      {tasks.data && tasks.data.length === 0 && (
        <Panel><EmptyState title="Nothing has run yet" line="Secure, isolate or link a site, start a service — every dashboard action lands here with its log." command="nomeus tasks" /></Panel>
      )}

      {tasks.data && tasks.data.length > 0 && (
        <Panel>
          <Table columns={['when', 'status', 'command', 'took', '']}>
            {tasks.data.map((t) => {
              const last = t.status === 'failed' ? (t.log ?? '').trim().split('\n').filter(Boolean).slice(-1)[0] : null;
              return (
                <Fragment key={t.id}>
                  <tr id={t.id} className={rowClass({ wash: t.status === 'running' ? 'lantern' : undefined, clickable: true })} onClick={() => setOpen(open === t.id ? null : t.id)}>
                    <td className={`${CELL_FIRST} whitespace-nowrap text-dim`}>{new Date(t.created_at).toLocaleTimeString()}</td>
                    <td className={CELL}><Chip tint={TINT[t.status]} glow={t.status === 'running'}>{t.status}{t.status === 'failed' && t.exit_code !== null ? ` · exit ${t.exit_code}` : ''}</Chip></td>
                    <td className={`${CELL} text-mid`}>
                      {t.label}{t.cwd && <span className="text-faint"> in {t.cwd}</span>}
                      {last && <span className="ml-2 text-xs text-dim">{last}</span>}
                    </td>
                    <td className={`${CELL} whitespace-nowrap text-dim`}>{duration(t)}</td>
                    <td className={CELL_LAST}><Button onClick={(e) => { e.stopPropagation(); setOpen(open === t.id ? null : t.id); }}>{open === t.id ? 'hide' : 'log'}</Button></td>
                  </tr>
                  {open === t.id && (
                    <tr className="border-b border-line/55 last:border-0">
                      <td colSpan={5} className="bg-inset/60 px-4 py-3"><Log id={t.id} /></td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </Table>
        </Panel>
      )}
    </div>
  );
}
