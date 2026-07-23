import { cn } from "@/lib/utils";

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? "")
    .join("");
}

const TONES = [
  "bg-indigo text-white",
  "bg-purple text-white",
  "bg-pink text-white",
  "bg-orange text-white",
  "bg-cyan text-bg",
];

export function AvatarStack({
  names,
  max = 3,
  size = 28,
  className,
}: {
  names: string[];
  max?: number;
  size?: number;
  className?: string;
}) {
  const shown = names.slice(0, max);
  const overflow = names.length - shown.length;

  return (
    <div className={cn("inline-flex items-center", className)}>
      {shown.map((n, i) => (
        <span
          key={`${n}-${i}`}
          className={cn(
            "-ml-2 inline-flex items-center justify-center rounded-full border-2 border-bg font-heading text-[11px] font-bold first:ml-0",
            TONES[i % TONES.length],
          )}
          style={{ width: size, height: size }}
          title={n}
        >
          {initials(n)}
        </span>
      ))}
      {overflow > 0 && (
        <span
          className="-ml-2 inline-flex items-center justify-center rounded-full border-2 border-bg bg-card-elev text-[11px] font-bold text-fg"
          style={{ width: size, height: size }}
        >
          +{overflow}
        </span>
      )}
    </div>
  );
}
