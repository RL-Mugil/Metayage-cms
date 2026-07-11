import { Head } from "@inertiajs/react";
import { useEffect, useState, useRef } from "react";
import { createPortal } from "react-dom";
import { Plus, Loader2, Search, X, Trash2, Download, Pencil } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";

const PRIORITIES = ["Low", "Normal", "High", "Critical"];
const STATUSES_EDIT = ["Pending", "In Progress", "Review", "Completed"];
const blankForm = { project_id: "", title: "", description: "", assignee_id: "", priority: "Normal", due_date: "", status: "Pending" };

// Portal-based searchable combobox — docket-first search
function ProjectCombobox({ value, projects, onSelect }: {
  value: string;
  projects: any[];
  onSelect: (id: string) => void;
}) {
  const [q, setQ]       = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef        = useRef<HTMLInputElement>(null);

  const selected = projects.find((p) => String(p.id) === value);
  const filtered = projects
    .filter((p) => {
      if (!q) return true;
      const lq = q.toLowerCase();
      return (
        (p.docket_number ?? "").toLowerCase().includes(lq) ||
        (p.project_code ?? "").toLowerCase().includes(lq) ||
        (p.project_name ?? "").toLowerCase().includes(lq)
      );
    })
    .slice(0, 30);

  useEffect(() => {
    if (!open) return;
    const updatePos = () => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); };
    window.addEventListener("scroll", updatePos, true);
    return () => window.removeEventListener("scroll", updatePos, true);
  }, [open]);

  const ic = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: rect.width, zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-56 overflow-y-auto"
        >
          {filtered.map((p) => (
            <button key={p.id} type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(String(p.id)); setOpen(false); }}
              className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-baseline gap-2 ${String(p.id) === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
              <span className="font-mono text-[11px] text-gold flex-shrink-0">{p.docket_number ?? p.project_code}</span>
              <span className="truncate text-xs text-muted-foreground">{p.project_name}</span>
            </button>
          ))}
          {filtered.length === 0 && <p className="px-3 py-3 text-xs text-muted-foreground text-center">No matches</p>}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected ? `${selected.docket_number ?? selected.project_code} – ${selected.project_name}` : "")}
        onFocus={() => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); setOpen(true); setQ(""); }}
        onBlur={() => setTimeout(() => setOpen(false), 200)}
        onChange={(e) => setQ(e.target.value)}
        placeholder="Search docket number or case name…"
        className={ic}
      />
      {dropdown}
    </div>
  );
}

