import { NavLink, Outlet } from 'react-router-dom';
import { useStatus } from '@/hooks/useApi';

// Nav is ordered by build phase; the number is the phase, which is why it's shown.
const NAV: Array<{ to: string; label: string; phase: string; ready: boolean }> = [
  { to: '/', label: 'Status', phase: '1', ready: true },
  { to: '/sites', label: 'Sites', phase: '1', ready: true },
  { to: '/php', label: 'PHP', phase: '1', ready: true },
  { to: '/tasks', label: 'Tasks', phase: '1', ready: true },
  { to: '/services', label: 'Services', phase: '2', ready: false },
  { to: '/mail', label: 'Mail', phase: '3', ready: false },
  { to: '/logs', label: 'Logs', phase: '4', ready: false },
  { to: '/debug', label: 'Debug', phase: '5', ready: false },
];

function Led({ on, label }: { on: boolean; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5" title={`${label}: ${on ? 'running' : 'stopped'}`}>
      <span
        aria-hidden
        className={`inline-block h-2 w-2 rounded-full ${on ? 'bg-green shadow-[0_0_6px_var(--color-green)]' : 'bg-red'}`}
      />
      <span className={on ? 'text-fg' : 'text-dim'}>{label}</span>
    </span>
  );
}

function StatusStrip() {
  const { data, isError, isLoading } = useStatus();

  if (isLoading) return <div className="text-dim">reading…</div>;
  if (isError || !data) return <div className="text-red">api unreachable — is devkit.test linked and nginx up?</div>;

  const fpm = data.services.php_fpm;
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-1">
      <span>
        <span className="text-dim">php </span>
        <span className="text-gold">{data.php.global ?? '—'}</span>
      </span>
      <span>
        <span className="text-dim">valet </span>
        {data.valet.version ?? '—'}
      </span>
      <span className="h-3 w-px bg-line" aria-hidden />
      <Led on={data.services.nginx} label="nginx" />
      <Led on={data.services.dnsmasq} label="dnsmasq" />
      <Led on={fpm.length > 0} label={`fpm${fpm.length ? ' ' + fpm.join(' ') : ''}`} />
      <Led on={data.services.mailpit} label="mailpit" />
    </div>
  );
}

export default function Layout() {
  return (
    <div className="grid min-h-screen grid-cols-[200px_1fr]">
      <aside className="border-r border-line bg-panel px-4 py-5">
        <div className="mb-6 text-[15px] font-semibold tracking-wide text-gold">
          devkit<span className="text-dim font-normal"> /</span>
        </div>
        <nav aria-label="Sections">
          <ul className="space-y-0.5">
            {NAV.map((item) => (
              <li key={item.to}>
                <NavLink
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    [
                      'flex items-baseline gap-3 rounded-sm px-2 py-1',
                      isActive ? 'bg-bg text-gold' : item.ready ? 'text-fg hover:text-gold' : 'text-mute hover:text-dim',
                    ].join(' ')
                  }
                >
                  <span className="w-3 text-right text-blue">{item.phase}</span>
                  <span>{item.label}</span>
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </aside>

      <div className="flex min-w-0 flex-col">
        <header className="border-b border-line px-6 py-3">
          <StatusStrip />
        </header>
        <main className="min-w-0 flex-1 px-6 py-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
