import { Fragment, useState } from 'react';
import type { ServiceInstance, ServiceType } from '@/api/types';
import { ApiError } from '@/api/client';
import { copyText } from '@/lib/clipboard';
import TaskProgress from '@/components/TaskProgress';
import { useAdopt, useAdoptable, useCreateService, useRefetchAfterTask, useService, useServiceAction, useServiceTypes, useServices } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function Led({ s }: { s: ServiceInstance['status'] }) {
  const [cls, title] = s.running
    ? ['bg-green shadow-[0_0_6px_var(--color-green)]', 'running']
    : s.crashing
      ? ['bg-red shadow-[0_0_6px_var(--color-red)]', `crash-looping (last exit ${s.last_exit}) — expand the row for the log`]
      : s.loaded
        ? ['bg-gold', 'loaded, not answering yet']
        : s.installed
          ? ['bg-mute', 'stopped']
          : ['bg-red', 'formula missing'];
  return <span className={`inline-block h-2 w-2 rounded-full ${cls}`} title={title} />;
}

function EnvBlock({ env }: { env: Record<string, string> }) {
  const text = Object.entries(env).map(([k, v]) => `${k}=${v}`).join('\n');
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const copy = async () => {
    setState((await copyText(text)) ? 'copied' : 'failed');
    setTimeout(() => setState('idle'), 1500);
  };
  return (
    <div className="relative">
      <pre className="select-all border border-line bg-bg p-3 text-dim">{text}</pre>
      <button
        type="button"
        className={`absolute right-2 top-2 border bg-panel px-2 py-0.5 hover:border-gold hover:text-gold ${state === 'failed' ? 'border-red text-red' : state === 'copied' ? 'border-green text-green' : 'border-line text-dim'}`}
        onClick={copy}
      >
        {state === 'copied' ? 'copied' : state === 'failed' ? 'copy failed — select the block' : 'copy .env'}
      </button>
    </div>
  );
}

function Detail({ name }: { name: string }) {
  const { data } = useService(name);
  if (!data) return <p className="text-dim">reading…</p>;
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <div>
        <div className="mb-1 text-dim">.env for a site using it</div>
        <EnvBlock env={data.env} />
        <div className="mt-2 text-dim">data {data.dir}/data</div>
      </div>
      <div>
        <div className="mb-1 text-dim">log</div>
        <pre className="max-h-64 overflow-auto border border-line bg-bg p-3 text-dim">{data.log?.trim() || '(nothing yet)'}</pre>
      </div>
    </div>
  );
}

