import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Check, X, Plus, TrendingUp } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

const BLANK_FORM = { employee_id: "", new_base_pay: "", new_hra: "", effective_period: new Date().toISOString().slice(0, 7), reason: "" };

function statusBadge(status: string) {
  if (status === "Approved") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Rejected") return "bg-red-100 text-red-700 border-red-200";
  return "bg-amber-100 text-amber-700 border-amber-200";
}

export default function PayrollPortalApprovalsSalaryRevisions() {
  const [revisions, setRevisions] = useState<any[]>([]);
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState("");

  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ ...BLANK_FORM });
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState("");

  function load() {
    setLoading(true);
    api.getSalaryRevisions().then(setRevisions).catch((e) => setError(e.message || "Failed to load.")).finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    api.getEmployees().then(setEmployees).catch(() => setEmployees([]));
  }, []);

  async function resolve(id: number, action: "Approved" | "Rejected") {
    setBusyId(id);
    setError("");
    try {
      await api.resolveApproval("SalaryRevision", id, action);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to resolve revision.");
    } finally {
      setBusyId(null);
    }
  }

  async function handlePropose() {
    if (!form.employee_id || !form.new_base_pay) { setFormError("Employee and new Base Pay are required."); return; }
    setSaving(true);
    setFormError("");
    try {
      await api.proposeSalaryRevision({
        employee_id: Number(form.employee_id),
        new_base_pay: parseFloat(form.new_base_pay),
        new_hra: form.new_hra ? parseFloat(form.new_hra) : undefined,
        effective_period: form.effective_period,
        reason: form.reason || undefined,
      });
      setShowModal(false);
      setForm({ ...BLANK_FORM });
      load();
    } catch (e: any) {
      setFormError(e.message || "Failed to propose revision.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Salary Revisions" />
      <PageHeader eyebrow="Payroll Portal · Approvals" title="Salary Revisions" description="Proposed pay raises pending approval"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowModal(true)}><Plus className="h-4 w-4 mr-2" />Propose Revision</Button>}
      />

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Propose Salary Revision</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {formError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{formError}</div>}
            <div>
              <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                Revision Details
              </h3>
              <div className="grid grid-cols-4 gap-2">
                <div className="col-span-2">
                  <div className="text-[10px] text-muted-foreground mb-0.5">Employee *</div>
                  <select value={form.employee_id} onChange={(e) => setForm((p) => ({ ...p, employee_id: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select employee…</option>
                    {employees.map((e) => <option key={e.id} value={e.id}>{e.user?.name || e.full_name} ({e.employee_code}) — current Base Pay {fmt(e.base_pay_monthly || 0)}</option>)}
                  </select>
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">New Base Pay (₹) *</div>
                  <input type="number" value={form.new_base_pay} onChange={(e) => setForm((p) => ({ ...p, new_base_pay: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">New HRA (₹, optional)</div>
                  <input type="number" value={form.new_hra} onChange={(e) => setForm((p) => ({ ...p, new_hra: e.target.value }))}
                    placeholder="Leave blank to keep current"
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">Effective From</div>
                  <input type="month" value={form.effective_period} onChange={(e) => setForm((p) => ({ ...p, effective_period: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div className="col-span-4">
                  <div className="text-[10px] text-muted-foreground mb-0.5">Reason (optional)</div>
                  <textarea value={form.reason} rows={1} onChange={(e) => setForm((p) => ({ ...p, reason: e.target.value }))}
                    className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handlePropose} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Propose Revision
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400 mb-4">{error}</div>}

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : revisions.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 gap-3 text-muted-foreground">
            <TrendingUp className="h-10 w-10 opacity-20" />
            <p className="text-sm">No salary revisions yet.</p>
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Employee</th>
                    <th className="px-3 py-2.5 text-right">Previous Base Pay</th>
                    <th className="px-3 py-2.5 text-right">New Base Pay</th>
                    <th className="px-3 py-2.5 text-right">New HRA</th>
                    <th className="px-3 py-2.5 text-left">Effective</th>
                    <th className="px-3 py-2.5 text-left">Requested By</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    <th className="px-3 py-2.5 text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {revisions.map((r) => (
                    <tr key={r.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-3 py-2.5">
                        <div className="font-medium">{r.employee?.full_name}</div>
                        <div className="text-muted-foreground text-[10px]">{r.employee?.employee_code}</div>
                      </td>
                      <td className="px-3 py-2.5 text-right">{fmt(r.previous_base_pay)}</td>
                      <td className="px-3 py-2.5 text-right font-medium text-green-600">{fmt(r.new_base_pay)}</td>
                      <td className="px-3 py-2.5 text-right">{r.new_hra !== null && r.new_hra !== undefined ? fmt(r.new_hra) : "—"}</td>
                      <td className="px-3 py-2.5">{new Date(r.effective_period).toLocaleString("default", { month: "short", year: "numeric" })}</td>
                      <td className="px-3 py-2.5 text-muted-foreground">{r.requested_by?.name || "—"}</td>
                      <td className="px-3 py-2.5"><Badge variant="outline" className={`text-[10px] ${statusBadge(r.status)}`}>{r.status}</Badge></td>
                      <td className="px-3 py-2.5 text-center whitespace-nowrap">
                        {r.status === "Pending" && (
                          <>
                            <Button size="sm" className="h-6 px-2 text-[10px] bg-green-600 hover:bg-green-700 text-white mr-1" disabled={busyId === r.id} onClick={() => resolve(r.id, "Approved")}>
                              <Check className="h-3 w-3" />
                            </Button>
                            <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] border-red-200 text-red-600" disabled={busyId === r.id} onClick={() => resolve(r.id, "Rejected")}>
                              <X className="h-3 w-3" />
                            </Button>
                          </>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
