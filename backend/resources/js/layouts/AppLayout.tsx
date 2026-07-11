import type { ReactNode } from "react";
import { useEffect, useState } from "react";
import { usePage, Link } from "@inertiajs/react";
import { Search, Bell, HelpCircle } from "lucide-react";
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/app-sidebar";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

interface Props {
  children: ReactNode;
}

export default function AppLayout({ children }: Props) {
  const { props } = usePage() as any;
  const initialCount = props.auth?.user?.unread_notifications ?? 0;
  const [notifCount, setNotifCount] = useState<number>(initialCount);

  // Keep the bell badge fresh without a full page reload.
  useEffect(() => {
    let active = true;
    const poll = () =>
      api.getUnreadNotificationCount()
        .then((c) => { if (active) setNotifCount(c); })
        .catch(() => {});
    poll();
    const t = setInterval(poll, 60000);
    return () => { active = false; clearInterval(t); };
  }, []);

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full bg-background">
        <AppSidebar />
        <div className="flex flex-1 flex-col min-w-0">
          <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur">
            <SidebarTrigger />
            <div className="relative max-w-md flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search matters, clients, documents…"
                className="h-9 pl-9 bg-secondary/60 border-transparent focus-visible:bg-background"
              />
            </div>
            <div className="ml-auto flex items-center gap-2">
              <Badge variant="outline" className="hidden md:inline-flex border-gold/40 text-gold">
                Production · v2.0
              </Badge>
              <Button variant="ghost" size="icon">
                <HelpCircle className="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon" className="relative" asChild>
                <Link href="/notifications" aria-label="Notifications">
                  <Bell className="h-4 w-4" />
                  {notifCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-gold px-1 text-[10px] font-semibold text-black">
                      {notifCount > 99 ? "99+" : notifCount}
                    </span>
                  )}
                </Link>
              </Button>
            </div>
          </header>
          <main className="flex-1 min-w-0">{children}</main>
        </div>
      </div>
    </SidebarProvider>
  );
}
