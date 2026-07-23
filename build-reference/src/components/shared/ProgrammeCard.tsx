import { Calendar, Users } from "lucide-react";
import { cn } from "@/lib/utils";
import { CategoryBadge, type Category } from "./CategoryBadge";

type Status = "Open" | "Registering" | "Coming Soon" | "Closed";

const STATUS_STYLE: Record<Status, string> = {
  Open:          "bg-success/15 text-success",
  Registering:   "bg-gold-soft text-gold",
  "Coming Soon": "bg-cyan/15 text-cyan",
  Closed:        "bg-mut text-muted-fg",
};

export function ProgrammeCard({
  title,
  category,
  description,
  status = "Open",
  period,
  enrolled,
  capacity,
  ageRange,
  featured = false,
  image,
  brandColor,
  onClick,
  className,
}: {
  title: string;
  category: Category;
  description: string;
  status?: Status;
  period?: string;
  enrolled?: number;
  capacity?: number;
  ageRange?: string;
  featured?: boolean;
  image?: string;
  brandColor?: string;
  onClick?: () => void;
  className?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "group relative flex w-full flex-col overflow-hidden rounded-lg border border-border bg-card text-left shadow-card transition-all",
        "hover:-translate-y-0.5 hover:shadow-card-hover hover:border-gold/40",
        className,
      )}
    >
      <div
        className="relative h-44 w-full overflow-hidden"
        style={
          image
            ? { backgroundImage: `url(${image})`, backgroundSize: "cover", backgroundPosition: "center" }
            : {
                background: brandColor
                  ? `linear-gradient(135deg, ${brandColor}, color-mix(in oklab, ${brandColor} 55%, black))`
                  : "linear-gradient(135deg, var(--card-elev), var(--card))",
              }
        }
      >
        <div className="absolute inset-0 bg-gradient-to-b from-black/10 to-black/40" />
        <span
          className={cn(
            "absolute rounded-full px-2.5 py-1 text-xs font-medium backdrop-blur-md",
            STATUS_STYLE[status],
          )}
          style={{ top: 16, left: 16 }}
        >
          {status}
        </span>
        {featured && (
          <span
            className="absolute inline-flex items-center gap-1 rounded-full bg-gold-gradient px-2.5 py-1 text-xs font-semibold text-bg"
            style={{ bottom: 16, right: 16 }}
          >
            ★ Featured
          </span>
        )}
      </div>


      <div className="flex flex-1 flex-col p-4">
        <CategoryBadge category={category} className="self-start" />
        <h3 className="mt-3 font-heading text-lg font-bold leading-tight text-fg">{title}</h3>
        <p className="mt-1.5 line-clamp-2 text-sm text-muted-fg">{description}</p>

        <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-3 text-xs text-muted-fg">
          {period && (
            <span className="inline-flex items-center gap-1">
              <Calendar className="h-3.5 w-3.5" />
              {period}
            </span>
          )}
          {enrolled !== undefined && capacity !== undefined && (
            <span className="inline-flex items-center gap-1">
              <Users className="h-3.5 w-3.5" />
              {enrolled}/{capacity}
            </span>
          )}
          {ageRange && <span className="ml-auto">Ages {ageRange}</span>}
        </div>
      </div>
    </button>
  );
}
