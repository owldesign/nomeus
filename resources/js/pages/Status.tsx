import { useState, type ReactNode } from 'react';
import type { DoctorRow } from '@/api/types';
import Button from '@/components/Button';
import Chip from '@/components/Chip';
import Kbd from '@/components/Kbd';
import Led from '@/components/Led';
import TaskProgress from '@/components/TaskProgress';
import { useDoctor, useDoctorFix, useSelfUpdate, useStatus } from '@/hooks/useApi';

function Row({ k, children }: { k: string; children: ReactNode }) {
  return (
    <div className="grid min-h-9 grid-cols-[120px_1fr] items-center gap-4 border-b border-line/55 py-1.5 last:border-0">
      <div className="t-sans text-2xs font-semibold uppercase tracking-[0.12em] text-dim">{k}</div>
      <div className="min-w-0 break-all text-mid">{children}</div>
    </div>
  );
}

function State({ on, children }: { on: boolean; children?: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-2">
      <Led state={on ? 'running' : 'stopped'} />
      <span className={on ? 'text-text' : 'text-faint'}>{on ? 'running' : 'stopped'}</span>
      {children && <span className="text-mid">{children}</span>}
    </span>
  );
}

/** A `fix:` line under a non-OK doctor row: the exact command as a pill, and a button that runs it as a task (spec §10). */
function FixLine({ detail }: { detail: string }) {
  const fix = useDoctorFix();
  const [taskId, setTaskId] = useState<string | null>(null);
  const cmd = extractCommand(detail);
  if (!cmd) return null;
  if (taskId) return <TaskProgress id={taskId} compact onFinished={() => setTimeout(() => setTaskId(null), 4000)} />;
  return (
    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
      <span className="text-dim">fix:</span>
      <Kbd>{cmd}</Kbd>
      {fix.isError && <span className="text-fail">{String(fix.error)}</span>}
      <button type="button" className="rounded-md border border-line px-2 py-[2px] text-xs text-dim hover:border-lantern hover:text-lantern" disabled={fix.isPending} onClick={() => fix.mutate(cmd, { onSuccess: (r) => setTaskId(r.task.id) })}>
        run fix
      </button>
    </div>
  );
}

