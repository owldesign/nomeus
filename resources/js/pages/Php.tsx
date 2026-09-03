import { useState } from 'react';
import type { PhpVersion } from '@/api/types';
import { ApiError } from '@/api/client';
import TaskProgress from '@/components/TaskProgress';
import { useCheckPhpUpdates, useNode, useNodeAction, usePhp, usePhpAction, useRefetchAfterTask } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function Row({ v, minPhp }: { v: PhpVersion; minPhp: string }) {
  const act = usePhpAction();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [confirmUse, setConfirmUse] = useState(false);
  const tooOld = v.version.localeCompare(minPhp, undefined, { numeric: true }) < 0;

  const run = (action: 'use' | 'install' | 'update') => {
    setConfirmUse(false);
    act.mutate({ version: v.version, action }, { onSuccess: (r) => setTaskId(r.task.id) });
  };

  const actions = () => {
    if (act.isPending) return <span className="text-dim">enqueuing…</span>;
    if (taskId) {
      return <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />;
    }
    if (confirmUse) {
      return (
        <>
          <span className="text-gold">switch global to {v.version}? the dashboard restarts with it.</span>{' '}
          <button type="button" className="text-red hover:underline" onClick={() => run('use')}>yes</button>{' '}
          <button type="button" className="text-dim hover:underline" onClick={() => setConfirmUse(false)}>no</button>
        </>
      );
    }
    return (
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        {!v.linked && (
          tooOld
            ? <span className="text-mute" title={`nomeus's dependencies need ${minPhp}+ on the global fpm; isolate sites to ${v.version} instead`}>use</span>
            : <button type="button" className="hover:text-gold" onClick={() => setConfirmUse(true)}>use</button>
        )}
        {v.outdated && (
          <button type="button" className="text-gold hover:underline" onClick={() => run('update')}>update → {v.outdated}</button>
        )}
        {act.isError && <span className="basis-full text-red">{errorText(act.error)}</span>}
      </div>
    );
  };

  return (
    <tr className="border-b border-dashed border-line align-top">
      <td className="py-2 pr-4 whitespace-nowrap">
        <span className={v.linked ? 'text-gold' : ''}>{v.version}</span>
        {v.linked && <span className="ml-2 text-dim" title="global (valet use)">global</span>}
      </td>
      <td className="py-2 pr-4 text-dim">{v.patch ?? '?'}</td>
      <td className="py-2 pr-4">
        <span className={`inline-block h-2 w-2 rounded-full ${v.fpm ? 'bg-green' : 'bg-mute'}`} title={v.fpm ? 'fpm answering' : 'no fpm'} />
      </td>
      <td className="py-2 pr-4 text-dim">{v.sites.length ? v.sites.join(', ') : '—'}</td>
      <td className="py-2 pr-4 break-all text-dim">{v.ini}</td>
      <td className="py-2">{actions()}</td>
    </tr>
  );
}

function Install({ installable }: { installable: string[] }) {
  const act = usePhpAction();
  const refetch = useRefetchAfterTask();
  const [pick, setPick] = useState('');
  const [taskId, setTaskId] = useState<string | null>(null);

  if (installable.length === 0) return null;

  return (
    <div className="mt-6 border border-line bg-panel px-4 py-3">
      <div className="mb-2 text-dim">install another version from the shivammathur/php tap</div>
      <div className="flex flex-wrap items-center gap-2">
        <select aria-label="PHP version to install" className="border border-line bg-bg px-2 py-1 text-fg" value={pick} onChange={(e) => setPick(e.target.value)}>
          <option value="">version…</option>
          {installable.map((v) => <option key={v} value={v}>{v}</option>)}
        </select>
        <button
          type="button"
          className="border border-line px-3 py-1 hover:border-gold hover:text-gold disabled:text-mute"
          disabled={!pick || act.isPending || taskId !== null}
          onClick={() => act.mutate({ version: pick, action: 'install' }, { onSuccess: (r) => { setTaskId(r.task.id); setPick(''); } })}
        >
          {act.isPending ? 'enqueuing…' : 'install'}
        </button>
        <span className="text-dim">brew builds nothing — bottles, but still a minute or two</span>
      </div>
      {act.isError && <div className="mt-2 text-red">{errorText(act.error)}</div>}
      {taskId && (
        <div className="mt-2">
          <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />
        </div>
      )}
    </div>
  );
}