export default function Tasks() {
  const [tasks, setTasks]     = useState<any[]>([]);
  const [projects, setProjects] = useState<any[]>([]);
  const [users, setUsers]     = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch]   = useState("");
  const [filterStatus, setFilterStatus] = useState("All");

  // Modal state — shared for create & edit
  const [showModal, setShowModal] = useState(false);
  const [editTask, setEditTask]   = useState<any>(null);   // null = create mode
  const [form, setForm]           = useState(blankForm);
  const [saving, setSaving]       = useState(false);
  const [saveError, setSaveError] = useState("");

  // Delete confirm
  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting]   = useState(false);

  useEffect(() => {
    Promise.all([api.getTasks(), api.getProjects(), api.getUsers()])
      .then(([t, p, u]) => {
        setTasks(t); setProjects(p); setUsers(u);
        const params = new URLSearchParams(window.location.search);
        const openId = params.get("open");
        if (openId) {
          const target = (t as any[]).find((task: any) => String(task.id) === openId);
          if (target) openEdit(target);
          params.delete("open");
          window.history.replaceState({}, "", window.location.pathname + (params.toString() ? "?" + params.toString() : ""));
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const filtered = tasks.filter(t => {
    const q = search.toLowerCase();
    const matchSearch = !search ||
      t.title?.toLowerCase().includes(q) ||
      t.project?.project_code?.toLowerCase().includes(q) ||
      t.project?.docket_number?.toLowerCase().includes(q);
    const matchStatus = filterStatus === "All" || t.status === filterStatus;
    return matchSearch && matchStatus;
  });

  function openCreate() {
    setEditTask(null);
    setForm(blankForm);
    setSaveError("");
    setShowModal(true);
  }

  function openEdit(t: any) {
    setEditTask(t);
    setForm({
      project_id:  t.project_id  ? String(t.project_id)  : "",
      title:       t.title       ?? "",
      description: t.description ?? "",
      assignee_id: t.assignee_id ? String(t.assignee_id) : "",
      priority:    t.priority    ?? "Normal",
      due_date:    t.due_date    ? t.due_date.split("T")[0] : "",
      status:      t.status      ?? "Pending",
    });
    setSaveError("");
    setShowModal(true);
  }

  async function handleSave() {
    if (!form.title.trim() || !form.project_id) { setSaveError("Title and project are required."); return; }
    setSaving(true); setSaveError("");
    try {
      const payload = {
        ...form,
        project_id:  parseInt(form.project_id),
        assignee_id: form.assignee_id ? parseInt(form.assignee_id) : undefined,
      };
      if (editTask) {
        const updated = await api.updateTask(editTask.id, payload as any);
        setTasks(prev => prev.map(t => {
          if (t.id !== editTask.id) return t;
          const proj = projects.find(p => p.id === parseInt(form.project_id));
          const user = users.find(u => u.id === (form.assignee_id ? parseInt(form.assignee_id) : -1));
          return { ...t, ...updated, project: proj ?? t.project, assignee: user ?? t.assignee };
        }));
      } else {
        const created = await api.createTask({ ...payload, status: "Pending" } as any);
        const proj = projects.find(p => p.id === parseInt(form.project_id));
        const user = users.find(u => u.id === (form.assignee_id ? parseInt(form.assignee_id) : -1));
        setTasks(prev => [{ ...created, project: proj ?? null, assignee: user ?? null }, ...prev]);
      }
      setShowModal(false);
    } catch (e: any) { setSaveError(e.message || "Failed to save task."); }
    finally { setSaving(false); }
  }

  async function handleDelete() {
    if (!delTarget) return;
    setDeleting(true);
    try {
      await api.deleteTask(delTarget.id);
      setTasks(prev => prev.filter(t => t.id !== delTarget.id));
      setDelTarget(null);
    } catch (e: any) { alert(e.message || "Delete failed."); }
    finally { setDeleting(false); }
  }

  function handleExport() {
    const rows = filtered.map(t => ({
      Title: t.title, Project: t.project?.project_code, Assignee: t.assignee?.name,
      Priority: t.priority, Status: t.status, Due: fmtDate(t.due_date),
    }));
    downloadCSV(`tasks-${new Date().toISOString().slice(0,10)}.csv`, rows);
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
  const statuses = ["All", "Pending", "In Progress", "Review", "Completed"];

  return (
    <AppLayout>
      <Head title="Tasks" />
      <PageHeader
        eyebrow="Work"
        title="Tasks"
        description={`${tasks.length} tasks`}
        actions={
          <>
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            <Button onClick={openCreate}><Plus className="h-4 w-4 mr-2" />New Task</Button>
          </>
        }
      />

      {/* ── Create / Edit Modal ────────────────────────────────────────────── */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">
                {editTask ? "Edit Task" : "New Task"}
              </h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            {saveError && (
              <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>
            )}

            <div className="space-y-3">
              {/* Title */}
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Task Title *</label>
                <input value={form.title} onChange={e => setForm(p => ({ ...p, title: e.target.value }))}
                  placeholder="e.g. Draft patent claims" className={inputCls} />
              </div>

              {/* Case + Assignee */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Case / Project *</label>
                  <ProjectCombobox
                    value={form.project_id}
                    projects={projects}
                    onSelect={(id) => setForm(p => ({ ...p, project_id: id }))}
                  />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Assignee</label>
                  <select value={form.assignee_id} onChange={e => setForm(p => ({ ...p, assignee_id: e.target.value }))} className={inputCls}>
                    <option value="">Assign to (me)</option>
                    {users.map(u => <option key={u.id} value={u.id}>{u.name} ({u.role})</option>)}
                  </select>
                </div>
              </div>

              {/* Priority + Due Date */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Priority</label>
                  <select value={form.priority} onChange={e => setForm(p => ({ ...p, priority: e.target.value }))} className={inputCls}>
                    {PRIORITIES.map(p => <option key={p}>{p}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Due Date</label>
                  <input type="date" value={form.due_date} onChange={e => setForm(p => ({ ...p, due_date: e.target.value }))} className={inputCls} />
                </div>
              </div>

              {/* Status — only shown in edit mode */}
              {editTask && (
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Status</label>
                  <select value={form.status} onChange={e => setForm(p => ({ ...p, status: e.target.value }))} className={inputCls}>
                    {STATUSES_EDIT.map(s => <option key={s}>{s}</option>)}
                  </select>
                </div>
              )}

              {/* Description */}
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Description</label>
                <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                  rows={3} placeholder="Optional details…"
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
              </div>
            </div>

            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                {editTask ? "Save Changes" : "Create Task"}
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Delete Confirm ─────────────────────────────────────────────────── */}
      {delTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center gap-3 mb-4">
              <div className="h-10 w-10 rounded-full bg-destructive/10 flex items-center justify-center flex-shrink-0">
                <Trash2 className="h-5 w-5 text-destructive" />
              </div>
              <div>
                <h3 className="font-semibold">Delete Task</h3>
                <p className="text-sm text-muted-foreground mt-0.5">
                  Delete <strong>"{delTarget.title}"</strong>? This cannot be undone.
                </p>
              </div>
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={() => setDelTarget(null)}>Cancel</Button>
              <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Delete"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Table ─────────────────────────────────────────────────────────── */}
      <div className="px-8 py-6 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input placeholder="Search tasks, docket, code…" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
          </div>
          <div className="flex items-center gap-2">
            {statuses.map(s => (
              <button key={s} onClick={() => setFilterStatus(s)}
                className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${filterStatus === s ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"}`}>
                {s}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Task</th>
                    <th className="px-4 py-3 text-left">Case</th>
                    <th className="px-4 py-3 text-left">Assignee</th>
                    <th className="px-4 py-3 text-left">Priority</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Due Date</th>
                    <th className="px-4 py-3 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((t) => (
                    <tr key={t.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="font-medium">{t.title}</div>
                        {t.description && (
                          <div className="text-xs text-muted-foreground truncate max-w-xs">{t.description}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                        {t.project?.docket_number ?? t.project?.project_code ?? "—"}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{t.assignee?.name || "Unassigned"}</td>
                      <td className="px-4 py-3">
                        <Badge variant={t.priority === "High" || t.priority === "Critical" ? "destructive" : "outline"}>
                          {t.priority}
                        </Badge>
                      </td>
                      <td className="px-4 py-3">
                        <Badge variant={statusColor(t.status)}>{t.status}</Badge>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtDate(t.due_date)}</td>
                      <td className="px-4 py-3">
                        <div className="flex gap-1">
                          <Button size="sm" variant="outline" className="h-7 w-7 p-0" title="Edit" onClick={() => openEdit(t)}>
                            <Pencil className="h-3 w-3" />
                          </Button>
                          <Button size="sm" variant="outline" title="Delete"
                            className="h-7 w-7 p-0 text-destructive border-destructive/30 hover:bg-destructive/10"
                            onClick={() => setDelTarget(t)}>
                            <Trash2 className="h-3 w-3" />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr>
                      <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">
                        {tasks.length === 0 ? "No tasks yet. Create your first task." : "No tasks match your filter."}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
