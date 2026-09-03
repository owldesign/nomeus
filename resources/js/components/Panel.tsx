import type { ReactNode } from 'react';

/** The card every page is made of: rounded-lg, hairline border, panel surface, the panel shadow. Optional header row and footer. */
export default function Panel({ title, actions, children, footer, className = '', accent = false }: { title?: ReactNode; actions?: ReactNode; children: ReactNode; footer?: ReactNode; className?: string; accent?: boolean }) {
  return (
    <section className={`rounded-lg border ${accent ? 'border-lantern/40' : 'border-line'} bg-panel shadow-panel ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-center gap-3 border-b border-line/55 px-4 py-2">
          {title && <h2 className="t-sans text-2xs font-semibold uppercase tracking-[0.14em] text-dim">{title}</h2>}
          {actions && <div className="ml-auto flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
      )}
      {children}
      {footer && <div className="border-t border-line/55 px-4 py-2 text-xs text-faint">{footer}</div>}
    </section>
  );
}

/** Page header: sans title, optional summary chip/text, actions at the right. */
export function PageHeader({ title, summary, actions }: { title: string; summary?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-center gap-4">
      <h1 className="t-sans text-xl font-semibold text-text">{title}</h1>
      {summary && <div className="text-dim">{summary}</div>}
      {actions && <div className="ml-auto flex flex-wrap items-center gap-3">{actions}</div>}
    </div>
  );
}

/** Section labels inside a panel body and form labels (sans 11 600 uppercase). */
export const LABEL = 't-sans text-2xs font-semibold uppercase tracking-[0.12em] text-dim';
/** Inputs and selects on `inset` with the lantern focus ring (spec §7). */
export const INPUT = 'rounded-md border border-line bg-inset px-[10px] py-[7px] text-[13px] text-text placeholder:text-faint focus:border-lantern/70 focus:shadow-[0_0_8px_oklch(0.79_0.14_68/0.25)] focus:outline-none disabled:text-faint';
/** The compact variant for inline rows. */
export const INPUT_SM = 'rounded-md border border-line bg-inset px-2 py-[3px] text-xs text-text placeholder:text-faint focus:border-lantern/70 focus:outline-none';
