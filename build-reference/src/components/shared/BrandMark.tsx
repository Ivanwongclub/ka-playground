import { cn } from "@/lib/utils";

const SIZES = {
  sm: "h-8 w-8 text-sm rounded-md",
  md: "h-[42px] w-[42px] text-base rounded-[10px]",
  lg: "h-14 w-14 text-xl rounded-xl",
} as const;

export function BrandMark({
  size = "md",
  className,
}: {
  size?: keyof typeof SIZES;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "inline-flex items-center justify-center font-heading font-extrabold tracking-tight",
        "bg-gold-gradient text-bg shadow-card",
        SIZES[size],
        className,
      )}
      aria-label="Kings Armour"
    >
      KA
    </div>
  );
}
