import { useState } from 'react';
import type { Site } from '@/api/types';
import { ApiError } from '@/api/client';
import TaskProgress from '@/components/TaskProgress';
import type { NewSiteRequest } from '@/hooks/useApi';
import { useLinkSite, useRefetchAfterTask, useSiteAction, useSites, useStatus, useNewSite, useServices } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function TypeTag({ type }: { type: Site['type'] }) {
  const color = type === 'linked' ? 'text-blue' : type === 'proxy' ? 'text-gold' : 'text-dim';
  return <span className={color}>{type}</span>;
}

/** A row's actions. Destructive ones (unsecure, unlink) ask once, inline, before firing. */
function RowActions({ site, phpVersions, globalPhp }: { site: Site; phpVersions: string[]; globalPhp: string }) {
  const act = useSiteAction();
  const refetch = useRefetchAfterTask();
  const [confirm, setConfirm] = useState<'unsecure' | 'unlink' | null>(null);
  const [isolateTo, setIsolateTo] = useState<string>('');
  const [taskId, setTaskId] = useState<string | null>(null);

  const run = (a: Parameters<typeof act.mutate>[0]) => {
    setConfirm(null);
    act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });
  };

  if (site.type === 'proxy') return <span className="text-dim">—</span>;
  if (act.isPending) return <span className="text-dim">enqueuing…</span>;
  if (taskId) {
    return (
      <TaskProgress
        id={taskId}
        onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }}
      />
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
      {confirm ? (
        <>
          <span className="text-gold">{confirm} {site.name}?</span>
          <button type="button" className="text-red hover:underline" onClick={() => run({ name: site.name, action: confirm })}>yes</button>
          <button type="button" className="text-dim hover:underline" onClick={() => setConfirm(null)}>no</button>
        </>
      ) : (
        <>
          {site.secured ? (
            <button type="button" className="hover:text-gold" onClick={() => setConfirm('unsecure')}>unsecure</button>
          ) : (
            <button type="button" className="hover:text-gold" onClick={() => run({ name: site.name, action: 'secure' })}>secure</button>
          )}

          <span className="inline-flex items-center gap-1">
            <select
              aria-label={`Isolate ${site.name} to PHP version`}
              className="border border-line bg-bg px-1 py-0.5 text-fg"
              value={isolateTo}
              onChange={(e) => setIsolateTo(e.target.value)}
            >
              <option value="">php…</option>
              {phpVersions.map((v) => (
                <option key={v} value={v} disabled={v === (site.php ?? globalPhp)}>{v}</option>
              ))}
            </select>
            <button
              type="button"
              className="hover:text-gold disabled:text-mute"
              disabled={!isolateTo}
              onClick={() => { run({ name: site.name, action: 'isolate', php: isolateTo }); setIsolateTo(''); }}
            >
              isolate
            </button>
          </span>

          {site.php && (
            <button type="button" className="hover:text-gold" onClick={() => run({ name: site.name, action: 'unisolate' })}>unisolate</button>
          )}
          {site.manifest && (
            <button type="button" className="text-gold hover:underline" title="nomeus init — apply the site's nomeus.yml" onClick={() => run({ name: site.name, action: 'init' })}>init</button>
          )}
          {site.type === 'linked' && (
            <button type="button" className="hover:text-red" onClick={() => setConfirm('unlink')}>unlink</button>
          )}
        </>
      )}
      {act.isError && <span className="basis-full text-red">{errorText(act.error)}</span>}
    </div>
  );
}

