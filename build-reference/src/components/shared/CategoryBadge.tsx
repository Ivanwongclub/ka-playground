import { cn } from "@/lib/utils";

export type Category = "Language" | "STEM" | "Arts" | "Maths" | "STEM on Car";

const MAP: Record<Category, { bg: string; fg: string; dot: string }> = {
  Language:      { bg: "bg-indigo/15", fg: "text-indigo", dot: "bg-indigo" },
  STEM:          { bg: "bg-purple/15", fg: "text-purple", dot: "bg-purple" },
  "STEM on Car": { bg: "bg-cyan/15",   fg: "text-cyan",   dot: "bg-cyan" },
  Arts:          { bg: "bg-pink/15",   fg: "text-pink",   dot: "bg-pink" },
  Maths:         { bg: "bg-orange/15", fg: "text-orange", dot: "bg-orange" },
};

export function CategoryBadge({
  category,
  className,
  withDot = true,
}: {
  category: Category;
  className?: string;
  withDot?: boolean;
}) {
  const c = MAP[category];
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium",
        c.bg,
        c.fg,
        className,
      )}
    >
      {withDot && <span className={cn("h-1.5 w-1.5 rounded-full", c.dot)} />}
      {category}
    </span>
  );
}

export function categoryDotClass(category: Category) {
  return MAP[category].dot;
}
