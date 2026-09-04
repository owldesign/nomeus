import { NavLink, Outlet } from 'react-router-dom';
import Led, { Star } from '@/components/Led';
import { useStatus } from '@/hooks/useApi';

// Nav is ordered by build phase; the number is the phase, which is why it's shown.
const NAV: Array<{ to: string; label: string; phase: string; ready: boolean }> = [
  { to: '/', label: 'Status', phase: '1', ready: true },
  { to: '/sites', label: 'Sites', phase: '1', ready: true },
  { to: '/php', label: 'PHP', phase: '1', ready: true },
  { to: '/tasks', label: 'Tasks', phase: '1', ready: true },
  { to: '/services', label: 'Services', phase: '2', ready: true },
  { to: '/mail', label: 'Mail', phase: '3', ready: true },
  { to: '/logs', label: 'Logs', phase: '4', ready: true },
  { to: '/debug', label: 'Debug', phase: '5', ready: true },
];

function StripLed({ on, label, title }: { on: boolean; label: string; title?: string }) {
  return (
    <span className="inline-flex items-center gap-1.5" title={title ?? `${label}: ${on ? 'running' : 'stopped'}`}>
      <Led state={on ? 'running' : 'stopped'} />
      <span className={on ? 'text-mid' : 'text-dim'}>{label}</span>
    </span>
  );
}

function Version() {
  const { data } = useStatus();
  return <span className="text-faint font-normal">{data ? `v${data.nomeus.version}` : ''}</span>;
}

/** Spec §2: a 44px bezel on `inset` — the mark, php/valet, one star per core service, the flock count, the polling dot. */
function StatusStrip() {
  const { data, isError, isLoading, dataUpdatedAt } = useStatus();

  if (isLoading) return <div className="text-dim">reading…</div>;
  if (isError || !data) return <div className="text-fail">api unreachable — is nomeus.test linked and nginx up?</div>;

  const fpm = data.services.php_fpm;
  const running = data.instances.filter((i) => i.running).length;
  return (
    <div className="flex flex-wrap items-center gap-x-5 gap-y-1">
      <Star size={15} />
      <span>
        <span className="text-dim">php </span>
        <span className="font-semibold text-lantern">{data.php.global ?? '—'}</span>
      </span>
      <span>
        <span className="text-dim">valet </span>
        <span className="font-medium text-text">{data.valet.version ?? '—'}</span>
      </span>
      <span className="h-3 w-px bg-line" aria-hidden />
      <StripLed on={data.services.nginx} label="nginx" />
      <StripLed on={data.services.dnsmasq} label="dnsmasq" />
      <StripLed on={fpm.length > 0} label={`fpm${fpm.length ? ' ' + fpm.join(' ') : ''}`} />
      <StripLed on={data.services.mailpit} label="mailpit" />
      {data.instances.length > 0 && (
        <>
          <span className="h-3 w-px bg-line" aria-hidden />
          <span className="inline-flex items-center gap-1.5" title={`${running} of ${data.instances.length} instances running`}>
            <Led state={running === data.instances.length ? 'running' : running === 0 ? 'stopped' : 'starting'} />
            <span className="text-dim">svc <span className="text-text">{running}/{data.instances.length}</span></span>
          </span>
        </>
      )}
      <span className="ml-auto inline-flex items-center gap-1.5 text-2xs text-faint" title={`polled ${new Date(dataUpdatedAt).toLocaleTimeString()}`}>
        <span className="inline-block h-[5px] w-[5px] rounded-full bg-ok animate-poll" aria-hidden />
        polled 5s
      </span>
    </div>
  );
}

export default function Layout() {
  return (
    // The shell is the viewport; only <main> scrolls, so the sidebar and the status strip stay put
    // (a page's own sticky headers now stick to <main>, which is what they meant anyway).
    <div className="grid h-screen grid-cols-[200px_1fr] overflow-hidden">
      <aside className="overflow-y-auto border-r border-line bg-panel px-4 py-5">
        <div className="mb-6 flex items-baseline justify-between text-[15px] font-semibold tracking-wide text-lantern">
          <span className="inline-flex items-center gap-2"><Star size={13} />nomeus</span>
          <Version />
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
                      isActive ? 'bg-inset text-lantern' : item.ready ? 'text-mid hover:bg-raised hover:text-text' : 'text-faint hover:text-dim',
                    ].join(' ')
                  }
                >
                  <span className="w-3 text-right text-faint">{item.phase}</span>
                  <span>{item.label}</span>
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
      </aside>

      <div className="flex min-h-0 min-w-0 flex-col">
        <header className="flex min-h-[44px] shrink-0 items-center border-b border-line bg-inset px-6 py-2">
          <div className="w-full"><StatusStrip /></div>
        </header>
        <main className="min-h-0 min-w-0 flex-1 overflow-y-auto px-6 py-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
