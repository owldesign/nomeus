export default function Placeholder({ name, slice }: { name: string; slice: string }) {
  return (
    <div className="max-w-3xl">
      <h1 className="mb-2 text-[15px] font-semibold">{name}</h1>
      <p className="text-dim">
        Lands in <span className="text-blue">{slice}</span>. Nothing here yet.
      </p>
    </div>
  );
}
