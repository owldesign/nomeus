import { useState } from 'react';
import type { Site } from '@/api/types';
import { ApiError } from '@/api/client';
import Button, { ConfirmInline } from '@/components/Button';
import Chip from '@/components/Chip';
import EmptyState from '@/components/EmptyState';
import Field, { ToggleChip, Toggle } from '@/components/Field';
import Panel, { INPUT, INPUT_SM, PageHeader } from '@/components/Panel';
import Table, { CELL, CELL_FIRST, CELL_LAST, rowClass } from '@/components/Table';
import TaskProgress from '@/components/TaskProgress';
import type { NewSiteRequest } from '@/hooks/useApi';
import { useLinkSite, useRefetchAfterTask, useSiteAction, useSites, useStatus, useNewSite, useServices } from '@/hooks/useApi';

const errorText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function TypeTag({ type }: { type: Site['type'] }) {
  return <Chip tint={type === 'linked' ? 'info' : type === 'proxy' ? 'lantern' : 'neutral'}>{type}</Chip>;
}

/** A row's actions: inline; the destructive ones (unsecure, unlink) confirm in place. */
function RowActions({ site, phpVersions, globalPhp }: { site: Site; phpVersions: string[]; globalPhp: string }) {
  const act = useSiteAction();
  const refetch = useRefetchAfterTask();
  const [isolateTo, setIsolateTo] = useState<string>('');
  const [taskId, setTaskId] = useState<string | null>(null);
  const run = (a: Parameters<typeof act.mutate>[0]) => act.mutate(a, { onSuccess: (r) => setTaskId(r.task.id) });

  if (site.type === 'proxy') return <span className="text-faint">—</span>;
  if (act.isPending) return <span className="text-dim">enqueuing…</span>;
  if (taskId) return <TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} />;

  return (
    <div className="flex flex-wrap items-center gap-x-1 gap-y-1">
      {site.secured ? (
        <ConfirmInline trigger="unsecure" question={`unsecure ${site.name}?`} action="unsecure" onConfirm={() => run({ name: site.name, action: 'unsecure' })} />
      ) : (
        <Button onClick={() => run({ name: site.name, action: 'secure' })}>secure</Button>
      )}
      <span className="inline-flex items-center gap-1">
        <select aria-label={`Isolate ${site.name} to PHP version`} className={INPUT_SM} value={isolateTo} onChange={(e) => setIsolateTo(e.target.value)}>
          <option value="">php…</option>
          {phpVersions.map((v) => <option key={v} value={v} disabled={v === (site.php ?? globalPhp)}>{v}</option>)}
        </select>
        <Button disabled={!isolateTo} onClick={() => { run({ name: site.name, action: 'isolate', php: isolateTo }); setIsolateTo(''); }}>isolate</Button>
      </span>
      {site.php && <Button onClick={() => run({ name: site.name, action: 'unisolate' })}>unisolate</Button>}
      {site.manifest && <Button className="text-lantern" title="nomeus init — apply the site's nomeus.yml" onClick={() => run({ name: site.name, action: 'init' })}>init</Button>}
      {site.type === 'linked' && <ConfirmInline trigger="unlink" question={`unlink ${site.name}?`} action="unlink" onConfirm={() => run({ name: site.name, action: 'unlink' })} />}
      {act.isError && <span className="basis-full text-fail">{errorText(act.error)}</span>}
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
    <Panel className="mt-6" title="link a directory as <name>.test" footer={<span>the directory is served as it is; parked directories don't need this · <span className="text-dim">nomeus link {name || '<name>'} {path || '<path>'}</span></span>}>
      <div className="flex flex-wrap items-end gap-3 px-4 py-3">
        <Field label="name"><input aria-label="Site name" className={`${INPUT} w-40`} placeholder="name" value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <Field label="directory" className="min-w-[320px] flex-1"><input aria-label="Directory path" className={`${INPUT} w-full`} placeholder="/Users/you/Code/project" value={path} onChange={(e) => setPath(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && submit()} /></Field>
        <Button variant="primary" disabled={!name || !path || link.isPending} onClick={submit}>{link.isPending ? 'linking…' : 'link'}</Button>
      </div>
      {link.isError && <div className="px-4 pb-3 text-fail">{errorText(link.error)}</div>}
      {taskId && <div className="px-4 pb-3"><TaskProgress id={taskId} onFinished={() => { refetch(); setTimeout(() => setTaskId(null), 4000); }} /></div>}
    </Panel>
  );
}

const DBS = ['postgresql', 'mysql', 'mariadb', 'none'] as const;
const EXTRAS = ['redis', 'meilisearch', 'typesense', 'seaweedfs'] as const;

/** `nomeus new` as a form (spec §7 is this form): stacked labels, the service picker as toggle chips, toggles, one primary, the CLI in the footer. */
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
  const cli = ['nomeus new', f.name || '<name>', f.starter === 'from' ? `--from=${f.from || '<pkg>'}` : f.starter === 'empty' ? '--empty' : '--laravel', f.php ? `--php=${f.php}` : '', f.db && f.db !== 'none' ? `--db=${f.db}` : '--db=none', f.redis ? '--redis' : '', ...(f.services ?? []).map((s) => `--service=${s}`), f.mail ? '--mail' : '', f.secure ? '--secure' : '', f.skip_scripts ? '--no-scripts' : ''].filter(Boolean).join(' ');

  if (!open) {
    return <div className="mt-4"><Button variant="primary" onClick={() => setOpen(true)}>+ new site</Button></div>;
  }
  return (
    <Panel className="mt-4" accent title={<span className="text-lantern">new site</span>} actions={<Button onClick={() => setOpen(false)}>close</Button>}
      footer={<span>composer create-project runs in a task — a minute or two; the Tasks page shows it live · <span className="text-dim">{cli}</span></span>}>
      <div className="grid gap-4 px-4 py-4 md:grid-cols-2">
        <Field label="name" hint="becomes <name>.test"><input className={`${INPUT} w-full`} placeholder="shop" value={f.name} onChange={(e) => set({ name: e.target.value.toLowerCase() })} /></Field>
        <Field label="directory" hint="default: your parked directory + name"><input className={`${INPUT} w-full`} placeholder="(parked dir)/name" value={f.dir ?? ''} onChange={(e) => set({ dir: e.target.value || undefined })} /></Field>
        <Field label="start from">
          <select className={`${INPUT} w-full`} value={f.starter} onChange={(e) => set({ starter: e.target.value as NewSiteRequest['starter'] })}>
            <option value="laravel">Laravel (composer create-project)</option>
            <option value="from">another package…</option>
            <option value="empty">empty / existing directory</option>
          </select>
        </Field>
        {f.starter === 'from' ? (
          <Field label="package"><input className={`${INPUT} w-full`} placeholder="laravel/laravel:^12" value={f.from ?? ''} onChange={(e) => set({ from: e.target.value })} /></Field>
        ) : (
          <Field label="php">
            <select className={`${INPUT} w-full`} value={f.php ?? ''} onChange={(e) => set({ php: e.target.value || undefined })}>
              <option value="">linked (default)</option>
              {versions.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </Field>
        )}
      </div>
      <div className="grid gap-4 px-4 pb-4 md:grid-cols-2">
        <Field label="database">
          <div className="flex flex-wrap gap-1.5">
            {DBS.map((d) => <ToggleChip key={d} on={f.db === d} onClick={() => set({ db: d })}>{d}{d !== 'none' && (have(d) ? ` → ${have(d)}` : ' · new')}</ToggleChip>)}
          </div>
        </Field>
        <Field label="also">
          <div className="flex flex-wrap gap-1.5">
            <ToggleChip on={!!f.redis} onClick={() => set({ redis: !f.redis })}>redis{have('redis') ? ` → ${have('redis')}` : ''}</ToggleChip>
            {EXTRAS.filter((t) => t !== 'redis').map((t) => (
              <ToggleChip key={t} on={f.services?.includes(t) ?? false} onClick={() => set({ services: f.services?.includes(t) ? (f.services ?? []).filter((x) => x !== t) : [...(f.services ?? []), t] })}>{t}{have(t) ? ` → ${have(t)}` : ''}</ToggleChip>
            ))}
          </div>
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-5 px-4 pb-4">
        <Toggle on={!!f.mail} onChange={(v) => set({ mail: v })} label="mail (mailpit inbox for this app)" />
        <Toggle on={!!f.secure} onChange={(v) => set({ secure: v })} label="https" />
        <Toggle on={!!f.skip_scripts} onChange={(v) => set({ skip_scripts: v })} label="skip migrate" />
        <span className="ml-auto">
          {taskId ? (
            <TaskProgress id={taskId} compact onFinished={() => { refetch(); setTimeout(() => { setTaskId(null); setOpen(false); }, 4000); }} />
          ) : (
            <Button variant="primary" disabled={!valid || create.isPending} onClick={() => create.mutate(f, { onSuccess: (r) => setTaskId(r.task.id) })}>
              {create.isPending ? 'enqueuing…' : `create ${f.name || '…'}.test`}
            </Button>
          )}
        </span>
        {create.isError && <span className="basis-full text-fail">{String(create.error)}</span>}
      </div>
    </Panel>
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
      <PageHeader title="Sites" summary={sites.data ? `${sites.data.length} served` : ''} />

      {!trusted && (
        <p className="mb-4 rounded-md border border-warn/45 bg-warn/8 px-3 py-2 text-warn">
          Valet isn't trusted, so actions here will fail with a sudo error. Run <code>nomeus trust</code> once.
        </p>
      )}

      {sites.isLoading && <p className="text-dim">reading…</p>}
      {sites.isError && <p className="text-fail">{errorText(sites.error)}</p>}

      {sites.data && sites.data.length === 0 && (
        <Panel><EmptyState title="Nothing served yet" line="Park a directory and every folder in it becomes <folder>.test, or link one below." command="nomeus park" /></Panel>
      )}

      {sites.data && sites.data.length > 0 && (
        <Panel>
          <Table columns={['site', 'type', 'php', 'tls', 'path', 'actions']}>
            {sites.data.map((s) => (
              <tr key={s.name} className={rowClass()}>
                <td className={`${CELL_FIRST} whitespace-nowrap`}>
                  <a className="font-medium text-text hover:text-lantern" href={s.url} target="_blank" rel="noreferrer">{s.host}</a>
                  {s.laravel && <span className="ml-2 text-faint" title="Laravel app">λ</span>}
                </td>
                <td className={CELL}><TypeTag type={s.type} /></td>
                <td className={`${CELL} whitespace-nowrap`}>
                  {s.type === 'proxy' ? <span className="text-faint">—</span> : s.php ? <span className="text-lantern">{s.php}</span> : <span className="text-dim">{globalPhp}</span>}
                </td>
                <td className={CELL}>{s.secured ? <Chip tint="ok">https</Chip> : <span className="text-faint">http</span>}</td>
                <td className={`${CELL} break-all text-faint`}>{s.path}</td>
                <td className={CELL_LAST}><RowActions site={s} phpVersions={phpVersions} globalPhp={globalPhp} /></td>
              </tr>
            ))}
          </Table>
        </Panel>
      )}

      <NewSiteForm versions={phpVersions} />
      <LinkForm />
    </div>
  );
}
