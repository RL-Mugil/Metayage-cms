import { Link, useRouterState } from "@tanstack/react-router";
import {
  LayoutDashboard, Users, Briefcase, GitBranch, KanbanSquare, ListChecks,
  BellRing, UserCircle2, GanttChart, FolderLock, MessagesSquare, CheckCircle2,
  Bell, BarChart3, FileBarChart2, Wallet, Sparkles, Building2, Star, Layers,
  CalendarDays, ShieldCheck, Plug, Settings, IdCard, Scale,
} from "lucide-react";
import {
  Sidebar, SidebarContent, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarFooter,
} from "@/components/ui/sidebar";

const groups = [
  {
    label: "Overview",
    items: [
      { to: "/", title: "Dashboard", icon: LayoutDashboard },
    ],
  },
  {
    label: "Practice",
    items: [
      { to: "/clients", title: "Clients", icon: Users },
      { to: "/projects", title: "Projects", icon: Briefcase },
      { to: "/tracker", title: "Project Tracker", icon: GitBranch },
      { to: "/kanban", title: "Kanban", icon: KanbanSquare },
      { to: "/tasks", title: "Tasks", icon: ListChecks },
      { to: "/timeline", title: "Timeline", icon: GanttChart },
      { to: "/calendar", title: "Calendar", icon: CalendarDays },
    ],
  },
  {
    label: "Client Engagement",
    items: [
      { to: "/portal", title: "Client Portal", icon: UserCircle2 },
      { to: "/discussions", title: "Discussions", icon: MessagesSquare },
      { to: "/approvals", title: "Approvals", icon: CheckCircle2 },
      { to: "/feedback", title: "Feedback (CSAT)", icon: Star },
      { to: "/reminders", title: "Reminders", icon: BellRing },
      { to: "/notifications", title: "Notifications", icon: Bell },
    ],
  },
  {
    label: "Knowledge",
    items: [
      { to: "/documents", title: "Documents (DMS)", icon: FolderLock },
      { to: "/ai", title: "AI Assistant", icon: Sparkles },
      { to: "/team", title: "Team Workspace", icon: Building2 },
    ],
  },
  {
    label: "Finance & Insight",
    items: [
      { to: "/financial", title: "Financial Suite", icon: Wallet },
      { to: "/analytics", title: "Analytics", icon: BarChart3 },
      { to: "/reports", title: "Reports", icon: FileBarChart2 },
    ],
  },
  {
    label: "Operations",
    items: [
      { to: "/bulk", title: "Bulk Operations", icon: Layers },
      { to: "/compliance", title: "Compliance & Audit", icon: ShieldCheck },
      { to: "/integrations", title: "Integrations", icon: Plug },
      { to: "/settings", title: "Settings", icon: Settings },
    ],
  },
  {
    label: "HRMS",
    items: [
      { to: "/hrms", title: "HR Overview", icon: IdCard },
      { to: "/hrms/employees", title: "Employees", icon: Users },
      { to: "/hrms/attendance", title: "Attendance", icon: CalendarDays },
      { to: "/hrms/leave", title: "Leave", icon: ListChecks },
      { to: "/hrms/payroll", title: "Payroll", icon: Wallet },
      { to: "/hrms/performance", title: "Performance", icon: BarChart3 },
      { to: "/hrms/recruitment", title: "Recruitment", icon: Briefcase },
      { to: "/hrms/offboarding", title: "Offboarding", icon: Scale },
    ],
  },
];

import { api } from "@/lib/api-client";
import { LogOut } from "lucide-react";

export function AppSidebar() {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const isActive = (p: string) => (p === "/" ? pathname === "/" : pathname === p || pathname.startsWith(p + "/"));

  const user = api.getUser();
  const initials = user ? user.name.split(" ").map((n: string) => n[0]).join("").substring(0, 2).toUpperCase() : "US";

  const handleLogout = async () => {
    await api.logout();
    window.location.reload();
  };

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="border-b border-sidebar-border">
        <Link to="/" className="flex items-center gap-2 px-2 py-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground font-display text-lg font-bold">
            IP
          </div>
          <div className="flex flex-col leading-tight group-data-[collapsible=icon]:hidden">
            <span className="font-display text-base font-semibold text-sidebar-foreground">IPFlow</span>
            <span className="text-[10px] uppercase tracking-widest text-sidebar-foreground/60">Metayage</span>
          </div>
        </Link>
      </SidebarHeader>
      <SidebarContent>
        {groups.map((g) => {
          // If user is a client, hide practice, operations, hrms groups
          if (user?.role === "client" && ["Practice", "Operations", "HRMS"].includes(g.label)) {
            return null;
          }
          return (
            <SidebarGroup key={g.label}>
              <SidebarGroupLabel className="text-sidebar-foreground/50 uppercase tracking-wider text-[10px]">
                {g.label}
              </SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {g.items.map((it) => (
                    <SidebarMenuItem key={it.to}>
                      <SidebarMenuButton asChild isActive={isActive(it.to)} tooltip={it.title}>
                        <Link to={it.to}>
                          <it.icon className="h-4 w-4" />
                          <span>{it.title}</span>
                        </Link>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          );
        })}
      </SidebarContent>
      <SidebarFooter className="border-t border-sidebar-border">
        <div className="flex items-center justify-between gap-2 px-2 py-2 group-data-[collapsible=icon]:hidden">
          <div className="flex items-center gap-2">
            <div className="h-8 w-8 rounded-full bg-gold text-gold-foreground flex items-center justify-center text-xs font-semibold">{initials}</div>
            <div className="flex flex-col leading-tight">
              <span className="text-xs font-medium text-sidebar-foreground">{user?.name || "User"}</span>
              <span className="text-[10px] text-sidebar-foreground/60 capitalize">{user?.role || "Associate"}</span>
            </div>
          </div>
          <Button variant="ghost" size="icon" className="h-8 w-8 text-sidebar-foreground/60 hover:text-destructive" onClick={handleLogout}>
            <LogOut className="h-4 w-4" />
          </Button>
        </div>
      </SidebarFooter>
    </Sidebar>
  );
}
