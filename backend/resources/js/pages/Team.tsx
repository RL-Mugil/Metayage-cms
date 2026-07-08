import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Users, Grid, List, Search, Mail, Briefcase, Loader2, Plus, X, FolderOpen, Building2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const DEPTS = ["Legal", "Operations", "HR", "Finance", "Engineering", "Business Development", "Administration"];
const ROLES = ["Director", "HR", "Patent Attorney", "Patent Analyst", "Patent Engineer", "Finance Manager", "Paralegal", "Consultant", "System Admin"];
const LOCATIONS = ["Remote", "Coimbatore", "Chennai", "Bengaluru", "Hyderabad", "Pollachi"];
const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

function getInitials(name: string) {
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
}

interface WorkloadEntry {
  user_id: number;
  project_count: number;
  tracker_count: number;
  total: number;
}

const MAX_WORKLOAD = 20; // total >= 20 cases = 100%

function calcWorkloadPct(total: number) {
  return Math.min(100, Math.round((total / MAX_WORKLOAD) * 100));
}

interface AssignedProject {
  id: number;
  project_code: string;
  docket_number: string | null;
  client_name: string | null;
  status: string | null;
  hard_deadline: string | null;
}

interface ManagedClient {
  id: number;
  client_code: string;
  company_name: string | null;
  status: string | null;
  gst_type: string | null;
}

