import { ArrowDown, ArrowUp, Minus } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

type DeltaTone = "up" | "down" | "flat";

export function KPITile({
  label,
  value,
  delta,
  deltaTone = "up",
  fillPercent = 0,
  fillColor = "bg-gold",
  featured = false,
  icon: Icon,
  className,
}: {
  label: string;
  value: string | number;
  delta?: string;
  deltaTone?: DeltaTone;
  fillPercent?: number;
  fillColor?: string;
  featured?: boolean;
  icon?: LucideIcon;
  className?: string;
}) {
  const toneCls =
    deltaTone === "up" ? "text-success" :
    deltaTone === "down" ? "text-danger" :
    "text-muted-fg";

  const DeltaIcon = deltaTone === "up" ? ArrowUp : deltaTone === "down" ? ArrowDown : Minus;

  return (
    <div
      className={cn(
        "relative overflow-hidden rounded-lg border bg-card p-4 shadow-card md:p-5",
        featured
          ? "border-gold/40 bg-gradient-to-br from-gold-soft/40 to-card ring-1 ring-gold/20"
          : "border-border",
        className,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div
          className={cn(
            "text-[10.5px] font-medium uppercase tracking-wider leading-tight md:text-xs",
            "line-clamp-2 min-w-0",
            featured ? "text-gold" : "text-muted-fg",
          )}
        >
          {label}
        </div>
        {Icon && (
          <Icon
            className="h-4 w-4 shrink-0 text-muted-fg/60 md:h-5 md:w-5"
            strokeWidth={1.5}
            aria-hidden
          />
        )}
      </div>
      <div
        className={cn(
          "mt-2 font-heading font-bold text-fg",
          featured ? "text-kpi-featured" : "text-kpi",
        )}
      >
        {value}
      </div>
      {delta && (
        <div className={cn("mt-1 inline-flex items-center gap-1 text-[11px] font-medium md:text-xs", toneCls)}>
          <DeltaIcon className="h-3 w-3" />
          {delta}
        </div>
      )}
      <div className="mt-3 h-1 w-full rounded-full bg-mut md:mt-4">
        <div
          className={cn("h-full rounded-full transition-all", fillColor)}
          style={{ width: `${Math.max(0, Math.min(100, fillPercent))}%` }}
        />
      </div>
    </div>
  );
}
