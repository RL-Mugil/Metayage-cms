import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Plus, X, Landmark, ChevronDown, ChevronRight } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function statusBadgeClass(status: string) {
  if (status === "Active") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Closed") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Rejected") return "bg-red-100 text-red-700 border-red-200";
  return "bg-blue-100 text-blue-700 border-blue-200"; // Requested
}

const BLANK_FORM = { employee_id: "", principal_amount: "", monthly_installment: "", start_period: new Date().toISOString().slice(0, 7), notes: "" };

export default function PayrollPortalLoans() {
  const [loans, setLoans] = useState<any[]>([]);
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ ...BLANK_FORM });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const [approvingId, setApprovingId] = useState<number | null>(null);
  const [approveStartPeriod, setApproveStartPeriod] = useState(new Date().toISOString().slice(0, 7));
  const [approveBusy, setApproveBusy] = useState(false);

  function load() {
    setLoading(true);
    api.getLoans().then(setLoans).catch(() => setLoans([])).finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    api.getEmployees().then(setEmployees).catch(() => setEmployees([]));
  }, []);

  async function handleSave() {
    if (!form.employee_id || !form.principal_amount || !form.monthly_installment) {
      setError("Employee, principal amount, and monthly installment are required.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      await api.createLoan({
        employee_id: Number(form.employee_id),
        principal_amount: parseFloat(form.principal_amount),
        monthly_installment: parseFloat(form.monthly_installment),
        start_period: form.start_period,
        notes: form.notes || undefined,
      });
      setShowModal(false);
      setForm({ ...BLANK_FORM });
      load();
    } catch (e: any) {
      setError(e.message || "Failed to create loan.");
    } finally {
      setSaving(false);
    }
  }

  async function handleClose(id: number) {
    if (!confirm("Close this loan? Any remaining balance will be waived and no further installments deducted.")) return;
    await api.closeLoan(id);
    load();
  }

  async function handleApprove(id: number) {
    setApproveBusy(true);
    try {
      await api.approveLoanRequest(id, { start_period: approveStartPeriod });
      setApprovingId(null);
      load();
    } catch (e: any) {
      alert(e.message || "Failed to approve loan request.");
    } finally {
      setApproveBusy(false);
    }
  }

  async function handleReject(id: number) {
    if (!confirm("Decline this loan request?")) return;
    await api.rejectLoanRequest(id);
    load();
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Loans" />
      <PageHeader eyebrow="Payroll Portal" title="Loans" description="Employee loans, repaid as a monthly salary deduction"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowModal(true)}><Plus className="h-4 w-4 mr-2" />Give Loan</Button>}
      />

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Give Loan</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {error && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{error}</div>}
            <div>
              <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                Loan Details
              </h3>
              <div className="grid grid-cols-4 gap-2">
                <div className="col-span-2">
                  <div className="text-[10px] text-muted-foreground mb-0.5">Employee *</div>
                  <select value={form.employee_id} onChange={(e) => setForm((p) => ({ ...p, employee_id: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select employee…</option>
                    {employees.map((e) => <option key={e.id} value={e.id}>{e.user?.name || e.full_name} ({e.employee_code})</option>)}
                  </select>
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">Principal Amount (₹) *</div>
                  <input type="number" value={form.principal_amount} onChange={(e) => setForm((p) => ({ ...p, principal_amount: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">Monthly Installment (₹) *</div>
                  <input type="number" value={form.monthly_installment} onChange={(e) => setForm((p) => ({ ...p, monthly_installment: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5">Start Period</div>
                  <input type="month" value={form.start_period} onChange={(e) => setForm((p) => ({ ...p, start_period: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div className="col-span-3">
                  <div className="text-[10px] text-muted-foreground mb-0.5">Notes (optional)</div>
                  <textarea value={form.notes} rows={1} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
                    className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Give Loan
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {approvingId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Approve Loan Request</h2>
              <button onClick={() => setApprovingId(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <label className="block text-xs text-muted-foreground mb-1">Start Period (first deduction month)</label>
            <input type="month" value={approveStartPeriod} onChange={(e) => setApproveStartPeriod(e.target.value)}
              className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={approveBusy} onClick={() => handleApprove(approvingId)}>
                {approveBusy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Approve
              </Button>
              <Button variant="outline" onClick={() => setApprovingId(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6">
        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : loans.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 gap-3 text-muted-foreground">
            <Landmark className="h-10 w-10 opacity-20" />
            <p className="text-sm">No loans given yet.</p>
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Employee</th>
                    <th className="px-3 py-2.5 text-right">Principal</th>
                    <th className="px-3 py-2.5 text-right">Monthly Installment</th>
                    <th className="px-3 py-2.5 text-right">Remaining Balance</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    <th className="px-3 py-2.5 text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {loans.map((loan) => (
                    <>
                      <tr key={loan.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5">
                          <button className="flex items-center gap-1 text-left hover:text-gold transition-colors" onClick={() => setExpanded((cur) => (cur === loan.id ? null : loan.id))}>
                            {expanded === loan.id ? <ChevronDown className="h-3 w-3 shrink-0 text-muted-foreground" /> : <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground" />}
                            <span>
                              <div className="font-medium">{loan.employee?.full_name}</div>
                              <div className="text-muted-foreground text-[10px]">{loan.employee?.employee_code}</div>
                            </span>
                          </button>
                        </td>
                        <td className="px-3 py-2.5 text-right">{fmt(loan.principal_amount)}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(loan.monthly_installment)}</td>
                        <td className="px-3 py-2.5 text-right font-medium">{fmt(loan.remaining_balance)}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${statusBadgeClass(loan.status)}`}>{loan.status}</Badge>
                        </td>
                        <td className="px-3 py-2.5 text-center whitespace-nowrap">
                          {loan.status === "Requested" && (
                            <>
                              <Button size="sm" className="h-6 px-2 text-[10px] bg-green-600 hover:bg-green-700 text-white mr-1"
                                onClick={() => { setApprovingId(loan.id); setApproveStartPeriod(new Date().toISOString().slice(0, 7)); }}>Approve</Button>
                              <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] border-red-200 text-red-600" onClick={() => handleReject(loan.id)}>Decline</Button>
                            </>
                          )}
                          {loan.status === "Active" && (
                            <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] border-red-200 text-red-600" onClick={() => handleClose(loan.id)}>Close</Button>
                          )}
                        </td>
                      </tr>
                      {expanded === loan.id && (
                        <tr className="border-t border-border bg-muted/10">
                          <td colSpan={6} className="px-3 py-2.5">
                            {loan.repayments?.length ? (
                              <div className="flex flex-wrap gap-x-5 gap-y-1 pl-5 text-[11px] text-muted-foreground">
                                {loan.repayments.map((r: any) => (
                                  <span key={r.id}><span className="text-foreground font-medium">{new Date(r.period).toLocaleString("default", { month: "short", year: "numeric" })}:</span> {fmt(r.installment_amount)} ({r.status})</span>
                                ))}
                              </div>
                            ) : (
                              <p className="pl-5 text-[11px] text-muted-foreground">No repayments deducted yet.</p>
                            )}
                          </td>
                        </tr>
                      )}
                    </>
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
