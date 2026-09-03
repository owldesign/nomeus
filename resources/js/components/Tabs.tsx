/** Tabs with counts (spec §6): 2px bottom border, lantern when active, counts lantern/faint, zero-count labels faint. */
export default function Tabs<T extends string>({ tabs, active, onChange }: { tabs: { id: T; label: string; count?: number }[]; active: T; onChange: (id: T) => void }) {
  return (
    <div className="flex flex-wrap border-b border-line/55">
      {tabs.map((t) => {
        const isActive = t.id === active;
        const zero = t.count === 0;
        return (
          <button
            key={t.id}
            type="button"
            onClick={() => onChange(t.id)}
            className={`-mb-px border-b-2 px-3 pb-[10px] pt-3 text-sm ${isActive ? 'border-lantern text-text' : zero ? 'border-transparent text-faint hover:text-text' : 'border-transparent text-mid hover:text-text'}`}
          >
            {t.label}
            {t.count !== undefined && <span className={`ml-1.5 ${isActive ? 'text-lantern' : 'text-faint'}`}>{t.count}</span>}
          </button>
        );
      })}
    </div>
  );
}