export default function Team() {
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [viewMode, setViewMode] = useState<"grid" | "list">("grid");
  const [search, setSearch] = useState("");
  const [workload, setWorkload] = useState<Record<number, WorkloadEntry>>({});
  const [notice, setNotice] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  const [showAddModal, setShowAddModal] = useState(false);
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [addForm, setAddForm] = useState({ full_name: "", work_email: "", department_name: "", designation_title: "", work_location: "Coimbatore", date_of_joining: "" });
  const [inviteForm, setInviteForm] = useState({ name: "", email: "" });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  // Assigned projects modal
  const [assignedModal, setAssignedModal] = useState<{ emp: any; projects: AssignedProject[]; loading: boolean } | null>(null);

  // Client Manager modal
  const [clientMgrModal, setClientMgrModal] = useState<{ emp: any; clients: ManagedClient[]; loading: boolean } | null>(null);

  useEffect(() => {
    Promise.all([
      api.getEmployees(),
      api.getEmployeeWorkload().catch(() => [] as WorkloadEntry[]),
    ]).then(([emps, wl]) => {
      setEmployees(emps);
      const map: Record<number, WorkloadEntry> = {};
      (wl as WorkloadEntry[]).forEach((w) => { map[w.user_id] = w; });
      setWorkload(map);
    }).catch(() => {}).finally(() => setLoading(false));
  }, []);

  async function handleAddEmployee() {
    if (!addForm.full_name.trim() || !addForm.work_email.trim()) { setSaveError("Name and email are required."); return; }
    setSaving(true); setSaveError("");
    try {
      const created = await api.createEmployee(addForm);
      setEmployees(prev => [created, ...prev]);
      setShowAddModal(false);
      setAddForm({ full_name: "", work_email: "", department_name: "", designation_title: "", work_location: "Coimbatore", date_of_joining: "" });
      // Refresh workload after adding
      api.getEmployeeWorkload().then((wl) => {
        const map: Record<number, WorkloadEntry> = {};
        (wl as WorkloadEntry[]).forEach((w) => { map[w.user_id] = w; });
        setWorkload(map);
      }).catch(() => {});
    } catch (e: any) { setSaveError(e.message || "Failed to add employee."); }
    finally { setSaving(false); }
  }

  async function handleInvite() {
    if (!inviteForm.name.trim() || !inviteForm.email.trim()) {
      setSaveError("Name and email are required.");
      return;
    }
    setSaving(true);
    setSaveError("");
    try {
      const response = await api.inviteTeamMember(inviteForm);
      setNotice({ kind: "ok", text: response.message });
      setInviteForm({ name: "", email: "" });
      setShowInviteModal(false);
    } catch (e: any) {
      setSaveError(e.message || "Failed to create invitation record.");
    } finally {
      setSaving(false);
    }
  }

  async function openAssignedProjects(emp: any) {
    const userId = emp.user_id ?? emp.user?.id;
    if (!userId) return;
    setAssignedModal({ emp, projects: [], loading: true });
    try {
      const params = new URLSearchParams({ patent_engineer_id: String(userId), per_page: "100" });
      const res = await api.getProjectsPaged(params) as any;
      const list: AssignedProject[] = (Array.isArray(res) ? res : (res?.data ?? [])).map((p: any) => ({
        id: p.id,
        project_code: p.project_code,
        docket_number: p.docket_number ?? null,
        client_name: p.client?.company_name ?? p.client_name ?? null,
        status: p.status ?? null,
        hard_deadline: p.hard_deadline ?? null,
      }));
      setAssignedModal({ emp, projects: list, loading: false });
    } catch {
      setAssignedModal((prev) => prev ? { ...prev, loading: false } : null);
    }
  }

  async function openClientManagerModal(emp: any) {
    const userId = emp.user_id ?? emp.user?.id;
    if (!userId) return;
    setClientMgrModal({ emp, clients: [], loading: true });
    try {
      // Fetch all projects where this employee is the assigned manager (client manager role)
      const params = new URLSearchParams({ assigned_manager_id: String(userId), per_page: "500" });
      const res = await api.getProjectsPaged(params) as any;
      const projects: any[] = Array.isArray(res) ? res : (res?.data ?? []);

      // Deduplicate clients from those projects
      const seen = new Set<number>();
      const list: ManagedClient[] = [];
      for (const p of projects) {
        const c = p.client;
        if (!c) continue;
        if (seen.has(c.id)) continue;
        seen.add(c.id);
        list.push({
          id: c.id,
          client_code: c.client_code ?? "—",
          company_name: c.company_name ?? c.legal_name ?? "—",
          status: c.status ?? null,
          gst_type: c.gst_type ?? null,
        });
      }
      list.sort((a, b) => (a.company_name ?? "").localeCompare(b.company_name ?? ""));
      setClientMgrModal({ emp, clients: list, loading: false });
    } catch {
      setClientMgrModal((prev) => prev ? { ...prev, loading: false } : null);
    }
  }

  const filtered = employees.filter((e) => {
    const name = (e.user?.name ?? "").toLowerCase();
    const role = (e.designation?.title ?? "").toLowerCase();
    const q = search.toLowerCase();
    return name.includes(q) || role.includes(q);
  });

  const totalMembers = employees.length;
  const activeCount = employees.filter(
    (e) => (e.employment_status ?? "").toLowerCase() === "active"
  ).length;
  const departments = new Set(employees.map((e) => e.department?.name).filter(Boolean)).size;

  function getWorkloadForEmp(emp: any) {
    const userId = emp.user_id ?? emp.user?.id;
    if (!userId) return { total: 0, pct: 0 };
    const w = workload[userId];
    if (!w) return { total: 0, pct: 0 };
    return { total: w.total, pct: calcWorkloadPct(w.total) };
  }

  if (loading) {
    return (
      <AppLayout>
        <Head title="Team" />
        <div className="flex h-screen items-center justify-center bg-background">
          <div className="flex flex-col items-center gap-2">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
            <p className="text-sm text-muted-foreground">Loading team members...</p>
          </div>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title="Team" />
      <PageHeader
        eyebrow="People"
        title="Team"
        description="Manage your team members, roles, and workloads across all departments."
        actions={
          <>
            <Button variant="outline" onClick={() => { setShowInviteModal(true); setSaveError(""); }}>
              <Mail className="h-4 w-4 mr-2" />Invite Member
            </Button>
            <Button onClick={() => { setShowAddModal(true); setSaveError(""); }}>
              <Plus className="h-4 w-4 mr-2" />Add Employee
            </Button>
          </>
        }
      />

      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Add Employee</h2>
              <button onClick={() => setShowAddModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">Full Name *</label>
                  <input value={addForm.full_name} onChange={e => setAddForm(p => ({ ...p, full_name: e.target.value }))} placeholder="Priya Sharma" className={inputCls} /></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Work Email *</label>
                  <input type="email" value={addForm.work_email} onChange={e => setAddForm(p => ({ ...p, work_email: e.target.value }))} placeholder="priya@firm.com" className={inputCls} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">Department</label>
                  <select value={addForm.department_name} onChange={e => setAddForm(p => ({ ...p, department_name: e.target.value }))} className={inputCls}>
                    <option value="">Select</option>{DEPTS.map(d => <option key={d}>{d}</option>)}</select></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Role / Designation</label>
                  <select value={addForm.designation_title} onChange={e => setAddForm(p => ({ ...p, designation_title: e.target.value }))} className={inputCls}>
                    <option value="">Select</option>{ROLES.map(r => <option key={r}>{r}</option>)}</select></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">Location</label>
                  <select value={addForm.work_location} onChange={e => setAddForm(p => ({ ...p, work_location: e.target.value }))} className={inputCls}>
                    {LOCATIONS.map(l => <option key={l}>{l}</option>)}</select></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Date of Joining</label>
                  <input type="date" value={addForm.date_of_joining} onChange={e => setAddForm(p => ({ ...p, date_of_joining: e.target.value }))} className={inputCls} /></div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleAddEmployee} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Add Employee
              </Button>
              <Button variant="outline" onClick={() => setShowAddModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {showInviteModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Invite Team Member</h2>
              <button onClick={() => setShowInviteModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <p className="text-sm text-muted-foreground mb-4">Create a workspace account and send the access email immediately.</p>
            <div className="space-y-3">
              <input value={inviteForm.name} onChange={e => setInviteForm(p => ({ ...p, name: e.target.value }))} placeholder="Priya Sharma" className={inputCls} />
              <input type="email" value={inviteForm.email} onChange={e => setInviteForm(p => ({ ...p, email: e.target.value }))} placeholder="colleague@firm.com" className={inputCls} />
            </div>
            <div className="flex gap-2 mt-4">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleInvite} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Mail className="h-4 w-4 mr-2" />}Create Invite
              </Button>
              <Button variant="outline" onClick={() => setShowInviteModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Assigned Projects Modal */}
      {assignedModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col">
            <div className="flex items-center justify-between p-5 border-b border-border">
              <div>
                <h2 className="font-display text-base font-semibold">
                  Assigned Projects — {assignedModal.emp.user?.name ?? assignedModal.emp.full_name ?? "Employee"}
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Cases where this employee is Patent Engineer (Projects) or PR (Tracker)
                </p>
              </div>
              <button onClick={() => setAssignedModal(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <div className="flex-1 overflow-y-auto p-5">
              {assignedModal.loading ? (
                <div className="flex items-center justify-center py-12">
                  <Loader2 className="h-6 w-6 animate-spin text-gold" />
                </div>
              ) : assignedModal.projects.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 gap-3 text-muted-foreground">
                  <FolderOpen className="h-10 w-10 opacity-30" />
                  <p className="text-sm">No projects assigned as Patent Engineer.</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="text-xs uppercase tracking-wider text-muted-foreground">
                    <tr className="border-b border-border">
                      <th className="pb-2 text-left">Code</th>
                      <th className="pb-2 text-left">Docket</th>
                      <th className="pb-2 text-left">Client</th>
                      <th className="pb-2 text-left">Status</th>
                      <th className="pb-2 text-left">Hard Deadline</th>
                    </tr>
                  </thead>
                  <tbody>
                    {assignedModal.projects.map((p) => (
                      <tr key={p.id} className="border-b border-border/50 hover:bg-muted/20">
                        <td className="py-2.5 font-mono text-xs">{p.project_code}</td>
                        <td className="py-2.5 font-mono text-xs text-muted-foreground">{p.docket_number ?? "—"}</td>
                        <td className="py-2.5 text-xs truncate max-w-[160px]">{p.client_name ?? "—"}</td>
                        <td className="py-2.5">
                          <Badge variant="outline" className="text-[10px]">{p.status ?? "—"}</Badge>
                        </td>
                        <td className="py-2.5 text-xs text-muted-foreground font-mono">
                          {p.hard_deadline ? new Date(p.hard_deadline + "T00:00:00").toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }) : "—"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div className="p-4 border-t border-border text-right">
              <Button variant="outline" onClick={() => setAssignedModal(null)}>Close</Button>
            </div>
          </div>
        </div>
      )}

      {/* Client Manager Modal */}
      {clientMgrModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col">
            <div className="flex items-center justify-between p-5 border-b border-border">
              <div>
                <h2 className="font-display text-base font-semibold">
                  Client Manager — {clientMgrModal.emp.user?.name ?? clientMgrModal.emp.full_name ?? "Employee"}
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Clients where this employee is the Account Manager
                </p>
              </div>
              <button onClick={() => setClientMgrModal(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <div className="flex-1 overflow-y-auto p-5">
              {clientMgrModal.loading ? (
                <div className="flex items-center justify-center py-12">
                  <Loader2 className="h-6 w-6 animate-spin text-gold" />
                </div>
              ) : clientMgrModal.clients.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 gap-3 text-muted-foreground">
                  <Building2 className="h-10 w-10 opacity-30" />
                  <p className="text-sm">No clients assigned as Account Manager.</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="text-xs uppercase tracking-wider text-muted-foreground">
                    <tr className="border-b border-border">
                      <th className="pb-2 text-left">Code</th>
                      <th className="pb-2 text-left">Client Name</th>
                      <th className="pb-2 text-left">Status</th>
                      <th className="pb-2 text-left">GST Type</th>
                    </tr>
                  </thead>
                  <tbody>
                    {clientMgrModal.clients.map((c) => (
                      <tr key={c.id} className="border-b border-border/50 hover:bg-muted/20">
                        <td className="py-2.5 font-mono text-xs text-gold">{c.client_code}</td>
                        <td className="py-2.5 text-xs font-medium">{c.company_name}</td>
                        <td className="py-2.5">
                          <span className={`text-[10px] px-1.5 py-0.5 rounded border ${c.status === 'Active' ? 'border-green-200 text-green-600 bg-green-50' : 'border-border text-muted-foreground'}`}>
                            {c.status ?? "—"}
                          </span>
                        </td>
                        <td className="py-2.5 text-xs text-muted-foreground">{c.gst_type ?? "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
            <div className="p-4 border-t border-border flex items-center justify-between">
              <span className="text-xs text-muted-foreground">{clientMgrModal.clients.length} client{clientMgrModal.clients.length !== 1 ? "s" : ""}</span>
              <Button variant="outline" onClick={() => setClientMgrModal(null)}>Close</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {notice && (
          <div className={`rounded-md border px-4 py-3 text-sm ${notice.kind === "ok" ? "border-green-200 bg-green-50 text-green-700" : "border-red-200 bg-red-50 text-red-700"}`}>
            {notice.text}
          </div>
        )}

        {/* Stat cards */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gold/10">
                  <Users className="h-5 w-5 text-gold" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{totalMembers}</div>
                  <div className="text-xs text-muted-foreground">Total Members</div>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-500/10">
                  <Users className="h-5 w-5 text-green-500" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{activeCount}</div>
                  <div className="text-xs text-muted-foreground">Active</div>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                  <Briefcase className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{departments}</div>
                  <div className="text-xs text-muted-foreground">Departments</div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Toolbar */}
        <div className="flex items-center gap-3">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <input
              type="text"
              placeholder="Search by name or role..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full rounded-md border border-border bg-background pl-9 pr-4 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-gold"
            />
          </div>
          <div className="flex items-center rounded-md border border-border overflow-hidden">
            <button
              onClick={() => setViewMode("grid")}
              className={`flex items-center gap-1.5 px-3 py-2 text-sm transition-colors ${
                viewMode === "grid"
                  ? "bg-gold text-background"
                  : "bg-background text-muted-foreground hover:bg-muted/50"
              }`}
            >
              <Grid className="h-4 w-4" />
              Grid
            </button>
            <button
              onClick={() => setViewMode("list")}
              className={`flex items-center gap-1.5 px-3 py-2 text-sm transition-colors ${
                viewMode === "list"
                  ? "bg-gold text-background"
                  : "bg-background text-muted-foreground hover:bg-muted/50"
              }`}
            >
              <List className="h-4 w-4" />
              List
            </button>
          </div>
        </div>

        {/* Grid View */}
        {viewMode === "grid" && (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {filtered.map((emp) => {
              const name = emp.user?.name ?? "Unknown";
              const email = emp.user?.email ?? "";
              const role = emp.designation?.title ?? "—";
              const dept = emp.department?.name ?? "—";
              const status = emp.employment_status ?? "Active";
              const { total, pct } = getWorkloadForEmp(emp);

              return (
                <Card key={emp.id} className="border-border hover:shadow-md transition-shadow">
                  <CardContent className="pt-5 pb-4 flex flex-col items-center text-center gap-3">
                    <div className="flex h-14 w-14 items-center justify-center rounded-full bg-gold text-background font-display font-semibold text-lg">
                      {getInitials(name)}
                    </div>
                    <div>
                      <div className="font-medium text-foreground">{name}</div>
                      <div className="text-xs text-muted-foreground mt-0.5">{role}</div>
                    </div>
                    <Badge variant="secondary" className="text-[10px]">
                      {dept}
                    </Badge>
                    <div className="flex items-center gap-1 text-xs text-muted-foreground">
                      <Mail className="h-3 w-3 flex-shrink-0" />
                      <span className="truncate max-w-[160px]">{email}</span>
                    </div>
                    <div className="w-full">
                      <div className="flex justify-between text-[10px] text-muted-foreground mb-1">
                        <span>Workload</span>
                        <span className="text-gold">{total} cases ({pct}%)</span>
                      </div>
                      <div className="w-full h-1.5 bg-muted rounded-full">
                        <div className="h-full bg-gold rounded-full" style={{ width: `${pct}%` }} />
                      </div>
                    </div>
                    <Badge
                      variant={status.toLowerCase() === "active" ? "default" : "outline"}
                      className="text-[10px]"
                    >
                      {status}
                    </Badge>
                    <div className="w-full flex gap-2 mt-1">
                      <Button
                        size="sm"
                        variant="outline"
                        className="flex-1 text-xs"
                        onClick={() => openAssignedProjects(emp)}
                      >
                        <FolderOpen className="h-3.5 w-3.5 mr-1" />
                        Projects
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="flex-1 text-xs"
                        onClick={() => openClientManagerModal(emp)}
                      >
                        <Building2 className="h-3.5 w-3.5 mr-1" />
                        Clients
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
            {filtered.length === 0 && (
              <div className="col-span-full py-12 text-center text-muted-foreground text-sm">
                No team members match your search.
              </div>
            )}
          </div>
        )}

        {/* List View */}
        {viewMode === "list" && (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Name</th>
                    <th className="px-4 py-3 text-left">Role</th>
                    <th className="px-4 py-3 text-left">Department</th>
                    <th className="px-4 py-3 text-left">Email</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Workload</th>
                    <th className="px-4 py-3 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((emp) => {
                    const name = emp.user?.name ?? "Unknown";
                    const email = emp.user?.email ?? "";
                    const role = emp.designation?.title ?? "—";
                    const dept = emp.department?.name ?? "—";
                    const status = emp.employment_status ?? "Active";
                    const { total, pct } = getWorkloadForEmp(emp);

                    return (
                      <tr key={emp.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gold text-background text-xs font-semibold flex-shrink-0">
                              {getInitials(name)}
                            </div>
                            <span className="font-medium">{name}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{role}</td>
                        <td className="px-4 py-3">
                          <Badge variant="secondary" className="text-[10px]">
                            {dept}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground text-xs">{email}</td>
                        <td className="px-4 py-3">
                          <Badge
                            variant={status.toLowerCase() === "active" ? "default" : "outline"}
                            className="text-[10px]"
                          >
                            {status}
                          </Badge>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <div className="w-24 h-1.5 bg-muted rounded-full">
                              <div
                                className="h-full bg-gold rounded-full"
                                style={{ width: `${pct}%` }}
                              />
                            </div>
                            <span className="text-xs text-muted-foreground">{total} ({pct}%)</span>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <Button size="sm" variant="outline" className="text-xs h-7" onClick={() => openAssignedProjects(emp)}>
                              <FolderOpen className="h-3 w-3 mr-1" />Projects
                            </Button>
                            <Button size="sm" variant="outline" className="text-xs h-7" onClick={() => openClientManagerModal(emp)}>
                              <Building2 className="h-3 w-3 mr-1" />Clients
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {filtered.length === 0 && (
                <div className="py-12 text-center text-muted-foreground text-sm">
                  No team members match your search.
                </div>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