/** Node through fnm: installed versions, the default, per-site pins; install/pin as tasks. */
function NodeSection() {
  const node = useNode();
  const act = useNodeAction();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [version, setVersion] = useState('lts');
  const run = (a: Parameters<typeof act.mutate>[0]) => act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });
  if (!node.data) return null;
  const d = node.data;
  return (
    <div className="mt-8">
      <div className="mb-2 flex items-baseline justify-between">
        <h2 className="text-[15px] font-semibold">Node <span className="text-dim font-normal">via fnm</span></h2>
        {d.fnm ? <span className="text-dim">{d.fnm}</span> : <span className="text-gold">fnm not installed — brew install fnm</span>}
      </div>
      {d.fnm && (
        <>
          <div className="flex flex-wrap items-center gap-3 border border-line bg-panel px-3 py-2">
            {d.versions.length === 0 && <span className="text-dim">no versions yet</span>}
            {d.versions.map((v) => (
              <span key={v} className={`inline-flex items-center gap-2 ${v === d.default ? 'text-green' : ''}`}>
                node {v}{v === d.default ? ' (default)' : ''}
                {v !== d.default && (
                  <button type="button" className="text-dim hover:text-gold" disabled={!!taskId} onClick={() => run({ action: 'use', version: v, default: true })} title="make default">make default</button>
                )}
              </span>
            ))}
            <span className="ml-auto inline-flex items-center gap-2">
              <input className="w-24 border border-line bg-bg px-2 py-0.5" value={version} onChange={(e) => setVersion(e.target.value)} placeholder="22 / lts" />
              <button type="button" className="border border-line px-2 py-0.5 hover:border-gold hover:text-gold disabled:text-mute" disabled={!!taskId || !version} onClick={() => run({ action: 'install', version })}>install</button>
            </span>
            {taskId && <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 3000); }} />}
          </div>
          {d.pins.length > 0 && (
            <table className="mt-2 w-full border-collapse">
              <thead><tr className="text-dim"><th className="py-1 pr-4 text-left font-normal">site</th><th className="py-1 pr-4 text-left font-normal">.nvmrc</th><th className="py-1 text-left font-normal">installed</th></tr></thead>
              <tbody>
                {d.pins.map((p) => (
                  <tr key={p.site} className="border-t border-dashed border-line">
                    <td className="py-1 pr-4">{p.site}</td>
                    <td className="py-1 pr-4">{p.pin}</td>
                    <td className="py-1">
                      {p.installed ? <span className="text-green">{p.installed}</span> : (
                        <button type="button" className="text-red hover:text-gold" disabled={!!taskId} onClick={() => run({ action: 'use', version: p.pin, site: p.site })}>not installed — install {p.pin}</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {act.isError && <div className="mt-1 text-red">{String(act.error)}</div>}
        </>
      )}
    </div>
  );
}

export default function Php() {
  const php = usePhp();
  const check = useCheckPhpUpdates();

  return (
    <div className="max-w-5xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="text-[15px] font-semibold">PHP</h1>
        <div className="flex items-center gap-4 text-dim">
          {php.data && <span>global {php.data.global ?? '?'} <span className="text-mute">· nomeus needs {php.data.min_php}+</span></span>}
          <button
            type="button"
            className="border border-line px-2 py-0.5 hover:border-gold hover:text-gold disabled:text-mute"
            disabled={check.isPending}
            onClick={() => check.mutate()}
            title="re-run brew outdated (reflects your last brew update)"
          >
            {check.isPending ? 'checking…' : 'check updates'}
          </button>
        </div>
      </div>

      {php.isLoading && <p className="text-dim">reading…</p>}
      {php.isError && <p className="text-red">{errorText(php.error)}</p>}
      {check.isError && <p className="mb-3 text-red">{errorText(check.error)}</p>}

      {php.data && (
        <>
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b border-line text-left text-dim">
                <th className="py-1 pr-4 font-normal">version</th>
                <th className="py-1 pr-4 font-normal">patch</th>
                <th className="py-1 pr-4 font-normal">fpm</th>
                <th className="py-1 pr-4 font-normal">sites</th>
                <th className="py-1 pr-4 font-normal">php.ini</th>
                <th className="py-1 font-normal">actions</th>
              </tr>
            </thead>
            <tbody>
              {php.data.installed.map((v) => <Row key={v.version} v={v} minPhp={php.data.min_php} />)}
            </tbody>
          </table>
          <Install installable={php.data.installable} />
        </>
      )}
      <NodeSection />
    </div>
  );
}
