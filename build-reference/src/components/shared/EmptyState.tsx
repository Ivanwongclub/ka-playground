import { Inbox } from "lucide-react";
import { cn } from "@/lib/utils";

export function EmptyState({
  title,
  description,
  icon,
  action,
  className,
}: {
  title: string;
  description?: string;
  icon?: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-card/40 px-6 py-12 text-center",
        className,
      )}
    >
      <div className="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-full bg-gold-soft text-gold">
        {icon ?? <Inbox className="h-6 w-6" />}
      </div>
      <h3 className="font-heading text-lg font-semibold text-fg">{title}</h3>
      {description && (
        <p className="mt-1 max-w-sm text-sm text-muted-fg">{description}</p>
      )}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
