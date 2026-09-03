import type { ReactNode } from 'react';
import { Star } from '@/components/Led';
import Kbd from '@/components/Kbd';

/** Empty states (spec §11): the hollow star marks absence; every one teaches the next command. */
export default function EmptyState({ title, line, command, action }: { title: string; line?: string; command?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 py-9 text-center">
      <Star size={20} hollow />
      <div className="t-sans text-[13px] font-semibold text-text">{title}</div>
      {line && <div className="text-xs text-dim">{line}</div>}
      {action}
      {command && <Kbd className="mt-1">{`$ ${command}`}</Kbd>}
      {action && command && <div className="text-2xs text-faint">or the command above</div>}
    </div>
  );
}
