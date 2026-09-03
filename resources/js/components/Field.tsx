import type { ReactNode } from 'react';
import { LABEL } from '@/components/Panel';

/** A stacked form field (spec §7): label 6px above the control. */
export default function Field({ label, children, className = '', hint }: { label: string; children: ReactNode; className?: string; hint?: string }) {
  return (
    <label className={`block ${className}`}>
      <span className={`${LABEL} mb-1.5 block`}>{label}</span>
      {children}
      {hint && <span className="mt-1 block text-2xs text-faint">{hint}</span>}
    </label>
  );
}

/** Toggle chips for pickers (spec §7): selected = lantern tint. */
export function ToggleChip({ on, onClick, children, disabled }: { on: boolean; onClick: () => void; children: ReactNode; disabled?: boolean }) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`rounded-sm border px-2 py-[3px] text-xs ${on ? 'border-lantern/48 bg-lantern/13 text-lantern' : 'border-line text-dim hover:border-line-strong hover:text-mid'} disabled:opacity-50`}
    >
      {children}
    </button>
  );
}

/** A 30×16 switch (spec §7). */
export function Toggle({ on, onChange, label, disabled }: { on: boolean; onChange: (v: boolean) => void; label: ReactNode; disabled?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={on} disabled={disabled} onClick={() => onChange(!on)} className="inline-flex items-center gap-2 text-xs text-dim disabled:opacity-50">
      <span className="np-toggle" data-on={on ? 'true' : 'false'} aria-hidden />
      <span className={on ? 'text-mid' : ''}>{label}</span>
    </button>
  );
}
