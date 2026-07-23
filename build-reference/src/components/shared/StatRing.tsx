import { cn } from "@/lib/utils";

export function StatRing({
  percent,
  size = 64,
  stroke = 6,
  color = "var(--gold)",
  trackColor = "var(--mut)",
  label,
  className,
}: {
  percent: number;
  size?: number;
  stroke?: number;
  color?: string;
  trackColor?: string;
  label?: string;
  className?: string;
}) {
  const p = Math.max(0, Math.min(100, percent));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const dash = (p / 100) * c;

  // Scale label by ring size; below 44px the % won't fit inside without colliding
  // with the stroke, so render it below the ring instead.
  const inside = size >= 44;
  const labelFontSize = size < 36 ? 9 : size < 44 ? 10 : size < 56 ? 11 : 13;

  return (
    <div className={cn("inline-flex flex-col items-center gap-1", className)}>
      <div className="relative" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} stroke={trackColor} strokeWidth={stroke} fill="none" />
          <circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            stroke={color}
            strokeWidth={stroke}
            fill="none"
            strokeLinecap="round"
            strokeDasharray={`${dash} ${c}`}
            className="transition-all duration-500"
          />
        </svg>
        {inside && (
          <div
            className="absolute inset-0 flex items-center justify-center font-heading font-bold text-fg"
            style={{ fontSize: labelFontSize }}
          >
            {Math.round(p)}%
          </div>
        )}
      </div>
      {!inside && (
        <span className="font-heading font-bold text-fg" style={{ fontSize: labelFontSize }}>
          {Math.round(p)}%
        </span>
      )}
      {label && <span className="text-xs text-muted-fg">{label}</span>}
    </div>
  );
}