function Row({ i, open, onToggle }: { i: ServiceInstance; open: boolean; onToggle: () => void }) {
  const act = useServiceAction();
  const refetch = useRefetchAfterTask();
  const [taskId, setTaskId] = useState<string | null>(null);
  const [mode, setMode] = useState<'idle' | 'clone' | 'delete'>('idle');
  const [cloneName, setCloneName] = useState(`${i.name}-copy`);
  const [clonePort, setClonePort] = useState('');
  const [keepData, setKeepData] = useState(false);

  const run = (a: Parameters<typeof act.mutate>[0]) => {
    setMode('idle');
    act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });
  };

  const actions = () => {
    if (act.isPending) return <span className="text-dim">enqueuing…</span>;
    if (taskId) return <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />;
    if (mode === 'clone') {
      return (
        <span className="inline-flex flex-wrap items-center gap-2">
          <input aria-label="Clone name" className="w-36 border border-line bg-bg px-2 py-0.5" value={cloneName} onChange={(e) => setCloneName(e.target.value)} />
          <input aria-label="Clone port" className="w-20 border border-line bg-bg px-2 py-0.5" placeholder="port" value={clonePort} onChange={(e) => setClonePort(e.target.value)} />
          <button type="button" className="text-gold hover:underline" onClick={() => run({ name: i.name, action: 'clone', newName: cloneName, port: clonePort ? Number(clonePort) : undefined })}>clone</button>
          <button type="button" className="text-dim hover:underline" onClick={() => setMode('idle')}>cancel</button>
        </span>
      );
    }
    if (mode === 'delete') {
      return (
        <span className="inline-flex flex-wrap items-center gap-2">
          <span className="text-gold">delete {i.name}?</span>
          <label className="inline-flex items-center gap-1 text-dim"><input type="checkbox" checked={keepData} onChange={(e) => setKeepData(e.target.checked)} /> keep data</label>
          <button type="button" className="text-red hover:underline" onClick={() => run({ name: i.name, action: 'delete', keepData })}>yes</button>
          <button type="button" className="text-dim hover:underline" onClick={() => setMode('idle')}>no</button>
        </span>
      );
    }
    return (
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        {i.status.running || i.status.loaded
          ? <button type="button" className="hover:text-gold" onClick={() => run({ name: i.name, action: 'stop' })}>stop</button>
          : <button type="button" className="hover:text-gold" onClick={() => run({ name: i.name, action: 'start' })}>start</button>}
        <button type="button" className="hover:text-gold" onClick={() => run({ name: i.name, action: 'restart' })}>restart</button>
        <button type="button" className="hover:text-gold" onClick={() => setMode('clone')}>clone</button>
        <button type="button" className="hover:text-red" onClick={() => setMode('delete')}>delete</button>
        {act.isError && <span className="basis-full text-red">{errorText(act.error)}</span>}
      </div>
    );
  };

  return (
    <Fragment>
      <tr className="border-b border-dashed border-line align-top">
        <td className="py-2 pr-3"><Led s={i.status} /></td>
        <td className="py-2 pr-4 whitespace-nowrap">
          <button type="button" className="hover:text-gold" onClick={onToggle}>{open ? '▾' : '▸'} {i.name}</button>
        </td>
        <td className="py-2 pr-4 whitespace-nowrap">{i.type} <span className="text-dim">{i.formula}</span></td>
        <td className="py-2 pr-4 text-dim">{i.version}</td>
        <td className="py-2 pr-4">{i.port}</td>
        <td className="py-2 pr-4 text-dim">{i.status.pid ?? (i.status.crashing ? <span className="text-red">exit {i.status.last_exit}</span> : '—')}</td>
        <td className="py-2">{actions()}</td>
      </tr>
      {open && (
        <tr className="border-b border-dashed border-line">
          <td colSpan={7} className="py-3 pl-6"><Detail name={i.name} /></td>
        </tr>
      )}
    </Fragment>
  );
}

