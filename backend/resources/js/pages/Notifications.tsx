import { Head, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Bell, CheckSquare, AlertTriangle, DollarSign, MessageSquare, Settings, User, X, Check, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

type NType = "task" | "deadline" | "payment" | "message" | "system" | "client";
type NFilter = "all" | "unread" | "deadline" | "system";

interface Notif {
  id: number;
  type: NType;
  title: string;
  description: string;
  meta?: Record<string, any>;
  action_url?: string | null;
  read: boolean;
  created_at: string;
}

const typeConfig: Record<string, { icon: React.ElementType; color: string }> = {
  task:     { icon: CheckSquare,    color: "text-blue-500"            },
  deadline: { icon: AlertTriangle,  color: "text-red-500"             },
  payment:  { icon: DollarSign,     color: "text-green-500"           },
  message:  { icon: MessageSquare,  color: "text-purple-500"          },
  system:   { icon: Settings,       color: "text-muted-foreground"    },
  client:   { icon: User,           color: "text-gold"                },
};

function timeAgo(ts: string): string {
  const diff = Date.now() - new Date(ts).getTime();
  const mins  = Math.floor(diff / 60000);
  if (mins < 1) return "Just now";
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs} hour${hrs > 1 ? "s" : ""} ago`;
  const days = Math.floor(hrs / 24);
  return `${days} day${days > 1 ? "s" : ""} ago`;
}

export default function Notifications() {
  const [items, setItems]   = useState<Notif[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<NFilter>("all");
  const [visible, setVisible] = useState(15);

  useEffect(() => {
    api.getNotifications()
      .then((data) => setItems(data.map((n) => ({
        id: n.id,
        type: (n as any).type ?? "system",
        title: n.title,
        description: (n as any).description ?? (n as any).message ?? "",
        meta: (n as any).meta,
        action_url: (n as any).action_url ?? null,
        read: (n as any).read ?? n.is_read ?? false,
        created_at: n.created_at,
      }) as Notif)))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const unreadCount = items.filter((n) => !n.read).length;

  async function markAllRead() {
    await api.markAllNotificationsRead().catch(() => {});
    setItems((prev) => prev.map((n) => ({ ...n, read: true })));
  }

  async function markRead(id: number) {
    if (items.find((n) => n.id === id)?.read) return;
    await api.markNotificationRead(id).catch(() => {});
    setItems((prev) => prev.map((n) => n.id === id ? { ...n, read: true } : n));
  }

  async function openNotif(n: Notif) {
    await markRead(n.id);
    if (n.action_url) router.visit(n.action_url);
  }

  async function dismiss(id: number) {
    await api.dismissNotification(id).catch(() => {});
    setItems((prev) => prev.filter((n) => n.id !== id));
  }

  const filtered = items.filter((n) => {
    if (filter === "unread")   return !n.read;
    if (filter === "deadline") return n.type === "deadline";
    if (filter === "system")   return n.type === "system";
    return true;
  });

  const shown = filtered.slice(0, visible);

  return (
    <AppLayout>
      <Head title="Notifications" />
      <PageHeader
        eyebrow="Engagement"
        title="Notifications"
        description="Deadline alerts, task updates, and activity feed"
        actions={
          unreadCount > 0 ? (
            <Button variant="outline" size="sm" onClick={markAllRead}>
              <Check className="h-4 w-4 mr-2" /> Mark all read
            </Button>
          ) : undefined
        }
      />

      <div className="px-8 py-6 space-y-4">
        {/* Filter tabs */}
        <div className="flex items-center gap-2">
          {(["all", "unread", "deadline", "system"] as NFilter[]).map((f) => (
            <button
              key={f}
              onClick={() => { setFilter(f); setVisible(15); }}
              className={`px-4 py-1.5 rounded-full text-sm font-medium border transition-colors capitalize ${
                filter === f ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"
              }`}
            >
              {f}
              {f === "unread" && unreadCount > 0 && (
                <Badge variant="destructive" className="ml-2 h-4 text-[10px] px-1">{unreadCount}</Badge>
              )}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-7 w-7 animate-spin text-gold" />
          </div>
        ) : (
          <>
            <div className="space-y-1">
              {shown.length === 0 ? (
                <div className="py-16 text-center text-muted-foreground text-sm">
                  {filter === "unread" ? "All caught up — no unread notifications." : "No notifications in this category."}
                </div>
              ) : (
                shown.map((n) => {
                  const cfg = typeConfig[n.type] ?? typeConfig.system;
                  const Icon = cfg.icon;
                  return (
                    <div
                      key={n.id}
                      onClick={() => openNotif(n)}
                      className={`flex items-start gap-4 p-4 rounded-lg border cursor-pointer transition-colors ${
                        !n.read ? "bg-muted/20 border-border" : "bg-transparent border-transparent hover:border-border"
                      }`}
                    >
                      <div className={`mt-0.5 flex-shrink-0 ${cfg.color}`}>
                        <Icon className="h-5 w-5" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className={`text-sm ${!n.read ? "font-semibold text-foreground" : "font-medium text-foreground/80"}`}>
                            {n.title}
                          </span>
                          {!n.read && <span className="h-2 w-2 rounded-full bg-gold flex-shrink-0" />}
                          {n.meta?.role && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded border border-border text-muted-foreground font-mono">
                              {n.meta.role}
                            </span>
                          )}
                        </div>
                        <p className="text-xs text-muted-foreground mt-0.5 leading-relaxed">{n.description}</p>
                        {(n.meta?.days_remaining ?? 0) > 0 && (
                          <div className="mt-1 flex items-center gap-3 text-[11px]">
                            {n.meta?.docket_number && (
                              <span className="font-mono text-muted-foreground">{n.meta.docket_number}</span>
                            )}
                            <span className={(n.meta?.days_remaining ?? 0) <= 3 ? "text-destructive font-medium" : "text-amber-500"}>
                              {n.meta?.days_remaining} day{n.meta?.days_remaining !== 1 ? "s" : ""} left
                            </span>
                          </div>
                        )}
                        {(n.meta?.days_overdue ?? 0) > 0 && (
                          <div className="mt-1 text-[11px] text-destructive font-medium">
                            {n.meta?.days_overdue} day{n.meta?.days_overdue !== 1 ? "s" : ""} overdue
                          </div>
                        )}
                        <span className="text-[11px] text-muted-foreground/60 mt-1 block">{timeAgo(n.created_at)}</span>
                      </div>
                      <button
                        onClick={(e) => { e.stopPropagation(); dismiss(n.id); }}
                        className="flex-shrink-0 mt-0.5 p-1 rounded hover:bg-muted text-muted-foreground hover:text-foreground"
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  );
                })
              )}
            </div>

            {visible < filtered.length && (
              <button
                onClick={() => setVisible((v) => v + 15)}
                className="w-full py-2 text-sm text-muted-foreground hover:text-foreground border border-dashed border-border rounded-lg"
              >
                Load more ({filtered.length - visible} remaining)
              </button>
            )}
          </>
        )}
      </div>
    </AppLayout>
  );
}
