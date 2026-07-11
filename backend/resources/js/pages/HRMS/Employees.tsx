import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Plus, X, Edit2, Trash2, Search, Download } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";

const DEPTS = ["Legal", "Operations", "HR", "Finance", "Engineering", "Business Development", "Administration"];
const ROLES = ["Director", "HR", "Patent Attorney", "Patent Analyst", "Patent Engineer", "Finance Manager", "Paralegal", "Consultant", "System Admin"];
const LOCATIONS = ["Remote", "Coimbatore", "Chennai", "Bengaluru", "Hyderabad", "Pollachi"];

const blankForm = { employee_code: "", full_name: "", work_email: "", department_name: "", designation_title: "", work_location: "Coimbatore", date_of_joining: "", employment_type: "Full-time", salary: "" };
const HR_CRUD_ROLES = ["super_admin", "partner", "hr"];

export default function HRMSEmployees() {
  const { props } = usePage() as any;
  const userRole: string = props.auth?.user?.role ?? "";
  const canCRUD = HR_CRUD_ROLES.includes(userRole);

  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  const [showModal, setShowModal] = useState(false);
  const [editEmp, setEditEmp] = useState<any | null>(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  useEffect(() => {
    api.getEmployees().then(setEmployees).catch(() => {}).finally(() => setLoading(false));
  }, []);

  const filtered = employees.filter(e => {
    const q = search.toLowerCase();
    return !search ||
      (e.user?.name || e.full_name || "").toLowerCase().includes(q) ||
      (e.designation?.title || "").toLowerCase().includes(q) ||
      (e.department?.name || "").toLowerCase().includes(q);
  });

  function openNew() {
    setEditEmp(null);
    setForm(blankForm);
    setSaveError("");
    setShowModal(true);
  }

  function openEdit(e: any) {
    setEditEmp(e);
    setForm({
      employee_code: e.employee_code || "",
      full_name: e.user?.name || e.full_name || "",
      work_email: e.user?.email || e.work_email || "",
      department_name: e.department?.name || "",
      designation_title: e.designation?.title || "",
      work_location: e.work_location || "Coimbatore",
      date_of_joining: e.date_of_joining || "",
      employment_type: e.employment_type || "Full-time",
      salary: e.salary?.toString() || "",
    });
    setSaveError("");
    setShowModal(true);
  }

  async function handleSave() {
    if (!form.full_name.trim() || !form.work_email.trim()) { setSaveError("Name and email are required."); return; }
    setSaving(true); setSaveError("");
    try {
      const payload = { ...form, salary: form.salary ? parseFloat(form.salary) : null };
      if (editEmp) {
        const updated = await api.updateEmployee(editEmp.id, payload as any);
        setEmployees(prev => prev.map(e => e.id === editEmp.id ? { ...e, ...updated } : e));
      } else {
        const created = await api.createEmployee(payload as any);
        setEmployees(prev => [created, ...prev]);
      }
      setShowModal(false);
    } catch (e: any) { setSaveError(e.message || "Failed to save employee."); }
    finally { setSaving(false); }
  }

  async function handleDelete(id: number) {
    if (!confirm("This will set the employee as Terminated. Continue?")) return;
    try {
      await api.deleteEmployee(id);
      setEmployees(prev => prev.map(e => e.id === id ? { ...e, employment_status: "Terminated" } : e));
    } catch (e: any) { alert(e.message || "Failed."); }
  }

  function handleExport() {
    const rows = filtered.map(e => ({
      Code: e.employee_code, Name: e.user?.name || e.full_name, Email: e.user?.email,
      Department: e.department?.name, Role: e.designation?.title,
      Location: e.work_location, Status: e.employment_status,
    }));
    downloadCSV(`employees-${new Date().toISOString().slice(0,10)}.csv`, rows);
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  return (
    <AppLayout>
      <Head title="Employees" />
      <PageHeader
        eyebrow="HRMS"
        title="Employees"
        description={`${employees.length} team members`}
        actions={
          <>
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            {canCRUD && <Button onClick={openNew}><Plus className="h-4 w-4 mr-2" />Add Employee</Button>}
          </>
        }
      />

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">{editEmp ? "Edit Employee" : "Add Employee"}</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <div className="space-y-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">
                  Employee Code
                  {!editEmp && <span className="ml-1 text-muted-foreground/60">(leave blank to auto-generate)</span>}
                </label>
                <input
                  value={form.employee_code}
                  onChange={e => setForm(p => ({ ...p, employee_code: e.target.value }))}
                  placeholder="e.g. EMP-2026-0042"
                  className={inputCls}
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Full Name *</label>
                  <input value={form.full_name} onChange={e => setForm(p => ({ ...p, full_name: e.target.value }))} placeholder="e.g. Priya Sharma" className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Work Email *</label>
                  <input type="email" value={form.work_email} onChange={e => setForm(p => ({ ...p, work_email: e.target.value }))} placeholder="priya@firm.com" className={inputCls} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Department</label>
                  <select value={form.department_name} onChange={e => setForm(p => ({ ...p, department_name: e.target.value }))} className={inputCls}>
                    <option value="">Select dept</option>
                    {DEPTS.map(d => <option key={d}>{d}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Designation</label>
                  <select value={form.designation_title} onChange={e => setForm(p => ({ ...p, designation_title: e.target.value }))} className={inputCls}>
                    <option value="">Select role</option>
                    {ROLES.map(r => <option key={r}>{r}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Location</label>
                  <select value={form.work_location} onChange={e => setForm(p => ({ ...p, work_location: e.target.value }))} className={inputCls}>
                    {LOCATIONS.map(l => <option key={l}>{l}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Date of Joining</label>
                  <input type="date" value={form.date_of_joining} onChange={e => setForm(p => ({ ...p, date_of_joining: e.target.value }))} className={inputCls} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Employment Type</label>
                  <select value={form.employment_type} onChange={e => setForm(p => ({ ...p, employment_type: e.target.value }))} className={inputCls}>
                    {["Full-time", "Part-time", "Contract", "Internship"].map(t => <option key={t}>{t}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Monthly Salary (₹)</label>
                  <input type="number" value={form.salary} onChange={e => setForm(p => ({ ...p, salary: e.target.value }))} placeholder="e.g. 150000" className={inputCls} />
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}{editEmp ? "Save Changes" : "Add Employee"}
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-4">
        <div className="relative max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search employees…"
            className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-left">Code</th>
                    <th className="px-4 py-3 text-left">Role</th>
                    <th className="px-4 py-3 text-left">Department</th>
                    <th className="px-4 py-3 text-left">Location</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    {canCRUD && <th className="px-4 py-3 text-left">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((e) => (
                    <tr key={e.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="font-medium">{e.user?.name || e.full_name || "—"}</div>
                        <div className="text-xs text-muted-foreground">{e.user?.email || e.work_email}</div>
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{e.employee_code}</td>
                      <td className="px-4 py-3 text-muted-foreground">{e.designation?.title || "—"}</td>
                      <td className="px-4 py-3">{e.department?.name || "—"}</td>
                      <td className="px-4 py-3 text-muted-foreground">{e.work_location || "—"}</td>
                      <td className="px-4 py-3">
                        <Badge variant={statusColor(e.employment_status || "Active")}>{e.employment_status || "Active"}</Badge>
                      </td>
                      {canCRUD && (
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1">
                            <button onClick={() => openEdit(e)} className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground"><Edit2 className="h-3.5 w-3.5" /></button>
                            <button onClick={() => handleDelete(e.id)} className="p-1.5 rounded hover:bg-destructive/10 text-muted-foreground hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan={canCRUD ? 7 : 6} className="px-4 py-12 text-center text-muted-foreground">
                      {employees.length === 0 ? "No employees yet. Add your first team member." : "No employees match your search."}
                    </td></tr>
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
