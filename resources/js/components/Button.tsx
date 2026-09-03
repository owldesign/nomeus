import { useEffect, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';

/** Actions (spec §4): inline (ghost mono), primary (one per view), destructive (ghost, fail), confirm-inline (swaps in place). */
export type ButtonVariant = 'inline' | 'primary' | 'destructive';

const VARIANTS: Record<ButtonVariant, string> = {
  inline: 'font-mono text-sm text-mid rounded px-2 py-[3px] hover:bg-raised hover:text-text disabled:text-faint disabled:hover:bg-transparent',
  primary: 't-sans text-sm font-semibold text-lantern rounded-md border border-lantern/45 bg-lantern/10 px-3 py-[5px] hover:bg-lantern/18 hover:shadow-glow-lantern disabled:opacity-50 disabled:hover:shadow-none',
  destructive: 'font-mono text-sm text-fail/75 rounded px-2 py-[3px] hover:bg-fail/12 hover:text-fail disabled:text-faint',
};

export default function Button({ variant = 'inline', className = '', children, ...rest }: { variant?: ButtonVariant; className?: string; children: ReactNode } & ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" className={`${VARIANTS[variant]} ${className}`} {...rest}>
      {children}
    </button>
  );
}

/**
 * Confirm in place (spec §4): the trigger swaps for a fail-tinted frame with the question, `no`, and the
 * solid action. Esc or `no` restores. Never a modal. `extra` renders between the question and the buttons
 * (e.g. a "keep data" toggle).
 */
export function ConfirmInline({ question, action, onConfirm, trigger = 'delete', extra, disabled }: { question: string; action: string; onConfirm: () => void; trigger?: string; extra?: ReactNode; disabled?: boolean }) {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);
  if (!open) return <Button variant="destructive" disabled={disabled} onClick={() => setOpen(true)}>{trigger}</Button>;
  return (
    <span className="inline-flex flex-wrap items-center gap-2 rounded-md border border-fail/45 bg-fail/8 px-2 py-1">
      <span className="text-xs text-fail">{question}</span>
      {extra}
      <Button variant="inline" onClick={() => setOpen(false)}>no</Button>
      <button type="button" className="rounded px-2 py-[3px] font-mono text-sm font-medium text-bg bg-fail hover:shadow-glow-fail" onClick={() => { setOpen(false); onConfirm(); }}>
        {action}
      </button>
    </span>
  );
}
