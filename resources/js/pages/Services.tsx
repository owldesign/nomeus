import { Fragment, useState } from 'react';
import type { ServiceInstance, ServiceType } from '@/api/types';
import { ApiError } from '@/api/client';
import { copyText } from '@/lib/clipboard';
import Button, { ConfirmInline } from '@/components/Button';
import Chip from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import Led, { ledStateFor } from '@/components/Led';
import TaskProgress from '@/components/TaskProgress';
import Panel, { INPUT, LABEL, PageHeader } from '@/components/Panel';
import Table, { CELL, CELL_FIRST, CELL_LAST, rowClass } from '@/components/Table';
import { useAdopt, useAdoptable, useCreateService, useRefetchAfterTask, useService, useServiceAction, useServiceTypes, useServices, useSites } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function EnvBlock({ env }: { env: Record<string, string> }) {
  const text = Object.entries(env).map(([k, v]) => `${k}=${v}`).join('\n');
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const copy = async () => {
    setState((await copyText(text)) ? 'copied' : 'failed');
    setTimeout(() => setState('idle'), 1500);
  };
  return (
    <div className="relative">
      <pre className="m-0 select-all rounded-md bg-inset px-3 py-2 text-xs leading-[1.7]">
        {Object.entries(env).map(([k, v]) => <span key={k}><span className="text-dim">{k}</span><span className="text-faint">=</span><span className="text-mid">{v}</span>{'\n'}</span>)}
      </pre>
      <div className="absolute right-2 top-1.5">
        <Button className={state === 'failed' ? 'text-fail' : state === 'copied' ? 'text-ok' : ''} onClick={copy}>
          {state === 'copied' ? 'copied' : state === 'failed' ? 'copy failed — select the block' : 'copy .env'}
        </Button>
      </div>
    </div>
  );
}

/** The expand well (spec §3): .env keys/values and the log tail with a live caret, on `inset`. */
function Detail({ name }: { name: string }) {
  const { data } = useService(name);
  if (!data) return <p className="text-dim">reading…</p>;
  const log = (data.log ?? '').trim();
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <div>
        <div className={LABEL}>.env for a site using it</div>
        <EnvBlock env={data.env} />
        <div className="mt-2 text-xs text-faint">data {data.dir}/data</div>
      </div>
      <div>
        <div className={LABEL}>log</div>
        <pre className="m-0 max-h-64 overflow-auto rounded-md bg-inset px-3 py-2 text-xs leading-[1.7] text-mid">
          {log ? log.split('\n').map((l, i) => <span key={i} className={/error|fatal|panic/i.test(l) ? 'text-fail' : ''}>{l}{'\n'}</span>) : <span className="text-faint">(nothing yet)</span>}
          <span className="text-lantern animate-blink">▍</span>
        </pre>
      </div>
    </div>
  );
}

