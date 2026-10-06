import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Plus, Loader2, Search, X, Trash2, Download, Pencil, Paperclip } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ProjectCombobox } from "@/components/project-combobox";
import { DocumentPicker, type PickedDocument } from "@/components/document-picker";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";

const PRIORITIES = ["Low", "Normal", "High", "Critical"];
const STATUSES_EDIT = ["Pending", "In Progress", "Review", "Completed"];
const blankForm = { project_id: "", title: "", description: "", assignee_id: "", priority: "Normal", due_date: "", status: "Pending" };

export default function Tasks() {
  const { props: pageProps } = usePage() as any;
  const currentUserRole = pageProps.auth?.user?.role ?? "";
  const isClientUser = ["client", "client_admin"].includes(currentUserRole);

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
  const [docs, setDocs]           = useState<PickedDocument[]>([]);
  const [saving, setSaving]       = useState(false);
  const [saveError, setSaveError] = useState("");

  // Delete confirm
  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting]   = useState(false);

  useEffect(() => {
    const userCall = isClientUser ? Promise.resolve([] as any[]) : api.getUsers();
    Promise.all([api.getTasks(), api.getProjects(), userCall])
      .then(([t, p, u]) => {
        setTasks(t); setProjects(p); setUsers(u as any[]);
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
    setDocs([]);
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
    setDocs((t.documents ?? []).map((d: any) => ({ id: d.id, name: d.file_name })));
    setSaveError("");
    setShowModal(true);
  }

  async function handleSave() {
    if (!form.title.trim() || !form.project_id) { setSaveError("Title and project are required."); return; }
    setSaving(true); setSaveError("");
    try {
      const payload = {
        ...form,
        project_id:   parseInt(form.project_id),
        assignee_id:  form.assignee_id ? parseInt(form.assignee_id) : undefined,
        document_ids: docs.map(d => d.id),
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

  const statuses = ["All", "Pending", "In Progress", "Review", "Completed"];

  // Compact, dense field style for the wide Add/Edit Task modal — same pattern
  // as the Indian Patent Invoice/Quotation form in Financial.tsx.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const fTa = "w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none";
  const FLbl = ({ children, req }: { children: React.ReactNode; req?: boolean }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}{req && <span className="text-destructive ml-0.5">*</span>}</div>
  );
  const FSectionHeading = ({ n, children }: { n: number; children: React.ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

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
            {!isClientUser && <Button onClick={openCreate}><Plus className="h-4 w-4 mr-2" />New Task</Button>}
          </>
        }
      />

      {/* ── Create / Edit Modal ────────────────────────────────────────────── */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <h2 className="font-display text-lg font-semibold">
                {editTask ? "Edit Task" : "New Task"}
              </h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-5">
              {saveError && (
                <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">{saveError}</div>
              )}

              <section>
                <FSectionHeading n={1}>Task Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div className="col-span-4">
                    <FLbl req>Task Title</FLbl>
                    <input value={form.title} onChange={e => setForm(p => ({ ...p, title: e.target.value }))}
                      placeholder="e.g. Draft patent claims" className={fInp} />
                  </div>
                  <div className="col-span-2">
                    <FLbl req>Case / Project</FLbl>
                    <ProjectCombobox
                      value={form.project_id}
                      projects={projects}
                      onSelect={(id) => setForm(p => ({ ...p, project_id: id }))}
                    />
                  </div>
                  <div className="col-span-2">
                    <FLbl>Assignee</FLbl>
                    <select value={form.assignee_id} onChange={e => setForm(p => ({ ...p, assignee_id: e.target.value }))} className={fInp}>
                      <option value="">Assign to (me)</option>
                      {users.map(u => <option key={u.id} value={u.id}>{u.name} ({u.role})</option>)}
                    </select>
                  </div>
                  <div>
                    <FLbl>Priority</FLbl>
                    <select value={form.priority} onChange={e => setForm(p => ({ ...p, priority: e.target.value }))} className={fInp}>
                      {PRIORITIES.map(p => <option key={p}>{p}</option>)}
                    </select>
                  </div>
                  <div>
                    <FLbl>Due Date</FLbl>
                    <input type="date" value={form.due_date} onChange={e => setForm(p => ({ ...p, due_date: e.target.value }))} className={fInp} />
                  </div>
                  {editTask && (
                    <div>
                      <FLbl>Status</FLbl>
                      <select value={form.status} onChange={e => setForm(p => ({ ...p, status: e.target.value }))} className={fInp}>
                        {STATUSES_EDIT.map(s => <option key={s}>{s}</option>)}
                      </select>
                    </div>
                  )}
                </div>
              </section>

              <section>
                <FSectionHeading n={2}>Description &amp; Documents</FSectionHeading>
                <div className="space-y-3">
                  <div>
                    <FLbl>Description</FLbl>
                    <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                      rows={2} placeholder="Optional details…"
                      className={fTa} />
                  </div>

                  {/* Documents — upload new, or pick from what's already on this case */}
                  <DocumentPicker
                    projectId={form.project_id ? parseInt(form.project_id) : null}
                    selected={docs}
                    onChange={setDocs}
                  />
                </div>
              </section>
            </div>

            <div className="flex gap-2 px-6 py-4 border-t border-border flex-shrink-0 justify-end">
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
              <Button className="bg-gold hover:bg-gold/90 text-black min-w-[120px]" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                {editTask ? "Save Changes" : "Create Task"}
              </Button>
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
                    {!isClientUser && <th className="px-4 py-3 text-left">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((t) => (
                    <tr key={t.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="font-medium flex items-center gap-1.5">
                          {t.title}
                          {t.documents?.length > 0 && (
                            <span className="flex items-center gap-0.5 text-muted-foreground" title={t.documents.map((d: any) => d.file_name).join(", ")}>
                              <Paperclip className="h-3 w-3" />
                              <span className="text-[10px]">{t.documents.length}</span>
                            </span>
                          )}
                        </div>
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
                      {!isClientUser && (
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
                      )}
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr>
                      <td colSpan={isClientUser ? 6 : 7} className="px-4 py-12 text-center text-muted-foreground">
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