function LinkForm() {
  const link = useLinkSite();
  const refetch = useRefetchAfterTask();
  const [name, setName] = useState('');
  const [path, setPath] = useState('');
  const [taskId, setTaskId] = useState<string | null>(null);
  const submit = () => {
    if (!name || !path) return;
    link.mutate({ name, path }, { onSuccess: (r) => { setTaskId(r.task.id); setName(''); setPath(''); } });
  };

  return (
    <div className="mt-6 border border-line bg-panel px-4 py-3">
      <div className="mb-2 text-dim">link a directory as &lt;name&gt;.test</div>
      <div className="flex flex-wrap items-center gap-2">
        <input
          aria-label="Site name"
          className="w-40 border border-line bg-bg px-2 py-1"
          placeholder="name"
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
        <input
          aria-label="Directory path"
          className="min-w-[320px] flex-1 border border-line bg-bg px-2 py-1"
          placeholder="/Users/you/Code/project"
          value={path}
          onChange={(e) => setPath(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submit()}
        />
        <button
          type="button"
          className="border border-line px-3 py-1 hover:border-gold hover:text-gold disabled:text-mute"
          disabled={!name || !path || link.isPending}
          onClick={submit}
        >
          {link.isPending ? 'linking…' : 'link'}
        </button>
      </div>
      {link.isError && <div className="mt-2 text-red">{errorText(link.error)}</div>}
      {taskId && (
        <div className="mt-2">
          <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />
        </div>
      )}
    </div>
  );
}

/** `nomeus new` as a form: a task streams composer create-project and the init log. */
function NewSiteForm({ versions }: { versions: string[] }) {
  const create = useNewSite();
  const services = useServices();
  const refetch = useRefetchAfterTask();
  const [open, setOpen] = useState(false);
  const [taskId, setTaskId] = useState<string | null>(null);
  const [f, setF] = useState<NewSiteRequest>({ name: '', starter: 'laravel', db: 'postgresql', redis: true, mail: true, secure: true });
  const have = (type: string) => services.data?.find((s) => s.type === type)?.name;
  const set = (patch: Partial<NewSiteRequest>) => setF({ ...f, ...patch });
  const valid = /^[a-z0-9][a-z0-9.-]*$/.test(f.name) && (f.starter !== 'from' || /^[\w.-]+\/[\w.-]+/.test(f.from ?? ''));

  if (!open) {
    return (
      <div className="mt-4">
        <button type="button" className="text-gold hover:underline" onClick={() => setOpen(true)}>+ new site</button>
      </div>
    );
  }
  return (
    <div className="mt-4 border border-gold/40 bg-panel px-4 py-3">
      <div className="mb-2 flex items-baseline justify-between">
        <span className="text-gold">new site</span>
        <button type="button" className="text-dim hover:text-fg" onClick={() => setOpen(false)}>close</button>
      </div>
      <div className="grid gap-2 md:grid-cols-2">
        <label className="flex items-center gap-2"><span className="w-20 text-dim">name</span>
          <input className="flex-1 border border-line bg-bg px-2 py-1" placeholder="shop" value={f.name} onChange={(e) => set({ name: e.target.value.toLowerCase() })} />
        </label>
        <label className="flex items-center gap-2"><span className="w-20 text-dim">directory</span>
          <input className="flex-1 border border-line bg-bg px-2 py-1" placeholder="(parked dir)/name" value={f.dir ?? ''} onChange={(e) => set({ dir: e.target.value || undefined })} />
        </label>
        <label className="flex items-center gap-2"><span className="w-20 text-dim">start from</span>
          <select className="flex-1 border border-line bg-bg px-2 py-1 text-fg" value={f.starter} onChange={(e) => set({ starter: e.target.value as NewSiteRequest['starter'] })}>
            <option value="laravel">Laravel (create-project)</option>
            <option value="from">another package…</option>
            <option value="empty">empty / existing directory</option>
          </select>
        </label>
        {f.starter === 'from' && (
          <label className="flex items-center gap-2"><span className="w-20 text-dim">package</span>
            <input className="flex-1 border border-line bg-bg px-2 py-1" placeholder="laravel/laravel:^12" value={f.from ?? ''} onChange={(e) => set({ from: e.target.value })} />
          </label>
        )}
        <label className="flex items-center gap-2"><span className="w-20 text-dim">php</span>
          <select className="flex-1 border border-line bg-bg px-2 py-1 text-fg" value={f.php ?? ''} onChange={(e) => set({ php: e.target.value || undefined })}>
            <option value="">linked (default)</option>
            {versions.map((v) => <option key={v} value={v}>{v}</option>)}
          </select>
        </label>
        <label className="flex items-center gap-2"><span className="w-20 text-dim">database</span>
          <select className="flex-1 border border-line bg-bg px-2 py-1 text-fg" value={f.db} onChange={(e) => set({ db: e.target.value as NewSiteRequest['db'] })}>
            {(['postgresql', 'mysql', 'mariadb', 'none'] as const).map((d) => <option key={d} value={d}>{d}{have(d) ? ` (→ ${have(d)})` : d !== 'none' ? ' (will create)' : ''}</option>)}
          </select>
        </label>
      </div>
      <div className="mt-2 flex flex-wrap gap-4 text-dim">
        <label className="inline-flex items-center gap-1"><input type="checkbox" checked={!!f.redis} onChange={(e) => set({ redis: e.target.checked })} /> redis{have('redis') ? ` (${have('redis')})` : ''}</label>
        {(['meilisearch', 'typesense', 'seaweedfs'] as const).map((t) => (
          <label key={t} className="inline-flex items-center gap-1">
            <input type="checkbox" checked={f.services?.includes(t) ?? false} onChange={(e) => set({ services: e.target.checked ? [...(f.services ?? []), t] : (f.services ?? []).filter((x) => x !== t) })} /> {t}
          </label>
        ))}
        <label className="inline-flex items-center gap-1"><input type="checkbox" checked={!!f.mail} onChange={(e) => set({ mail: e.target.checked })} /> mail</label>
        <label className="inline-flex items-center gap-1"><input type="checkbox" checked={!!f.secure} onChange={(e) => set({ secure: e.target.checked })} /> https</label>
        <label className="inline-flex items-center gap-1"><input type="checkbox" checked={!!f.skip_scripts} onChange={(e) => set({ skip_scripts: e.target.checked })} /> skip migrate</label>
      </div>
      <div className="mt-3 flex items-center gap-4">
        {taskId ? (
          <TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => { setTaskId(null); setOpen(false); }, 4000); }} />
        ) : (
          <button type="button" className="border border-line px-3 py-1 hover:border-gold hover:text-gold disabled:text-mute" disabled={!valid || create.isPending}
            onClick={() => create.mutate(f, { onSuccess: (r) => setTaskId(r.task.id) })}>
            {create.isPending ? 'enqueuing…' : `create ${f.name || '…'}.test`}
          </button>
        )}
        {create.isError && <span className="text-red">{String(create.error)}</span>}
        <span className="text-dim">composer create-project runs in the task — a minute or two; the Tasks page shows it live</span>
      </div>
    </div>
  );
}