/** Spec §3: 36px rows, hover wash, crash-looping wash across the row, stopped rows go faint except `start`. */
function Row({ i, open, onToggle }: { i: ServiceInstance; open: boolean; onToggle: () => void }) {
  const act = useServiceAction();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [mode, setMode] = useState<'idle' | 'clone'>('idle');
  const [cloneName, setCloneName] = useState(`${i.name}-copy`);
  const [clonePort, setClonePort] = useState('');
  const [keepData, setKeepData] = useState(false);
  const state = ledStateFor(i.status);
  const stopped = state === 'stopped' || state === 'missing';

  const run = (a: Parameters<typeof act.mutate>[0]) => {
    setMode('idle');
    act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });
  };

  const actions = () => {
    if (act.isPending) return <span className="text-dim">enqueuing…</span>;
    if (taskId) return <TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />;
    if (mode === 'clone') {
      return (
        <span className="inline-flex flex-wrap items-center gap-2">
          <input aria-label="Clone name" className={`${INPUT} w-36 py-[3px]`} value={cloneName} onChange={(e) => setCloneName(e.target.value)} />
          <input aria-label="Clone port" className={`${INPUT} w-20 py-[3px]`} placeholder="port" value={clonePort} onChange={(e) => setClonePort(e.target.value)} />
          <Button variant="primary" onClick={() => run({ name: i.name, action: 'clone', newName: cloneName, port: clonePort ? Number(clonePort) : undefined })}>clone</Button>
          <Button onClick={() => setMode('idle')}>cancel</Button>
        </span>
      );
    }
    return (
      <div className="flex flex-wrap items-center gap-x-1 gap-y-1" onClick={(e) => e.stopPropagation()}>
        {i.status.running || i.status.loaded
          ? <Button onClick={() => run({ name: i.name, action: 'stop' })}>stop</Button>
          : <Button className={stopped ? 'text-ok' : ''} onClick={() => run({ name: i.name, action: 'start' })}>start</Button>}
        <Button onClick={() => run({ name: i.name, action: 'restart' })}>restart</Button>
        <Button onClick={() => setMode('clone')}>clone</Button>
        <ConfirmInline
          question={`delete ${i.name}?`}
          action="delete"
          onConfirm={() => run({ name: i.name, action: 'delete', keepData })}
          extra={<label className="inline-flex items-center gap-1 text-xs text-dim"><input type="checkbox" checked={keepData} onChange={(e) => setKeepData(e.target.checked)} /> keep data</label>}
        />
        {act.isError && <span className="basis-full text-fail">{errorText(act.error)}</span>}
      </div>
    );
  };

  const faint = stopped ? 'text-faint' : '';
  return (
    <Fragment>
      <tr className={rowClass({ wash: state === 'crashing' ? 'fail' : undefined, clickable: true })} onClick={onToggle}>
        <td className={`${CELL_FIRST} w-12 whitespace-nowrap`}>
          <span className="mr-1 text-2xs text-faint">{open ? '▾' : '▸'}</span>
          <Led state={state} title={state === 'crashing' ? `crash-looping (last exit ${i.status.last_exit}) — expand for the log` : undefined} />
        </td>
        <td className={`${CELL} whitespace-nowrap font-medium ${stopped ? 'text-faint' : 'text-text'}`}>{i.name}</td>
        <td className={`${CELL} whitespace-nowrap ${stopped ? 'text-faint' : 'text-dim'}`}>{i.type} <span className="text-faint">{i.formula}</span></td>
        <td className={`${CELL} ${stopped ? 'text-faint' : 'text-dim'}`}>{i.version}</td>
        <td className={`${CELL} ${faint || 'text-text'}`}>{i.port}</td>
        <td className={`${CELL} ${faint || 'text-dim'}`}>{i.status.pid ?? (i.status.crashing ? <span className="text-fail">crash · exit {i.status.last_exit}</span> : '—')}</td>
        <td className={CELL_LAST}>{actions()}</td>
      </tr>
      {open && (
        <tr className="border-b border-line/55">
          <td colSpan={7} className="bg-inset/60 py-3 pl-12 pr-4"><Detail name={i.name} /></td>
        </tr>
      )}
    </Fragment>
  );
}

function CreateForm({ types, existing }: { types: ServiceType[]; existing: ServiceInstance[] }) {
  const create = useCreateService();
  const refetch = useRefetchAfterTask();
  const sites = useSites();
  const [type, setType] = useState(types[0]?.type ?? '');
  const [version, setVersion] = useState('');
  const [name, setName] = useState('');
  const [port, setPort] = useState('');
  const [site, setSite] = useState('');
  const [start, setStart] = useState(true);
  const [taskId, setTaskId] = useState<string | null>(null);

  const t = types.find((x) => x.type === type);
  const siteSites = (sites.data ?? []).filter((s) => s.type !== 'proxy');
  const usedNames = existing.map((e) => e.name);
  const suggestedName = !usedNames.includes(type) ? type : `${type}-${[2, 3, 4, 5].find((n) => !usedNames.includes(`${type}-${n}`)) ?? '?'}`;
  const submit = () => {
    create.mutate(
      { type, version: version || undefined, name: name || undefined, port: port ? Number(port) : undefined, start, site: t?.requires_site ? site : undefined },
      { onSuccess: (r) => { setTaskId(r.task.id); setName(''); setPort(''); } },
    );
  };

  return (
    <Panel className="mt-6" title="create a service instance">
      <div className="flex flex-wrap items-center gap-2 px-4 pt-3">
        <select aria-label="Type" className={INPUT} value={type} onChange={(e) => { setType(e.target.value); setVersion(''); }}>
          {types.map((x) => <option key={x.type} value={x.type}>{x.label}</option>)}
        </select>
        {t?.requires_site ? (
          <select aria-label="Site" className={INPUT} value={site} onChange={(e) => setSite(e.target.value)}>
            <option value="">site… ({t.site_package} must be installed there)</option>
            {siteSites.map((s) => <option key={s.name} value={s.name}>{s.name}</option>)}
          </select>
        ) : (
          <select aria-label="Version" className={INPUT} value={version} onChange={(e) => setVersion(e.target.value)}>
            <option value="">{t?.formulae[0]?.formula ?? 'version'} (default)</option>
            {t?.formulae.map((f) => (
              <option key={f.formula} value={f.formula}>{f.formula}{f.installed ? ` · installed ${f.version ?? ''}` : ' · will install'}</option>
            ))}
          </select>
        )}
        <input aria-label="Name" className={`${INPUT} w-36`} placeholder={suggestedName} value={name} onChange={(e) => setName(e.target.value)} />
        <input aria-label="Port" className={`${INPUT} w-24`} placeholder={String(t?.default_port ?? '')} value={port} onChange={(e) => setPort(e.target.value)} />
        <label className="inline-flex items-center gap-1 text-xs text-dim"><input type="checkbox" checked={start} onChange={(e) => setStart(e.target.checked)} /> start now</label>
        <span className="ml-auto">
          <Button variant="primary" disabled={!type || (t?.requires_site && !site) || create.isPending || taskId !== null} onClick={submit}>
            {create.isPending ? 'enqueuing…' : 'create'}
          </Button>
        </span>
      </div>
      <div className="px-4 pb-3 pt-2 text-xs text-faint">placeholders show what nomeus picks when left blank; the port is the standard one when free, otherwise the next free · <span className="text-dim">nomeus services:create {type}{version ? ` ${version.replace(/^.*@/, '')}` : ''}{name ? ` --name=${name}` : ''}{port ? ` --port=${port}` : ''}{start ? '' : ' --no-start'}</span></div>
      {create.isError && <div className="px-4 pb-3 text-fail">{errorText(create.error)}</div>}
      {taskId && (
        <div className="px-4 pb-3">
          <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />
        </div>
      )}
    </Panel>
  );
}

