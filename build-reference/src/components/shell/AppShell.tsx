import { useState, type ReactNode } from "react";
import { Sidebar } from "./Sidebar";
import { Topbar } from "./Topbar";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import type { KAUser } from "@/lib/auth";

export function AppShell({ user, children }: { user: KAUser; children: ReactNode }) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex h-dvh w-full overflow-hidden bg-bg text-fg">
      {/* Desktop sidebar — fixed inline, hidden on mobile */}
      <div className="hidden md:flex">
        <Sidebar role={user.role} />
      </div>

      {/* Mobile sidebar — off-canvas drawer */}
      <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
        <SheetContent
          side="left"
          className="w-[260px] border-0 bg-sidebar p-0"
        >
          <Sidebar role={user.role} onNavigate={() => setMobileNavOpen(false)} />
        </SheetContent>
      </Sheet>

      <div className="flex flex-1 flex-col overflow-hidden">
        <Topbar user={user} onOpenMobileNav={() => setMobileNavOpen(true)} />
        <main className="flex-1 overflow-y-auto px-[var(--spacing-page-x)] py-[var(--spacing-section-y)] safe-pb">
          {children}
        </main>
      </div>
    </div>
  );
}