export default function Sites() {
  const sites = useSites();
  const status = useStatus();
  const phpVersions = status.data?.php.installed ?? [];
  const globalPhp = status.data?.php.global?.match(/^\d+\.\d+/)?.[0] ?? '?';
  const trusted = status.data?.valet.trusted ?? true;

  return (
    <div className="max-w-5xl">
      <div className="mb-4 flex items-baseline justify-between">
        <h1 className="text-[15px] font-semibold">Sites</h1>
        <span className="text-dim">{sites.data ? `${sites.data.length} served` : ''}</span>
      </div>

      {!trusted && (
        <p className="mb-4 border border-gold px-3 py-2 text-gold">
          Valet isn't trusted, so actions here will fail with a sudo error. Run <code>nomeus trust</code> once.
        </p>
      )}

      {sites.isLoading && <p className="text-dim">reading…</p>}
      {sites.isError && <p className="text-red">{errorText(sites.error)}</p>}

      {sites.data && sites.data.length === 0 && (
        <p className="text-dim">Nothing served yet. Park a directory (<code>nomeus park</code>) or link one below.</p>
      )}

      {sites.data && sites.data.length > 0 && (
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-line text-left text-dim">
              <th className="py-1 pr-4 font-normal">site</th>
              <th className="py-1 pr-4 font-normal">type</th>
              <th className="py-1 pr-4 font-normal">php</th>
              <th className="py-1 pr-4 font-normal">tls</th>
              <th className="py-1 pr-4 font-normal">path</th>
              <th className="py-1 font-normal">actions</th>
            </tr>
          </thead>
          <tbody>
            {sites.data.map((s) => (
              <tr key={s.name} className="border-b border-dashed border-line align-top">
                <td className="py-2 pr-4 whitespace-nowrap">
                  <a href={s.url} target="_blank" rel="noreferrer">{s.host}</a>
                  {s.laravel && <span className="ml-2 text-dim" title="Laravel app">λ</span>}
                </td>
                <td className="py-2 pr-4"><TypeTag type={s.type} /></td>
                <td className="py-2 pr-4 whitespace-nowrap">
                  {s.type === 'proxy' ? <span className="text-dim">—</span>
                    : s.php ? <span className="text-gold">{s.php}</span>
                    : <span className="text-dim">{globalPhp}</span>}
                </td>
                <td className="py-2 pr-4">{s.secured ? <span className="text-green">https</span> : <span className="text-dim">http</span>}</td>
                <td className="py-2 pr-4 break-all text-dim">{s.path}</td>
                <td className="py-2"><RowActions site={s} phpVersions={phpVersions} globalPhp={globalPhp} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      <NewSiteForm versions={phpVersions} />
      <LinkForm />
    </div>
  );
}