/** The first `nomeus …` command in a doctor detail (they are written to carry exactly one). */
function extractCommand(detail: string): string | null {
  const m = detail.match(/nomeus [a-z][a-z0-9:-]*(?: [^\s—·(]+)*/);
  return m ? m[0].trim() : null;
}

/** Every layer, one glance (spec §10): counts up top, problems listed with their fix, everything else behind a toggle. */
function DoctorPanel() {
  const { data, isLoading, refetch } = useDoctor();
  const [showOk, setShowOk] = useState(false);
  if (isLoading || !data) return <p className="text-dim">doctor…</p>;
  const problems = data.rows.filter((r) => r.level !== 'ok');
  const shown: DoctorRow[] = showOk ? data.rows : problems;
  return (
    <div className="mt-6 rounded-lg border border-line bg-panel shadow-panel">
      <div className="flex flex-wrap items-center gap-4 border-b border-line/55 px-4 py-2">
        <span className="t-sans text-2xs font-semibold uppercase tracking-[0.14em] text-dim">doctor</span>
        <Chip tint="ok">{data.counts.ok} ok</Chip>
        <Chip tint={data.counts.warn ? 'warn' : 'neutral'}>{data.counts.warn} warn</Chip>
        <Chip tint={data.counts.fail ? 'fail' : 'neutral'}>{data.counts.fail} fail</Chip>
        <span className="ml-auto inline-flex gap-1">
          <Button onClick={() => setShowOk(!showOk)}>{showOk ? 'problems only' : 'show all'}</Button>
          <Button onClick={() => refetch()}>re-check</Button>
        </span>
      </div>
      {shown.length === 0 && <p className="px-4 py-3 text-ok">nothing to report</p>}
      {shown.map((r, i) => (
        <div key={i} className="border-b border-line/55 px-4 py-[9px] last:border-0">
          <div className="flex items-baseline gap-3">
            <Chip tint={r.level === 'ok' ? 'ok' : r.level === 'warn' ? 'warn' : 'fail'}>{r.level.toUpperCase()}</Chip>
            <span className={r.level === 'ok' ? 'text-mid' : 'text-text'}>{r.detail}</span>
            <span className="ml-auto whitespace-nowrap text-xs text-dim">{r.section} · {r.check}</span>
          </div>
          {r.level !== 'ok' && <FixLine detail={r.detail} />}
        </div>
      ))}
    </div>
  );
}

function UpdateButton() {
  const update = useSelfUpdate();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  if (taskId) {
    return <TaskProgress id={taskId} compact onFinished={() => setTimeout(() => window.location.reload(), 2500)} />;   // the bundle this page came from was just replaced
  }
  if (confirm) {
    return (
      <span className="inline-flex items-center gap-2 rounded-md border border-lantern/45 bg-lantern/8 px-2 py-1 text-xs">
        <span className="text-lantern">pull · deps · rebuild · ini · doctor — the page reloads when done</span>
        <Button onClick={() => setConfirm(false)}>no</Button>
        <Button variant="primary" disabled={update.isPending} onClick={() => update.mutate({}, { onSuccess: (r) => setTaskId(r.task.id) })}>update</Button>
      </span>
    );
  }
  return <Button variant="primary" onClick={() => setConfirm(true)} title="git pull --ff-only · composer install · npm run build · dumps:install · doctor">update nomeus</Button>;
}

export default function Status() {
  const { data, error, isLoading, dataUpdatedAt } = useStatus();
  const [raw, setRaw] = useState(false);

  if (isLoading) return <p className="text-dim">reading…</p>;
  if (error || !data) return <p className="text-fail">{String(error ?? 'no data')}</p>;

  const { nomeus, valet, php, services, dashboard } = data;

  return (
    <div className="max-w-3xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="t-sans text-xl font-semibold text-text">Status</h1>
        <div className="flex items-center gap-4 text-dim">
          <UpdateButton />
          <span>polled {new Date(dataUpdatedAt).toLocaleTimeString()}</span>
          <Button onClick={() => setRaw((v) => !v)}>{raw ? 'table' : 'json'}</Button>
        </div>
      </div>

      {raw ? (
        <pre className="overflow-x-auto rounded-md border border-line bg-inset p-3 text-xs text-mid">{JSON.stringify(data, null, 2)}</pre>
      ) : (
        <div className="rounded-lg border border-line bg-panel px-4 py-1 shadow-panel">
          <Row k="home">{nomeus.home}</Row>
          <Row k="config">
            {nomeus.config_path}
            {!nomeus.config_exists && <span className="text-warn"> — missing, run install/install.sh</span>}
          </Row>
          <Row k="code dir">{nomeus.code_dir}</Row>
          <Row k="valet">
            {valet.installed ? (
              <>
                {valet.version ?? '?'} <span className="text-dim">tld</span> .{valet.tld}{' '}
                <span className="text-dim">loopback</span> {valet.loopback}{' '}
                {valet.trusted ? (
                  <Chip tint="ok">trusted</Chip>
                ) : (
                  <span className="text-warn">not trusted — run nomeus trust for dashboard actions</span>
                )}
              </>
            ) : (
              <span className="text-fail">not installed</span>
            )}
          </Row>
          <Row k="parked">
            {valet.paths.length ? valet.paths.join('  ') : <span className="text-warn">none — valet park</span>}
          </Row>
          <Row k="php">{php.global ? <span className="text-lantern">{php.global}</span> : <span className="text-fail">not on PATH</span>}</Row>
          <Row k="nginx"><State on={services.nginx} /></Row>
          <Row k="dnsmasq"><State on={services.dnsmasq} /></Row>
          <Row k="php-fpm"><State on={services.php_fpm.length > 0}>{services.php_fpm.join(', ')}</State></Row>
          <Row k="mailpit"><State on={services.mailpit} /></Row>
          <Row k="dashboard">
            <a href={dashboard.url}>{dashboard.url}</a>{' '}
            {dashboard.linked ? (
              <Chip tint="ok">linked</Chip>
            ) : (
              <span className="text-warn">not linked — nomeus link nomeus</span>
            )}
          </Row>
        </div>
      )}
      <DoctorPanel />
    </div>
  );
}
