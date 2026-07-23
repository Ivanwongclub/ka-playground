import { cn } from "@/lib/utils";

export function Toggle({
  checked,
  onChange,
  label,
  className,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  label?: string;
  className?: string;
}) {
  return (
    <label className={cn("inline-flex cursor-pointer items-center gap-3", className)}>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={cn(
          "relative h-6 w-11 rounded-full border border-border transition-colors",
          checked ? "bg-gold-gradient" : "bg-mut",
        )}
      >
        <span
          className={cn(
            "absolute top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-card shadow-card transition-all",
            checked ? "left-[calc(100%-1.125rem)]" : "left-0.5",
          )}
        />
      </button>
      {label && <span className="text-sm text-fg">{label}</span>}
    </label>
  );
}
