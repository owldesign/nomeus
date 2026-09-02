import { useState, type ReactNode } from 'react';
import { useStatus } from '@/hooks/useApi';

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
                <span className="text-dim">loopback</span> {valet.loopback}
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
    </div>
  );
}
