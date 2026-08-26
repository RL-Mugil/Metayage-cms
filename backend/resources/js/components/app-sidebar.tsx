import { useRef, useEffect, useState } from "react";
import { Link, usePage, router } from "@inertiajs/react";
import echo from "@/lib/echo";
import { api } from "@/lib/api-client";
import {
  LayoutDashboard, Users, Briefcase, GitBranch, ListChecks,
  BellRing, UserCircle2, FolderLock, MessagesSquare, CheckCircle2,
  Bell, BarChart3, FileBarChart2, Wallet, Sparkles, Building2, Star, Layers,
  CalendarDays, Plug, Settings, IdCard, Scale, LogOut, TableProperties, ShieldCheck, Award, LayoutGrid, Trash2, CreditCard,
} from "lucide-react";
import {
  Sidebar, SidebarContent, SidebarGroup, SidebarGroupContent, SidebarGroupLabel,
  SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarFooter,
} from "@/components/ui/sidebar";
import { Button } from "@/components/ui/button";

// Dedicated nav for external client portal users (client / client_admin).
const clientGroups = (isAdmin: boolean) => [
  {
    label: "Overview",
    items: [
      { to: "/", title: "Dashboard", icon: LayoutDashboard },
      { to: "/patent-portfolio", title: "Patent Portfolio", icon: Award },
      { to: "/patent-lifecycle", title: "Patent Lifecycle", icon: GitBranch },
    ],
  },
  {
    label: "My Cases",
    items: [
      { to: "/projects", title: "My Cases", icon: Briefcase },
      { to: "/tasks", title: "Tasks", icon: ListChecks },
      { to: "/kanban", title: "Kanban Board", icon: LayoutGrid },
      { to: "/calendar", title: "Calendar", icon: CalendarDays },
    ],
  },
  {
    label: "Workspace",
    items: [
      { to: "/team", title: "My Team", icon: Building2 },
      { to: "/documents", title: "Documents", icon: FolderLock },
      { to: "/discussions", title: "Discussions", icon: MessagesSquare },
      { to: "/approvals", title: "Approvals", icon: CheckCircle2 },
      { to: "/financial", title: "Invoices & Payments", icon: Wallet },
      { to: "/pending-payments", title: "Pending Payments", icon: CreditCard },
    ],
  },
  {
    label: "Account",
    items: [
      ...(isAdmin ? [{ to: "/portal-users", title: "Portal Users", icon: Users }] : []),
      { to: "/feedback", title: "Feedback", icon: Star },
      { to: "/notifications", title: "Notifications", icon: Bell },
      { to: "/settings", title: "Settings", icon: Settings },
    ],
  },
];

// Billing-only nav for the client_finance role — no case/kanban/approval
// visibility, matching RestrictClientPages' allowlist for this role.
const financeGroups = [
  {
    label: "Overview",
    items: [{ to: "/", title: "Dashboard", icon: LayoutDashboard }],
  },
  {
    label: "Billing",
    items: [
      { to: "/financial", title: "Invoices & Payments", icon: Wallet },
      { to: "/pending-payments", title: "Pending Payments", icon: CreditCard },
      { to: "/documents", title: "Documents", icon: FolderLock },
    ],
  },
  {
    label: "Account",
    items: [
      { to: "/notifications", title: "Notifications", icon: Bell },
      { to: "/settings", title: "Settings", icon: Settings },
    ],
  },
];

// Minimal nav for the inventor role — a dashboard of their own cases (across
// possibly multiple clients, see User::projectsAsInventor()) and nothing else.
const inventorGroups = [
  {
    label: "Overview",
    items: [{ to: "/", title: "Your Inventions", icon: LayoutDashboard }],
  },
  {
    label: "Account",
    items: [
      { to: "/notifications", title: "Notifications", icon: Bell },
      { to: "/settings", title: "Settings", icon: Settings },
    ],
  },
];

const ROLE_LABEL: Record<string, string> = {
  super_admin: "System Admin", partner: "Director", manager: "Patent Attorney",
  hr: "HR", finance: "Accountant", associate: "Patent Analyst", paralegal: "Paralegal",
  galvanizer: "Galvanizer",
  client: "Client", client_admin: "Client Admin", client_finance: "Finance", inventor: "Inventor",
};

const groups = [
  {
    label: "Overview",
    items: [
      { to: "/", title: "Dashboard", icon: LayoutDashboard },
      { to: "/patent-portfolio", title: "Patent Portfolio", icon: Award },
      { to: "/patent-lifecycle", title: "Patent Lifecycle", icon: GitBranch },
    ],
  },
  {
    label: "Practice",
    items: [
      { to: "/clients", title: "Clients", icon: Users },
      { to: "/projects", title: "Projects", icon: Briefcase },
      { to: "/ip-records", title: "IP Portfolio", icon: Award },
      { to: "/project-tracker", title: "Project Tracker", icon: TableProperties },
      { to: "/tasks", title: "Tasks", icon: ListChecks },
      { to: "/kanban", title: "Kanban Board", icon: Layers },
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
      { to: "/pending-payments", title: "Pending Payments", icon: CreditCard },
      { to: "/analytics", title: "Analytics", icon: BarChart3 },
      { to: "/reports", title: "Reports", icon: FileBarChart2 },
    ],
  },
  {
    label: "Operations",
    items: [
      { to: "/bulk", title: "Bulk Operations", icon: Layers },
      { to: "/compliance", title: "Compliance", icon: ShieldCheck },
      { to: "/audit-logs", title: "Audit Log", icon: ShieldCheck, roles: ["super_admin", "partner"] },
      { to: "/recycle-bin", title: "Recycle Bin", icon: Trash2, roles: ["super_admin", "partner", "manager"] },
      { to: "/integrations", title: "Integrations", icon: Plug, roles: ["super_admin", "partner", "manager", "hr", "associate", "finance", "galvanizer"] },
      { to: "/staff-users", title: "Staff Users", icon: Users, adminOnly: true },
      { to: "/settings", title: "Settings", icon: Settings },
    ],
  },
  {
    label: "HRMS",
    items: [
      { to: "/hrms",             title: "HR Overview",  icon: IdCard },
      { to: "/hrms/attendance",  title: "Attendance",   icon: CalendarDays },
      { to: "/hrms/leave",       title: "Leave",        icon: ListChecks },
      { to: "/hrms/employees",   title: "Employees",    icon: Users,       roles: ["super_admin","partner","hr","finance","galvanizer"] },
      { to: "/hrms/payroll",     title: "Payroll",      icon: Wallet,      roles: ["super_admin","partner","hr","finance"] },
      { to: "/hrms/performance", title: "Performance",  icon: BarChart3,   roles: ["super_admin","partner","hr","finance","galvanizer"] },
      { to: "/hrms/recruitment", title: "Recruitment",  icon: Briefcase,   roles: ["super_admin","partner","hr","finance"] },
      { to: "/hrms/offboarding", title: "Offboarding",  icon: Scale,       roles: ["super_admin","partner","hr","finance"] },
    ],
  },
];