function CreateForm({ types, existing }: { types: ServiceType[]; existing: ServiceInstance[] }) {
  const create = useCreateService();
  const refetch = useRefetchAfterTask();
  const [type, setType] = useState(types[0]?.type ?? '');
  const [version, setVersion] = useState('');
  const [name, setName] = useState('');
  const [port, setPort] = useState('');
  const [start, setStart] = useState(true);
  const [taskId, setTaskId] = useState<string | null>(null);

  const t = types.find((x) => x.type === type);
  const usedNames = existing.map((e) => e.name);
  const suggestedName = !usedNames.includes(type) ? type : `${type}-${[2, 3, 4, 5].find((n) => !usedNames.includes(`${type}-${n}`)) ?? '?'}`;
  const submit = () => {
    create.mutate(
      { type, version: version || undefined, name: name || undefined, port: port ? Number(port) : undefined, start },
      { onSuccess: (r) => { setTaskId(r.task.id); setName(''); setPort(''); } },
    );
  };

  return (
    <div className="mt-6 border border-line bg-panel px-4 py-3">
      <div className="mb-2 text-dim">create a service instance</div>
      <div className="flex flex-wrap items-center gap-2">
        <select aria-label="Type" className="border border-line bg-bg px-2 py-1 text-fg" value={type} onChange={(e) => { setType(e.target.value); setVersion(''); }}>
          {types.map((x) => <option key={x.type} value={x.type}>{x.label}</option>)}
        </select>
        <select aria-label="Version" className="border border-line bg-bg px-2 py-1 text-fg" value={version} onChange={(e) => setVersion(e.target.value)}>
          <option value="">{t?.formulae[0]?.formula ?? 'version'} (default)</option>
          {t?.formulae.map((f) => (
            <option key={f.formula} value={f.formula}>{f.formula}{f.installed ? ` · installed ${f.version ?? ''}` : ' · will install'}</option>
          ))}
        </select>
        <input aria-label="Name" className="w-36 border border-line bg-bg px-2 py-1" placeholder={suggestedName} value={name} onChange={(e) => setName(e.target.value)} />
        <input aria-label="Port" className="w-24 border border-line bg-bg px-2 py-1" placeholder={String(t?.default_port ?? '')} value={port} onChange={(e) => setPort(e.target.value)} />
        <label className="inline-flex items-center gap-1 text-dim"><input type="checkbox" checked={start} onChange={(e) => setStart(e.target.checked)} /> start now</label>
        <button
          type="button"
          className="border border-line px-3 py-1 hover:border-gold hover:text-gold disabled:text-mute"
          disabled={!type || create.isPending || taskId !== null}
          onClick={submit}
        >
          {create.isPending ? 'enqueuing…' : 'create'}
        </button>
      </div>
      <div className="mt-1 text-dim">placeholders show what devkit picks when left blank; the port is the standard one when free, otherwise the next free</div>
      {create.isError && <div className="mt-2 text-red">{errorText(create.error)}</div>}
      {taskId && (
        <div className="mt-2">
          <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />
        </div>
      )}
    </div>
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
    <div className="mt-6 border border-gold/40 bg-panel px-4 py-3">
      <div className="mb-1 text-gold">running under brew services</div>
      <div className="mb-3 text-dim">devkit can take these over on their standard ports. The data is copied; brew's copy stays where it is until you remove it.</div>
      <table className="w-full border-collapse">
        <tbody>
          {adoptable.data.map((s) => (
            <tr key={s.formula} className="border-t border-dashed border-line">
              <td className="py-1 pr-4">{s.formula} <span className="text-dim">{s.type}</span></td>
              <td className="py-1 pr-4">{s.loaded ? <span className="text-green">running</span> : <span className="text-dim">{s.plist ? 'stopped, starts at login' : 'stopped'}</span>}</td>
              <td className="py-1 pr-4 text-dim">{s.port}{s.answering === false ? ' (silent)' : ''}</td>
              <td className="py-1 pr-4 break-all text-dim">{s.data_dir}</td>
              <td className="py-1">
                {taskId && busy === s.formula
                  ? <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => { setTaskId(null); setBusy(null); }, 4000); }} />
                  : (
                    <button
                      type="button"
                      className="border border-line px-2 py-0.5 hover:border-gold hover:text-gold disabled:text-mute"
                      disabled={adopt.isPending || taskId !== null}
                      onClick={() => { setBusy(s.formula); adopt.mutate({ formula: s.formula }, { onSuccess: (r) => setTaskId(r.task.id) }); }}
                    >
                      adopt
                    </button>
                  )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {adopt.isError && <div className="mt-2 text-red">{errorText(adopt.error)}</div>}
    </div>
  );
}

export default function Services() {
  const services = useServices();
  const types = useServiceTypes();
  const [open, setOpen] = useState<string | null>(null);
  const running = services.data?.filter((s) => s.status.running).length ?? 0;

  return (
    <div className="max-w-6xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="text-[15px] font-semibold">Services</h1>
        <span className="text-dim">{services.data ? `${running}/${services.data.length} running` : ''}</span>
      </div>

      {services.isLoading && <p className="text-dim">reading…</p>}
      {services.isError && <p className="text-red">{errorText(services.error)}</p>}
      {services.data && services.data.length === 0 && (
        <p className="text-dim">No instances yet. Create one below, or <code>devkit services:create postgresql</code>.</p>
      )}

      {services.data && services.data.length > 0 && (
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-line text-left text-dim">
              <th className="py-1 pr-3 font-normal"></th>
              <th className="py-1 pr-4 font-normal">name</th>
              <th className="py-1 pr-4 font-normal">type</th>
              <th className="py-1 pr-4 font-normal">version</th>
              <th className="py-1 pr-4 font-normal">port</th>
              <th className="py-1 pr-4 font-normal">pid</th>
              <th className="py-1 font-normal">actions</th>
            </tr>
          </thead>
          <tbody>
            {services.data.map((i) => (
              <Row key={i.name} i={i} open={open === i.name} onToggle={() => setOpen(open === i.name ? null : i.name)} />
            ))}
          </tbody>
        </table>
      )}

      {types.data && <CreateForm types={types.data} existing={services.data ?? []} />}
      <AdoptPanel />
    </div>
  );
}
