import type { ReactNode } from 'react';

/** Tinted chips (spec §5): the tint is the state. Mono 11, radius 3, colour/13% fill + colour/48% border. */
export type Tint = 'ok' | 'warn' | 'fail' | 'info' | 'lantern' | 'neutral';

const TINTS: Record<Tint, string> = {
  ok: 'text-ok border-ok/48 bg-ok/13',
  warn: 'text-warn border-warn/48 bg-warn/13',
  fail: 'text-fail border-fail/48 bg-fail/13',
  info: 'text-info border-info/48 bg-info/13',
  lantern: 'text-lantern border-lantern/48 bg-lantern/13',
  neutral: 'text-dim border-line-strong bg-transparent',
};

export default function Chip({ tint = 'neutral', glow = false, className = '', children }: { tint?: Tint; glow?: boolean; className?: string; children: ReactNode }) {
  return <span className={`np-chip ${TINTS[tint]} ${glow && tint === 'lantern' ? 'shadow-glow-lantern' : ''} ${className}`}>{children}</span>;
}

/** Log/doctor level → tint. */
export function tintForLevel(level: string): Tint {
  const l = level.toLowerCase();
  if (['ok', 'done', 'info'].includes(l) && l !== 'info') return 'ok';
  if (['emergency', 'alert', 'critical', 'error', 'fail', 'failed', 'emerg', 'crit'].includes(l)) return 'fail';
  if (['warning', 'warn', 'notice'].includes(l)) return 'warn';
  if (l === 'info') return 'info';
  return 'neutral';
}
