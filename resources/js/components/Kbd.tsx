/** A command pill: mono 12, lantern on inset, hairline frame (spec §10, §11). Selectable so it can be copied. */
export default function Kbd({ children, className = '' }: { children: string; className?: string }) {
  return <code className={`inline-block select-all rounded-sm border border-line bg-inset px-2 py-0.5 text-xs text-lantern ${className}`}>{children}</code>;
}
