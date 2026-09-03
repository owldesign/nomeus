import type { ReactNode } from 'react';

/** The table (spec §3): sans uppercase header, 36px rows, line/55% separators, hover wash. Rows are the caller's <tr>s. */
export default function Table({ columns, children, className = '' }: { columns: ReactNode[]; children: ReactNode; className?: string }) {
  return (
    <table className={`w-full border-collapse ${className}`}>
      <thead>
        <tr className="t-sans border-b border-line text-left text-2xs font-semibold uppercase tracking-[0.14em] text-dim">
          {columns.map((c, i) => <th key={i} className={`py-2 font-semibold ${i === 0 ? 'pl-4 pr-3' : i === columns.length - 1 ? 'pl-3 pr-4' : 'px-3'}`}>{c}</th>)}
        </tr>
      </thead>
      <tbody>{children}</tbody>
    </table>
  );
}

/** Row classes: base + optional wash. `faint` renders the whole row de-emphasised (stopped); `fail` washes it (crash-looping). */
export function rowClass(opts: { wash?: 'fail' | 'lantern'; clickable?: boolean } = {}): string {
  const wash = opts.wash === 'fail' ? 'bg-fail/5 hover:bg-fail/9' : opts.wash === 'lantern' ? 'bg-lantern/5 hover:bg-lantern/9' : 'hover:bg-raised/50';
  return `h-9 border-b border-line/55 align-middle last:border-0 ${wash} ${opts.clickable ? 'cursor-pointer' : ''}`;
}

/** Cell padding that matches the header. */
export const CELL = 'px-3 py-1';
export const CELL_FIRST = 'pl-4 pr-3 py-1';
export const CELL_LAST = 'pl-3 pr-4 py-1';
