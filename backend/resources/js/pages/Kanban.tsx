import { Head, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Plus, ChevronRight, User, Calendar, Flag } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const COLUMNS = [
  { key: "Pending",     label: "Pending",     color: "border-slate-400",  bg: "bg-slate-400/10",  dot: "bg-slate-400" },
  { key: "In Progress", label: "In Progress", color: "border-blue-400",   bg: "bg-blue-400/10",   dot: "bg-blue-400" },
  { key: "Review",      label: "Review",      color: "border-amber-400",  bg: "bg-amber-400/10",  dot: "bg-amber-400" },
  { key: "Completed",   label: "Completed",   color: "border-green-400",  bg: "bg-green-400/10",  dot: "bg-green-400" },
];

const PRIORITY_COLOR: Record<string, string> = {
  Critical: "bg-red-500/10 text-red-400 border-red-500/20",
  High:     "bg-orange-500/10 text-orange-400 border-orange-500/20",
  Normal:   "bg-blue-500/10 text-blue-400 border-blue-500/20",
  Low:      "bg-slate-500/10 text-slate-400 border-slate-500/20",
};

function fmtDate(d: string | null | undefined) {
  if (!d) return null;
  const [y, m, day] = d.split("T")[0].split("-");
  return `${day}-${m}-${y}`;
}

function isOverdue(d: string | null | undefined) {
  if (!d) return false;
  return new Date(d) < new Date();
}

export default function Kanban() {
  const [tasks, setTasks]     = useState<any[]>([]);
  const [users, setUsers]     = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [moving, setMoving]   = useState<number | null>(null);
  const [filterUser, setFilterUser] = useState<string>("all");

  const load = () => {
    Promise.all([api.getTasks(), api.getUsers()])
      .then(([t, u]) => { setTasks(Array.isArray(t) ? t : []); setUsers(Array.isArray(u) ? u : []); })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  const displayed = filterUser === "all"
    ? tasks
    : tasks.filter((t) => String(t.assignee_id) === filterUser);

  const grouped = COLUMNS.reduce<Record<string, any[]>>((acc, col) => {
    acc[col.key] = displayed.filter((t) => t.status === col.key);
    return acc;
  }, {});

  async function moveTask(taskId: number, newStatus: string) {
    setMoving(taskId);
    setTasks((prev) => prev.map((t) => t.id === taskId ? { ...t, status: newStatus } : t));
    try {
      await api.updateTask(taskId, { status: newStatus } as any);
    } catch {
      load();
    } finally {
      setMoving(null);
    }
  }

  function openTask(id: number) {
    router.visit(`/tasks?open=${id}`);
  }

  if (loading) return (
    <AppLayout>
      <Head title="Kanban Board" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Kanban Board" />
      <PageHeader
        eyebrow="Practice"
        title="Kanban Board"
        description={`${tasks.length} tasks across ${COLUMNS.length} columns`}
        actions={
          <div className="flex items-center gap-2">
            <select
              value={filterUser}
              onChange={(e) => setFilterUser(e.target.value)}
              className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
            >
              <option value="all">All assignees</option>
              {users.map((u) => (
                <option key={u.id} value={String(u.id)}>{u.name}</option>
              ))}
            </select>
            <Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => router.visit("/tasks")}>
              <Plus className="h-4 w-4 mr-2" /> New Task
            </Button>
          </div>
        }
      />

      <div className="px-6 py-6 overflow-x-auto">
        <div className="flex gap-4 min-w-max">
          {COLUMNS.map((col) => {
            const cards = grouped[col.key] ?? [];
            return (
              <div key={col.key} className="w-72 flex flex-col gap-3">
                {/* Column header */}
                <div className={`flex items-center justify-between px-3 py-2 rounded-lg border ${col.color} ${col.bg}`}>
                  <div className="flex items-center gap-2">
                    <span className={`h-2 w-2 rounded-full ${col.dot}`} />
                    <span className="font-semibold text-sm">{col.label}</span>
                  </div>
                  <Badge variant="outline" className="text-[10px] h-5 px-1.5">{cards.length}</Badge>
                </div>

                {/* Cards */}
                <div className="flex flex-col gap-2 min-h-[200px]">
                  {cards.length === 0 && (
                    <div className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-muted-foreground">
                      No tasks here
                    </div>
                  )}
                  {cards.map((task) => {
                    const assignee = users.find((u) => u.id === task.assignee_id);
                    const overdue = isOverdue(task.due_date) && task.status !== "Completed";
                    const otherCols = COLUMNS.filter((c) => c.key !== col.key);
                    return (
                      <div
                        key={task.id}
                        className={`rounded-lg border border-border bg-card p-3 shadow-sm hover:shadow-md transition-shadow cursor-pointer group ${overdue ? "border-red-400/40" : ""}`}
                        onClick={() => openTask(task.id)}
                      >
                        <div className="flex items-start justify-between gap-2 mb-2">
                          <p className="text-sm font-medium leading-snug line-clamp-2">{task.title}</p>
                          {moving === task.id && <Loader2 className="h-3.5 w-3.5 animate-spin text-gold shrink-0" />}
                        </div>

                        {task.project && (
                          <p className="text-[10px] text-muted-foreground mb-2 font-mono">
                            {task.project.docket_number ?? task.project.project_code}
                          </p>
                        )}

                        <div className="flex items-center gap-1.5 flex-wrap mb-2">
                          {task.priority && (
                            <Badge variant="outline" className={`text-[10px] h-4 px-1 py-0 ${PRIORITY_COLOR[task.priority] ?? ""}`}>
                              <Flag className="h-2.5 w-2.5 mr-0.5" />{task.priority}
                            </Badge>
                          )}
                          {task.due_date && (
                            <Badge variant="outline" className={`text-[10px] h-4 px-1 py-0 ${overdue ? "border-red-400/40 text-red-400" : "text-muted-foreground"}`}>
                              <Calendar className="h-2.5 w-2.5 mr-0.5" />{fmtDate(task.due_date)}
                            </Badge>
                          )}
                        </div>

                        <div className="flex items-center justify-between">
                          {assignee ? (
                            <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                              <div className="h-4 w-4 rounded-full bg-gold/20 flex items-center justify-center text-[8px] font-bold text-gold">
                                {assignee.name.split(" ").map((n: string) => n[0]).join("").substring(0, 2).toUpperCase()}
                              </div>
                              {assignee.name.split(" ")[0]}
                            </div>
                          ) : (
                            <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                              <User className="h-3 w-3" /> Unassigned
                            </div>
                          )}

                          {/* Move to column menu */}
                          <div className="opacity-0 group-hover:opacity-100 flex gap-1" onClick={(e) => e.stopPropagation()}>
                            {otherCols.map((dest) => (
                              <button
                                key={dest.key}
                                title={`Move to ${dest.label}`}
                                onClick={() => moveTask(task.id, dest.key)}
                                className={`text-[9px] px-1.5 py-0.5 rounded border ${dest.color} ${dest.bg} hover:opacity-80 transition-opacity font-medium`}
                              >
                                <ChevronRight className="h-2.5 w-2.5 inline -mt-px" />
                                {dest.label.split(" ")[0]}
                              </button>
                            ))}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </AppLayout>
  );
}
