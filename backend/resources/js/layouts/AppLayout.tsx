import type { ReactNode } from "react";
import { usePage } from "@inertiajs/react";
import { Search, Bell, HelpCircle } from "lucide-react";
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/app-sidebar";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

interface Props {
  children: ReactNode;
}

export default function AppLayout({ children }: Props) {
  const { props } = usePage() as any;
  const notifCount = props.auth?.user?.unread_notifications ?? 0;

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
              <Button variant="ghost" size="icon" className="relative">
                <Bell className="h-4 w-4" />
                {notifCount > 0 && (
                  <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-gold" />
                )}
              </Button>
            </div>
          </header>
          <main className="flex-1 min-w-0">{children}</main>
        </div>
      </div>
    </SidebarProvider>
  );
}
