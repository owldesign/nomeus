import { useState, type ReactNode } from 'react';
import type { DoctorRow } from '@/api/types';
import TaskProgress from '@/components/TaskProgress';
import { useDoctor, useSelfUpdate, useStatus } from '@/hooks/useApi';

function Row({ k, children }: { k: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[120px_1fr] gap-4 border-b border-dashed border-line py-2 last:border-0">
      <div className="text-dim">{k}</div>
      <div className="min-w-0 break-all">{children}</div>
    </div>
  );
}

function State({ on, children }: { on: boolean; children?: ReactNode }) {
  return (
    <span className={on ? 'text-green' : 'text-red'}>
      {on ? 'running' : 'stopped'}
      {children && <span className="text-fg"> {children}</span>}
    </span>
  );
}

const levelClass = { ok: 'text-green', warn: 'text-gold', fail: 'text-red' } as const;

/** Every layer, one glance: counts up top, problems listed, everything else behind a toggle. */
function DoctorPanel() {
  const { data, isLoading, refetch } = useDoctor();
  const [showOk, setShowOk] = useState(false);
  if (isLoading || !data) return <p className="text-dim">doctor…</p>;
  const problems = data.rows.filter((r) => r.level !== 'ok');
  const shown: DoctorRow[] = showOk ? data.rows : problems;
  return (
    <div className="mt-6 border border-line bg-panel px-4 py-3">
      <div className="mb-2 flex flex-wrap items-baseline gap-4">
        <span className="text-dim">doctor</span>
        <span className="text-green">{data.counts.ok} ok</span>
        <span className={data.counts.warn ? 'text-gold' : 'text-dim'}>{data.counts.warn} warn</span>
        <span className={data.counts.fail ? 'text-red' : 'text-dim'}>{data.counts.fail} fail</span>
        <button type="button" className="text-dim hover:text-gold" onClick={() => setShowOk(!showOk)}>{showOk ? 'problems only' : 'show all'}</button>
        <button type="button" className="text-dim hover:text-gold" onClick={() => refetch()}>re-check</button>
      </div>
      {shown.length === 0 && <p className="text-green">nothing to report</p>}
      {shown.map((r, i) => (
        <div key={i} className="grid grid-cols-[52px_90px_1fr] gap-3 border-t border-dashed border-line py-1">
          <span className={levelClass[r.level]}>{r.level.toUpperCase()}</span>
          <span className="text-dim">{r.section} · {r.check}</span>
          <span className="break-words">{r.detail}</span>
        </div>
      ))}
    </div>
  );
}

function UpdateButton() {
  const update = useSelfUpdate();
  const [taskId, setTaskId] = useState<string | null>(null);
  if (taskId) {
    return (
      <TaskProgress
        id={taskId}
        onFinished={() => setTimeout(() => window.location.reload(), 2500)}   // the bundle this page came from was just replaced
      />
    );
  }
  return (
    <button
      type="button"
      className="border border-line px-2 py-0.5 hover:border-gold hover:text-gold disabled:text-mute"
      disabled={update.isPending}
      title="git pull --ff-only · composer install · npm run build · dumps:install · doctor"
      onClick={() => { if (confirm('Update devkit now? (pull, deps, rebuild — the dashboard reloads when done)')) update.mutate({}, { onSuccess: (r) => setTaskId(r.task.id) }); }}
    >
      {update.isPending ? 'enqueuing…' : 'update devkit'}
    </button>
  );
}

export default function Status() {
  const { data, error, isLoading, dataUpdatedAt } = useStatus();
  const [raw, setRaw] = useState(false);

  if (isLoading) return <p className="text-dim">reading…</p>;
  if (error || !data) return <p className="text-red">{String(error ?? 'no data')}</p>;

  const { devkit, valet, php, services, dashboard } = data;

  return (
    <div className="max-w-3xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="text-[15px] font-semibold">Status</h1>
        <div className="flex items-center gap-4 text-dim">
          <UpdateButton />
          <span>polled {new Date(dataUpdatedAt).toLocaleTimeString()}</span>
          <button
            type="button"
            onClick={() => setRaw((v) => !v)}
            className="border border-line px-2 py-0.5 hover:border-gold hover:text-gold"
          >
            {raw ? 'table' : 'json'}
          </button>
        </div>
      </div>

      {raw ? (
        <pre className="overflow-x-auto border border-line bg-panel p-3 text-dim">{JSON.stringify(data, null, 2)}</pre>
      ) : (
        <div className="border border-line bg-panel px-4 py-1">
          <Row k="home">{devkit.home}</Row>
          <Row k="config">
            {devkit.config_path}
            {!devkit.config_exists && <span className="text-gold"> — missing, run install/install.sh</span>}
          </Row>
          <Row k="code dir">{devkit.code_dir}</Row>
          <Row k="valet">
            {valet.installed ? (
              <>
                {valet.version ?? '?'} <span className="text-dim">tld</span> .{valet.tld}{' '}
                <span className="text-dim">loopback</span> {valet.loopback}{' '}
                {valet.trusted ? (
                  <span className="text-green">trusted</span>
                ) : (
                  <span className="text-gold">not trusted — run devkit trust for dashboard actions</span>
                )}
              </>
            ) : (
              <span className="text-red">not installed</span>
            )}
          </Row>
          <Row k="parked">
            {valet.paths.length ? valet.paths.join('  ') : <span className="text-gold">none — valet park</span>}
          </Row>
          <Row k="php">{php.global ?? <span className="text-red">not on PATH</span>}</Row>
          <Row k="nginx"><State on={services.nginx} /></Row>
          <Row k="dnsmasq"><State on={services.dnsmasq} /></Row>
          <Row k="php-fpm"><State on={services.php_fpm.length > 0}>{services.php_fpm.join(', ')}</State></Row>
          <Row k="mailpit"><State on={services.mailpit} /></Row>
          <Row k="dashboard">
            <a href={dashboard.url}>{dashboard.url}</a>{' '}
            {dashboard.linked ? (
              <span className="text-green">linked</span>
            ) : (
              <span className="text-gold">not linked — devkit link devkit</span>
            )}
          </Row>
        </div>
      )}
      <DoctorPanel />
    </div>
  );
}
