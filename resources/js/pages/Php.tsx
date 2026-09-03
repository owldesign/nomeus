import { useState } from 'react';
import type { PhpVersion } from '@/api/types';
import { ApiError } from '@/api/client';
import Button from '@/components/Button';
import Chip from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import Led from '@/components/Led';
import Panel, { INPUT, INPUT_SM, PageHeader } from '@/components/Panel';
import Table, { CELL, CELL_FIRST, CELL_LAST, rowClass } from '@/components/Table';
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
    if (taskId) return <TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />;
    if (confirmUse) {
      return (
        <span className="inline-flex flex-wrap items-center gap-2 rounded-md border border-lantern/45 bg-lantern/8 px-2 py-1 text-xs">
          <span className="text-lantern">switch global to {v.version}? the dashboard restarts with it</span>
          <Button onClick={() => setConfirmUse(false)}>no</Button>
          <Button variant="primary" onClick={() => run('use')}>use {v.version}</Button>
        </span>
      );
    }
    return (
      <div className="flex flex-wrap items-center gap-x-1 gap-y-1">
        {!v.linked && (
          tooOld
            ? <span className="px-2 text-faint" title={`nomeus's dependencies need ${minPhp}+ on the global fpm; isolate sites to ${v.version} instead`}>use</span>
            : <Button onClick={() => setConfirmUse(true)}>use</Button>
        )}
        {v.outdated && <Button className="text-lantern" onClick={() => run('update')}>update → {v.outdated}</Button>}
        {act.isError && <span className="basis-full text-fail">{errorText(act.error)}</span>}
      </div>
    );
  };

  return (
    <tr className={rowClass({ wash: v.linked ? 'lantern' : undefined })}>
      <td className={`${CELL_FIRST} whitespace-nowrap`}>
        <span className={`font-medium ${v.linked ? 'text-lantern' : 'text-text'}`}>{v.version}</span>
        {v.linked && <Chip tint="lantern" className="ml-2">global</Chip>}
      </td>
      <td className={`${CELL} text-dim`}>{v.patch ?? '?'}</td>
      <td className={CELL}><span className="inline-flex items-center gap-1.5"><Led state={v.fpm ? 'running' : 'stopped'} title={v.fpm ? 'php-fpm answering' : 'no php-fpm'} /><span className={v.fpm ? 'text-mid' : 'text-faint'}>{v.fpm ? 'fpm' : '—'}</span></span></td>
      <td className={`${CELL} text-dim`}>{v.sites.length ? v.sites.join(', ') : <span className="text-faint">—</span>}</td>
      <td className={`${CELL} text-faint`}><code className="text-xs">{v.ini.replace(/^\/opt\/homebrew\//, '…/')}</code></td>
      <td className={CELL_LAST}>{actions()}</td>
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
    <div className="flex flex-wrap items-center gap-2 border-t border-line/55 px-4 py-3">
      <span className="text-xs text-dim">install another version from the shivammathur/php tap</span>
      <select aria-label="PHP version to install" className={`${INPUT} py-[5px]`} value={pick} onChange={(e) => setPick(e.target.value)}>
        <option value="">version…</option>
        {installable.map((v) => <option key={v} value={v}>{v}</option>)}
      </select>
      <Button variant="primary" disabled={!pick || act.isPending || taskId !== null} onClick={() => act.mutate({ version: pick, action: 'install' }, { onSuccess: (r) => { setTaskId(r.task.id); setPick(''); } })}>
        {act.isPending ? 'enqueuing…' : 'install'}
      </Button>
      <span className="text-xs text-faint">bottles, but still a minute or two</span>
      {act.isError && <div className="basis-full text-fail">{errorText(act.error)}</div>}
      {taskId && <div className="basis-full"><TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} /></div>}
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
    <Panel className="mt-6" title={<>node <span className="normal-case tracking-normal text-faint">via fnm</span></>} actions={d.fnm ? <span className="text-xs text-faint">{d.fnm}</span> : <span className="text-xs text-warn">fnm not installed — brew install fnm</span>}>
      {d.fnm && (
        <>
          <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            {d.versions.length === 0 && <span className="text-dim">no versions yet</span>}
            {d.versions.map((v) => (
              <span key={v} className="inline-flex items-center gap-1.5">
                {v === d.default ? <Chip tint="ok">node {v} · default</Chip> : <Chip tint="neutral">node {v}</Chip>}
                {v !== d.default && <Button className="text-xs" disabled={!!taskId} onClick={() => run({ action: 'use', version: v, default: true })}>make default</Button>}
              </span>
            ))}
            <span className="ml-auto inline-flex items-center gap-2">
              <input className={`${INPUT_SM} w-24`} value={version} onChange={(e) => setVersion(e.target.value)} placeholder="22 / lts" />
              <Button variant="primary" disabled={!!taskId || !version} onClick={() => run({ action: 'install', version })}>install</Button>
            </span>
            {taskId && <div className="basis-full"><TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 3000); }} /></div>}
          </div>
          {d.pins.length > 0 && (
            <Table columns={['site', '.nvmrc', 'installed']}>
              {d.pins.map((p) => (
                <tr key={p.site} className={rowClass()}>
                  <td className={`${CELL_FIRST} text-text`}>{p.site}</td>
                  <td className={`${CELL} text-mid`}>{p.pin}</td>
                  <td className={CELL_LAST}>
                    {p.installed ? <Chip tint="ok">{p.installed}</Chip> : (
                      <Button className="text-fail" disabled={!!taskId} onClick={() => run({ action: 'use', version: p.pin, site: p.site })}>not installed — install {p.pin}</Button>
                    )}
                  </td>
                </tr>
              ))}
            </Table>
          )}
          {act.isError && <div className="px-4 pb-3 text-fail">{String(act.error)}</div>}
        </>
      )}
    </Panel>
  );
}

export default function Php() {
  const php = usePhp();
  const check = useCheckPhpUpdates();

  return (
    <div className="max-w-5xl">
      <PageHeader
        title="PHP"
        summary={php.data && <span>global <span className="text-lantern">{php.data.global ?? '?'}</span> <span className="text-faint">· nomeus needs {php.data.min_php}+</span></span>}
        actions={<Button disabled={check.isPending} onClick={() => check.mutate()} title="re-run brew outdated (reflects your last brew update)">{check.isPending ? 'checking…' : 'check updates'}</Button>}
      />

      {php.isLoading && <p className="text-dim">reading…</p>}
      {php.isError && <p className="text-fail">{errorText(php.error)}</p>}
      {check.isError && <p className="mb-3 text-fail">{errorText(check.error)}</p>}

      {php.data && php.data.installed.length === 0 && (
        <Panel><EmptyState title="No PHP from brew yet" line="Valet serves through brew's php@X.Y kegs." command="nomeus php:install 8.4" /></Panel>
      )}
      {php.data && php.data.installed.length > 0 && (
        <Panel>
          <Table columns={['version', 'patch', 'fpm', 'sites', 'php.ini', 'actions']}>
            {php.data.installed.map((v) => <Row key={v.version} v={v} minPhp={php.data.min_php} />)}
          </Table>
          <Install installable={php.data.installable} />
        </Panel>
      )}
      <NodeSection />
    </div>
  );
}
