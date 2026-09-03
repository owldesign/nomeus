/**
 * The shepherd's star: every status LED is a four-point star (spec §1). Each state differs in
 * shape or fill, not just colour — running steady, starting pulses, stopped hollow, crash-looping
 * flickers, missing is a dashed circle (not a star at all).
 */
export type LedState = 'running' | 'starting' | 'stopped' | 'crashing' | 'missing';

export const STAR_PATH = 'M8 0C8.55 4.9 11.1 7.45 16 8C11.1 8.55 8.55 11.1 8 16C7.45 11.1 4.9 8.55 0 8C4.9 7.45 7.45 4.9 8 0Z';

const TITLES: Record<LedState, string> = {
  running: 'running',
  starting: 'starting — loaded, not answering yet',
  stopped: 'stopped',
  crashing: 'crash-looping — expand the row for the log',
  missing: 'missing — formula or binary not installed',
};

export default function Led({ state, size = 11, title, className = '' }: { state: LedState; size?: number; title?: string; className?: string }) {
  const label = title ?? TITLES[state];
  if (state === 'missing') {
    return (
      <svg className={`np-star ${className}`} width={size} height={size} viewBox="0 0 16 16" role="img" aria-label={label}>
        <title>{label}</title>
        <circle cx="8" cy="8" r="5.5" fill="none" stroke="var(--color-faint)" strokeWidth="1.5" strokeDasharray="2.5 2.5" />
      </svg>
    );
  }
  const fill = state === 'running' ? 'var(--color-ok)' : state === 'starting' ? 'var(--color-warn)' : state === 'crashing' ? 'var(--color-fail)' : 'none';
  const stroke = state === 'stopped' ? 'var(--color-faint)' : 'none';
  const filter = state === 'running' ? 'drop-shadow(0 0 3px oklch(0.76 0.16 150 / 0.6))' : state === 'crashing' ? 'drop-shadow(0 0 3px oklch(0.66 0.19 25 / 0.7))' : undefined;
  const animation = state === 'starting' ? 'animate-pulse-led' : state === 'crashing' ? 'animate-flicker' : '';
  return (
    <svg className={`np-star ${animation} ${className}`} width={size} height={size} viewBox="0 0 16 16" role="img" aria-label={label} style={{ filter }}>
      <title>{label}</title>
      <path d={STAR_PATH} fill={fill} stroke={stroke} strokeWidth={state === 'stopped' ? 1.5 : 0} />
    </svg>
  );
}

/** The mark: a lantern star, used once in the strip and once in empty states (hollow). */
export function Star({ size = 15, hollow = false, className = '' }: { size?: number; hollow?: boolean; className?: string }) {
  return (
    <svg className={`np-star ${className}`} width={size} height={size} viewBox="0 0 16 16" aria-hidden>
      <path d={STAR_PATH} fill={hollow ? 'none' : 'var(--color-lantern)'} stroke={hollow ? 'var(--color-faint)' : 'none'} strokeWidth={hollow ? 1.5 : 0} />
    </svg>
  );
}

/** Map an instance's status block onto a LED state. */
export function ledStateFor(s: { running: boolean; loaded: boolean; crashing: boolean; installed: boolean }): LedState {
  if (s.running) return 'running';
  if (s.crashing) return 'crashing';
  if (s.loaded) return 'starting';
  if (s.installed) return 'stopped';
  return 'missing';
}