function AdoptPanel() {
  const adoptable = useAdoptable();
  const adopt = useAdopt();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  if (!adoptable.data || adoptable.data.length === 0) return null;

  return (
    <Panel className="mt-6" accent title={<span className="text-lantern">running under brew services</span>}>
      <div className="px-4 pt-3 text-xs text-dim">nomeus can take these over on their standard ports. The data is copied; brew's copy stays where it is until you remove it.</div>
      <table className="mt-2 w-full border-collapse">
        <tbody>
          {adoptable.data.map((s) => (
            <tr key={s.formula} className={rowClass()}>
              <td className={`${CELL_FIRST} text-text`}>{s.formula} <span className="text-dim">{s.type}</span></td>
              <td className="pr-4"><span className="inline-flex items-center gap-2"><Led state={s.loaded ? 'running' : 'stopped'} />{s.loaded ? <span className="text-mid">running</span> : <span className="text-dim">{s.plist ? 'stopped, starts at login' : 'stopped'}</span>}</span></td>
              <td className="py-1 pr-4 text-dim">{s.port}{s.answering === false ? ' (silent)' : ''}</td>
              <td className="py-1 pr-4 break-all text-dim">{s.data_dir}</td>
              <td className="py-1">
                {taskId && busy === s.formula
                  ? <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => { setTaskId(null); setBusy(null); }, 4000); }} />
                  : (
                    <Button variant="primary" disabled={adopt.isPending || taskId !== null} onClick={() => { setBusy(s.formula); adopt.mutate({ formula: s.formula }, { onSuccess: (r) => setTaskId(r.task.id) }); }}>adopt</Button>
                  )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adopt.isError && <div className="px-4 pb-3 text-fail">{errorText(adopt.error)}</div>}
    </Panel>
  );
}

export default function Services() {
  const services = useServices();
  const types = useServiceTypes();
  const [open, setOpen] = useState<string | null>(null);
  const running = services.data?.filter((s) => s.status.running).length ?? 0;

  return (
    <div className="max-w-6xl">
      <PageHeader title="Services" actions={services.data && <Chip tint={running === services.data.length ? 'ok' : running ? 'warn' : 'neutral'}>{running}/{services.data.length} running</Chip>} />

      {services.isLoading && <p className="text-dim">reading…</p>}
      {services.isError && <p className="text-fail">{errorText(services.error)}</p>}
      {services.data && services.data.length === 0 && (
        <Panel><EmptyState title="No service instances yet" line="Postgres, MySQL, Redis, Meilisearch and the rest run as launchd agents nomeus owns." command="nomeus services:create postgresql" /></Panel>
      )}

      {services.data && services.data.length > 0 && (
        <Panel>
          <Table columns={['', 'name', 'type', 'version', 'port', 'pid', 'actions']}>
            {services.data.map((i) => (
              <Row key={i.name} i={i} open={open === i.name} onToggle={() => setOpen(open === i.name ? null : i.name)} />
            ))}
          </Table>
        </Panel>
      )}

      {types.data && <CreateForm types={types.data} existing={services.data ?? []} />}
      <AdoptPanel />
    </div>
  );
}
