import { Head, Link } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Users, Grid, List, Search, Mail, Briefcase, Loader2, Plus, X } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const DEPTS = ["Legal", "Operations", "HR", "Finance", "Engineering", "Business Development", "Administration"];
const ROLES = ["Partner", "Senior Attorney", "Associate Attorney", "Paralegal", "Patent Analyst", "Technical Writer", "HR Executive", "Finance Manager", "Business Developer"];
const LOCATIONS = ["Chennai", "Coimbatore", "Hyderabad", "Bangalore", "Remote"];
const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

function getInitials(name: string) {
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);
}

// Stable pseudo-random workload per employee id (40–90%)
function workloadPct(id: number) {
  return 40 + (id * 17) % 51;
}

export default function Team() {
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [viewMode, setViewMode] = useState<"grid" | "list">("grid");
  const [search, setSearch] = useState("");

  const [showAddModal, setShowAddModal] = useState(false);
  const [showInviteModal, setShowInviteModal] = useState(false);
  const [addForm, setAddForm] = useState({ full_name: "", work_email: "", department_name: "", designation_title: "", work_location: "Chennai", date_of_joining: "" });
  const [inviteEmail, setInviteEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  useEffect(() => {
    api.getEmployees().then(setEmployees).catch(() => {}).finally(() => setLoading(false));
  }, []);

  async function handleAddEmployee() {
    if (!addForm.full_name.trim() || !addForm.work_email.trim()) { setSaveError("Name and email are required."); return; }
    setSaving(true); setSaveError("");
    try {
      const created = await api.createEmployee(addForm);
      setEmployees(prev => [created, ...prev]);
      setShowAddModal(false);
      setAddForm({ full_name: "", work_email: "", department_name: "", designation_title: "", work_location: "Mumbai", date_of_joining: "" });
    } catch (e: any) { setSaveError(e.message || "Failed to add employee."); }
    finally { setSaving(false); }
  }

  function handleInvite() {
    if (!inviteEmail.trim()) return;
    alert(`Invitation sent to ${inviteEmail}`);
    setInviteEmail("");
    setShowInviteModal(false);
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
            <p className="text-sm text-muted-foreground mb-4">Enter the email address of the person you'd like to invite to the platform.</p>
            <input type="email" value={inviteEmail} onChange={e => setInviteEmail(e.target.value)} placeholder="colleague@firm.com" className={inputCls} />
            <div className="flex gap-2 mt-4">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleInvite}><Mail className="h-4 w-4 mr-2" />Send Invite</Button>
              <Button variant="outline" onClick={() => setShowInviteModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
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
              const pct = workloadPct(emp.id);

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
                        <span className="text-gold">{pct}%</span>
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
                    <Button asChild size="sm" variant="outline" className="w-full mt-1 text-xs">
                      <Link href="/projects">View Projects</Link>
                    </Button>
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
                    const pct = workloadPct(emp.id);

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
                            <span className="text-xs text-muted-foreground">{pct}%</span>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <Button asChild size="sm" variant="outline" className="text-xs h-7">
                            <Link href="/projects">View Projects</Link>
                          </Button>
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
