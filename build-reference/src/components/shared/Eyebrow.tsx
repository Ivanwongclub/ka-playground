import { cn } from "@/lib/utils";

export function Eyebrow({
  children,
  icon,
  tone = "gold",
  className,
}: {
  children: React.ReactNode;
  icon?: React.ReactNode;
  tone?: "gold" | "neutral";
  className?: string;
}) {
  const tones = {
    gold: "bg-gold-soft text-gold",
    neutral: "bg-mut text-muted-fg",
  };
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wider",
        tones[tone],
        className,
      )}
    >
      {icon && <span className="inline-flex h-3.5 w-3.5 items-center">{icon}</span>}
      {children}
    </span>
  );
}