export function AppSidebar() {
  const { url, props } = usePage() as any;
  const user = props.auth?.user;
  const contentRef = useRef<HTMLDivElement>(null);

  const isActive = (p: string) =>
    p === "/" ? url === "/" : url === p || url.startsWith(p + "/");

  // Unread chat badge on the Discussions nav item — live via the user channel.
  const [chatUnread, setChatUnread] = useState(0);
  const onDiscussions = url === "/discussions" || url.startsWith("/discussions");
  useEffect(() => {
    if (!user?.id) return;
    if (onDiscussions) { setChatUnread(0); return; }
    let active = true;
    api.getChatUnreadCount().then((r) => { if (active) setChatUnread(r.count ?? 0); }).catch(() => {});
    let channel: ReturnType<typeof echo.channel> | null = null;
    try {
      channel = echo.channel(`user.${user.id}`);
      channel.listen(".chat.unread", () => setChatUnread((c) => c + 1));
    } catch { /* Reverb unavailable — count still loads via the fetch above */ }
    return () => { active = false; try { channel?.stopListening(".chat.unread"); } catch { /* noop */ } };
  }, [user?.id, onDiscussions]);

  const initials = user
    ? user.name.split(" ").map((n: string) => n[0]).join("").substring(0, 2).toUpperCase()
    : "US";

  const handleLogout = () => {
    router.post("/logout");
  };

  useEffect(() => {
    const saved = sessionStorage.getItem("sidebar-scroll");
    if (saved && contentRef.current) {
      contentRef.current.scrollTop = parseInt(saved, 10);
    }
    const off = router.on("before", () => {
      if (contentRef.current) {
        sessionStorage.setItem("sidebar-scroll", String(contentRef.current.scrollTop));
      }
    });
    return () => off();
  }, []);

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="border-b border-sidebar-border">
        <Link href="/" className="flex items-center gap-2 px-2 py-3">
          <img
            src="/favicon.png"
            alt="Metayage"
            className="h-9 w-9 rounded-md object-cover"
          />
          <div className="flex flex-col leading-tight group-data-[collapsible=icon]:hidden">
            <span className="font-display text-base font-semibold text-sidebar-foreground">MyIPStrategy</span>
            <span className="text-[10px] uppercase tracking-widest text-sidebar-foreground/60">Metayage</span>
          </div>
        </Link>
      </SidebarHeader>
      <SidebarContent ref={contentRef}>
        {(user?.role === "client_finance"
          ? financeGroups
          : user?.role === "inventor"
          ? inventorGroups
          : ["client", "client_admin"].includes(user?.role)
          ? clientGroups(user?.role === "client_admin")
          : groups
        ).map((g) => {
          return (
            <SidebarGroup key={g.label}>
              <SidebarGroupLabel className="text-sidebar-foreground/50 uppercase tracking-wider text-[10px]">
                {g.label}
              </SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {g.items.filter((it: any) => {
                    if (it.adminOnly && user?.role !== "super_admin") return false;
                    if (it.roles && !it.roles.includes(user?.role)) return false;
                    return true;
                  }).map((it) => (
                    <SidebarMenuItem key={it.to}>
                      <SidebarMenuButton asChild isActive={isActive(it.to)} tooltip={it.title}>
                        <Link href={it.to}>
                          <it.icon className="h-4 w-4" />
                          <span>{it.title}</span>
                          {it.to === "/discussions" && chatUnread > 0 && (
                            <span className="ml-auto rounded-full bg-gold px-1.5 py-0.5 text-[10px] font-semibold leading-none text-black group-data-[collapsible=icon]:hidden">
                              {chatUnread > 99 ? "99+" : chatUnread}
                            </span>
                          )}
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
            {user?.avatar_url ? (
              <img src={user.avatar_url} alt={user.name} className="h-8 w-8 rounded-full object-cover" />
            ) : (
              <div className="h-8 w-8 rounded-full bg-gold text-gold-foreground flex items-center justify-center text-xs font-semibold">{initials}</div>
            )}
            <div className="flex flex-col leading-tight">
              <span className="text-xs font-medium text-sidebar-foreground">{user?.name || "User"}</span>
              <span className="text-[10px] text-sidebar-foreground/60">{ROLE_LABEL[user?.role] ?? user?.role ?? ""}</span>
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
