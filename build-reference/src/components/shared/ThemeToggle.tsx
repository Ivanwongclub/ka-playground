import { SunIcon, MoonIcon } from "@/components/icons";
import { useTheme } from "@/hooks/use-theme";
import { cn } from "@/lib/utils";

export function ThemeToggle({ className }: { className?: string }) {
  const { theme, toggle } = useTheme();
  const Icon = theme === "dark" ? SunIcon : MoonIcon;
  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={`Switch to ${theme === "dark" ? "light" : "dark"} theme`}
      className={cn(
        "inline-flex h-9 w-9 items-center justify-center rounded-lg bg-transparent text-muted-fg transition-colors hover:bg-mut hover:text-fg",
        className,
      )}
    >
      <Icon size={18} />
    </button>
  );
}
