import { useState } from 'react';
import type { Site } from '@/api/types';
import { ApiError } from '@/api/client';
import TaskProgress from '@/components/TaskProgress';
import { useLinkSite, useRefetchAfterTask, useSiteAction, useSites, useStatus } from '@/hooks/useApi';

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
            <button type="button" className="text-gold hover:underline" title="devkit init — apply the site's dev.yml" onClick={() => run({ name: site.name, action: 'init' })}>init</button>
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
          Valet isn't trusted, so actions here will fail with a sudo error. Run <code>devkit trust</code> once.
        </p>
      )}

      {sites.isLoading && <p className="text-dim">reading…</p>}
      {sites.isError && <p className="text-red">{errorText(sites.error)}</p>}

      {sites.data && sites.data.length === 0 && (
        <p className="text-dim">Nothing served yet. Park a directory (<code>devkit park</code>) or link one below.</p>
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

      <LinkForm />
    </div>
  );
}
