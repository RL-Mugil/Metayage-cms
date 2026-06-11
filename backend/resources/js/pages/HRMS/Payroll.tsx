import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
  DollarSign, Download, FileText, Users, TrendingUp, Calculator,
  CheckCircle, X, Loader2, Lock, Trash2, BadgeCheck,
} from "lucide-react";
import { api, downloadCSV } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function periodLabel(period: string): string {
  return new Date(period).toLocaleString("default", { month: "long", year: "numeric" });
}

function statusBadge(status: string) {
  if (status === "Paid") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Finalized") return "bg-blue-100 text-blue-700 border-blue-200";
  return "bg-amber-100 text-amber-700 border-amber-200";
}

export default function HRMSPayroll() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role || "";
  const isPayrollViewer = ["super_admin", "hr", "finance", "partner"].includes(role);

  const [runs, setRuns] = useState<any[]>([]);
  const [ytdPaid, setYtdPaid] = useState(0);
  const [canManage, setCanManage] = useState(false);
  const [canPay, setCanPay] = useState(false);
  const [selectedRun, setSelectedRun] = useState<any | null>(null);
  const [slips, setSlips] = useState<any[]>([]);
  const [mySlips, setMySlips] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [slipsLoading, setSlipsLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const [showProcess, setShowProcess] = useState(false);
  const [processMonth, setProcessMonth] = useState(new Date().toISOString().slice(0, 7));
  const [editingSlip, setEditingSlip] = useState<number | null>(null);
  const [editVals, setEditVals] = useState({ lop_days: "0", tds: "0" });

  function flash(setter: (s: string) => void, msg: string) {
    setter(msg);
    setTimeout(() => setter(""), 4000);
  }

  function loadRuns(selectId?: number) {
    api.getPayrollRuns()
      .then((data) => {
        setRuns(data.runs);
        setYtdPaid(data.ytd_paid);
        setCanManage(data.can_manage);
        setCanPay(data.can_pay);
        const target = selectId
          ? data.runs.find((r: any) => r.id === selectId)
          : data.runs[0];
        if (target) selectRun(target);
        else { setSelectedRun(null); setSlips([]); }
      })
      .catch((e) => flash(setError, e.message || "Failed to load payroll."))
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    if (isPayrollViewer) {
      loadRuns();
    } else {
      setLoading(false);
    }
    api.getMyPayslips().then(setMySlips).catch(() => {});
  }, []);

  function selectRun(run: any) {
    setSelectedRun(run);
    setSlipsLoading(true);
    api.getPayrollRun(run.id)
      .then((data) => setSlips(data.payslips || []))
      .catch((e) => flash(setError, e.message || "Failed to load payslips."))
      .finally(() => setSlipsLoading(false));
  }

  async function handleProcess() {
    setBusy(true);
    setError("");
    try {
      const result = await api.createPayrollRun(processMonth);
      setShowProcess(false);
      const skipped = result.skipped_employees || [];
      if (skipped.length) {
        flash(setNotice, `Run created. Skipped (no salary set): ${skipped.join(", ")}`);
      } else {
        flash(setNotice, "Draft payroll run created.");
      }
      loadRuns(result.run?.id);
    } catch (e: any) {
      flash(setError, e.message || "Failed to process payroll.");
    } finally {
      setBusy(false);
    }
  }

  async function handleLifecycle(action: "finalize" | "pay" | "delete") {
    if (!selectedRun) return;
    const labels = { finalize: "Finalize this run? Payslips lock after finalization.", pay: "Mark this run as paid?", delete: "Delete this draft run?" };
    if (!confirm(labels[action])) return;
    setBusy(true);
    setError("");
    try {
      if (action === "finalize") await api.finalizePayrollRun(selectedRun.id);
      if (action === "pay") await api.payPayrollRun(selectedRun.id);
      if (action === "delete") await api.deletePayrollRun(selectedRun.id);
      flash(setNotice, action === "delete" ? "Draft run deleted." : `Run ${action === "pay" ? "marked paid" : "finalized"}.`);
      loadRuns(action === "delete" ? undefined : selectedRun.id);
    } catch (e: any) {
      flash(setError, e.message || "Action failed.");
    } finally {
      setBusy(false);
    }
  }

  function startEdit(slip: any) {
    setEditingSlip(slip.id);
    setEditVals({ lop_days: String(slip.lop_days ?? 0), tds: String(slip.tds ?? 0) });
  }

  async function saveEdit(slipId: number) {
    setBusy(true);
    setError("");
    try {
      const updated = await api.updatePayslip(slipId, {
        lop_days: parseFloat(editVals.lop_days) || 0,
        tds: parseFloat(editVals.tds) || 0,
      });
      setSlips((prev) => prev.map((s) => (s.id === slipId ? updated : s)));
      setEditingSlip(null);
      loadRuns(selectedRun?.id);
    } catch (e: any) {
      flash(setError, e.message || "Failed to update payslip.");
    } finally {
      setBusy(false);
    }
  }

  function exportRun() {
    if (!selectedRun || !slips.length) return;
    downloadCSV(`payroll-${selectedRun.period.slice(0, 7)}.csv`, slips.map((s) => ({
      Employee: s.employee_name, Code: s.employee_code, Designation: s.designation,
      Gross: s.gross_salary, "LOP Days": s.lop_days, "LOP Deduction": s.lop_deduction,
      Basic: s.basic, HRA: s.hra, "Special Allowance": s.special_allowance,
      PF: s.pf_employee, ESI: s.esi_employee, "Prof. Tax": s.professional_tax, TDS: s.tds,
      "Total Deductions": s.total_deductions, "Net Pay": s.net_pay,
      Month: periodLabel(selectedRun.period), Status: selectedRun.status,
    })));
  }

  function exportSlip(s: any, period: string, status: string) {
    downloadCSV(`payslip-${s.employee_name.replace(/ /g, "-")}-${period.slice(0, 7)}.csv`, [{
      Employee: s.employee_name, Code: s.employee_code, Designation: s.designation,
      Month: periodLabel(period), Gross: s.gross_salary,
      "LOP Days": s.lop_days, "LOP Deduction": s.lop_deduction,
      Basic: s.basic, HRA: s.hra, "Special Allowance": s.special_allowance,
      PF: s.pf_employee, ESI: s.esi_employee, "Prof. Tax": s.professional_tax, TDS: s.tds,
      "Total Deductions": s.total_deductions, "Net Pay": s.net_pay, Status: status,
    }]);
  }

  const isDraft = selectedRun?.status === "Draft";
  const totals = slips.reduce(
    (acc, s) => ({
      gross: acc.gross + Number(s.gross_salary) - Number(s.lop_deduction),
      ded: acc.ded + Number(s.total_deductions),
      net: acc.net + Number(s.net_pay),
      tds: acc.tds + Number(s.tds),
      pf: acc.pf + Number(s.pf_employee),
    }),
    { gross: 0, ded: 0, net: 0, tds: 0, pf: 0 }
  );

  return (
    <AppLayout>
      <Head title="Payroll" />
      <PageHeader eyebrow="HRMS" title="Payroll"
        description="Salary processing, pay slips, and statutory deductions"
        actions={canManage ? (
          <Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowProcess(true)}>
            <Calculator className="h-4 w-4 mr-2" />Process Payroll
          </Button>
        ) : undefined}
      />

      {showProcess && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Process Payroll</h2>
              <button onClick={() => setShowProcess(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-4">
              Creates a draft run for every active employee with a salary. You can adjust LOP days and TDS per payslip before finalizing.
            </p>
            <label className="block text-xs text-muted-foreground mb-1">Payroll Month</label>
            <input type="month" value={processMonth} onChange={(e) => setProcessMonth(e.target.value)}
              className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={busy} onClick={handleProcess}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Create Draft Run
              </Button>
              <Button variant="outline" onClick={() => setShowProcess(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}
        {notice && <div className="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-400">{notice}</div>}

        {isPayrollViewer && (
          <>
            {/* Summary cards */}
            <div className="grid grid-cols-4 gap-4">
              {[
                { label: "YTD Paid", value: fmt(ytdPaid), icon: DollarSign, sub: `Calendar ${new Date().getFullYear()}` },
                { label: "Selected Run Net", value: selectedRun ? fmt(selectedRun.net_total) : "—", icon: TrendingUp, sub: selectedRun ? periodLabel(selectedRun.period) : "No run selected" },
                { label: "TDS (selected run)", value: fmt(totals.tds), icon: FileText, sub: "Employee total" },
                { label: "PF (selected run)", value: fmt(totals.pf), icon: Users, sub: "Employee contribution" },
              ].map(({ label, value, icon: Icon, sub }) => (
                <Card key={label} className="border-border">
                  <CardContent className="p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <Icon className="h-4 w-4 text-gold" />
                      <span className="text-xs text-muted-foreground">{label}</span>
                    </div>
                    <div className="text-xl font-bold">{value}</div>
                    <div className="text-xs text-muted-foreground mt-0.5">{sub}</div>
                  </CardContent>
                </Card>
              ))}
            </div>

            {loading ? (
              <div className="flex items-center justify-center py-16"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
            ) : runs.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
                <DollarSign className="h-10 w-10 opacity-20" />
                <p className="text-sm">
                  {canManage ? 'No payroll runs yet. Use "Process Payroll" to create the first draft run.' : "No payroll runs yet."}
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-6">
                {/* Runs list */}
                <div className="col-span-1">
                  <h3 className="text-sm font-semibold mb-3">Payroll Runs</h3>
                  <div className="space-y-1">
                    {runs.map((run) => (
                      <button key={run.id} onClick={() => selectRun(run)}
                        className={`w-full text-left p-3 rounded-lg border transition-all ${selectedRun?.id === run.id ? "border-gold bg-gold/5" : "border-border hover:border-gold/30"}`}>
                        <div className="flex items-center justify-between">
                          <span className="text-sm font-medium">{periodLabel(run.period)}</span>
                          <Badge variant="outline" className={`text-[10px] ${statusBadge(run.status)}`}>{run.status}</Badge>
                        </div>
                        <div className="text-xs text-muted-foreground mt-0.5">
                          Net: {fmt(run.net_total)} · {run.employee_count} employees
                        </div>
                      </button>
                    ))}
                  </div>
                </div>

                {/* Breakdown */}
                <div className="col-span-2">
                  {selectedRun && (
                    <>
                      <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                        <h3 className="text-sm font-semibold">
                          {periodLabel(selectedRun.period)} — Breakdown
                          {!isDraft && <Lock className="inline h-3 w-3 ml-1.5 text-muted-foreground" />}
                        </h3>
                        <div className="flex gap-2">
                          <Button size="sm" variant="outline" className="h-7 text-xs" onClick={exportRun}>
                            <Download className="h-3 w-3 mr-1" />Export CSV
                          </Button>
                          {canManage && isDraft && (
                            <>
                              <Button size="sm" className="h-7 text-xs bg-blue-600 hover:bg-blue-700 text-white" disabled={busy} onClick={() => handleLifecycle("finalize")}>
                                <BadgeCheck className="h-3 w-3 mr-1" />Finalize
                              </Button>
                              <Button size="sm" variant="outline" className="h-7 text-xs border-red-200 text-red-600" disabled={busy} onClick={() => handleLifecycle("delete")}>
                                <Trash2 className="h-3 w-3 mr-1" />Delete Draft
                              </Button>
                            </>
                          )}
                          {canPay && selectedRun.status === "Finalized" && (
                            <Button size="sm" className="h-7 text-xs bg-green-600 hover:bg-green-700 text-white" disabled={busy} onClick={() => handleLifecycle("pay")}>
                              <CheckCircle className="h-3 w-3 mr-1" />Mark Paid
                            </Button>
                          )}
                        </div>
                      </div>
                      <Card className="border-border">
                        <CardContent className="p-0">
                          {slipsLoading ? (
                            <div className="py-10 text-center"><Loader2 className="h-6 w-6 animate-spin text-gold mx-auto" /></div>
                          ) : (
                            <div className="overflow-x-auto">
                              <table className="w-full text-xs">
                                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                                  <tr>
                                    <th className="px-3 py-2.5 text-left">Employee</th>
                                    <th className="px-3 py-2.5 text-right">Gross</th>
                                    <th className="px-3 py-2.5 text-right">LOP</th>
                                    <th className="px-3 py-2.5 text-right">PF</th>
                                    <th className="px-3 py-2.5 text-right">ESI</th>
                                    <th className="px-3 py-2.5 text-right">PT</th>
                                    <th className="px-3 py-2.5 text-right">TDS</th>
                                    <th className="px-3 py-2.5 text-right">Net Pay</th>
                                    <th className="px-3 py-2.5 text-center">Actions</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {slips.map((s) => (
                                    <tr key={s.id} className="border-t border-border hover:bg-muted/20">
                                      <td className="px-3 py-2.5">
                                        <div className="font-medium">{s.employee_name}</div>
                                        <div className="text-muted-foreground text-[10px]">{s.designation || s.employee_code}</div>
                                      </td>
                                      <td className="px-3 py-2.5 text-right">{fmt(s.gross_salary)}</td>
                                      <td className="px-3 py-2.5 text-right">
                                        {editingSlip === s.id ? (
                                          <input type="number" min="0" max="31" step="0.5" value={editVals.lop_days}
                                            onChange={(e) => setEditVals((p) => ({ ...p, lop_days: e.target.value }))}
                                            className="w-14 h-6 text-right rounded border border-border bg-background px-1 text-xs" />
                                        ) : (
                                          <span className={Number(s.lop_days) > 0 ? "text-red-500" : "text-muted-foreground"}>
                                            {Number(s.lop_days) > 0 ? `${s.lop_days}d / -${fmt(s.lop_deduction)}` : "—"}
                                          </span>
                                        )}
                                      </td>
                                      <td className="px-3 py-2.5 text-right text-amber-600">-{fmt(s.pf_employee)}</td>
                                      <td className="px-3 py-2.5 text-right text-amber-600">{Number(s.esi_employee) > 0 ? `-${fmt(s.esi_employee)}` : "—"}</td>
                                      <td className="px-3 py-2.5 text-right text-amber-600">-{fmt(s.professional_tax)}</td>
                                      <td className="px-3 py-2.5 text-right">
                                        {editingSlip === s.id ? (
                                          <input type="number" min="0" value={editVals.tds}
                                            onChange={(e) => setEditVals((p) => ({ ...p, tds: e.target.value }))}
                                            className="w-20 h-6 text-right rounded border border-border bg-background px-1 text-xs" />
                                        ) : (
                                          <span className="text-red-500">{Number(s.tds) > 0 ? `-${fmt(s.tds)}` : "—"}</span>
                                        )}
                                      </td>
                                      <td className="px-3 py-2.5 text-right font-semibold text-green-600">{fmt(s.net_pay)}</td>
                                      <td className="px-3 py-2.5 text-center whitespace-nowrap">
                                        {editingSlip === s.id ? (
                                          <>
                                            <Button size="sm" className="h-6 px-2 text-[10px] bg-gold text-black hover:bg-gold/90" disabled={busy} onClick={() => saveEdit(s.id)}>Save</Button>
                                            <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px]" onClick={() => setEditingSlip(null)}>Cancel</Button>
                                          </>
                                        ) : (
                                          <>
                                            {canManage && isDraft && (
                                              <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px]" onClick={() => startEdit(s)}>Adjust</Button>
                                            )}
                                            <button className="p-1 rounded hover:bg-muted" title="Download slip"
                                              onClick={() => exportSlip(s, selectedRun.period, selectedRun.status)}>
                                              <Download className="h-3 w-3 text-muted-foreground" />
                                            </button>
                                          </>
                                        )}
                                      </td>
                                    </tr>
                                  ))}
                                  <tr className="border-t-2 border-border bg-muted/20 font-semibold">
                                    <td className="px-3 py-2.5 text-xs">Total ({slips.length})</td>
                                    <td className="px-3 py-2.5 text-right text-xs">{fmt(totals.gross)}</td>
                                    <td colSpan={5} className="px-3 py-2.5 text-right text-xs text-red-500">-{fmt(totals.ded)}</td>
                                    <td className="px-3 py-2.5 text-right text-xs text-green-600">{fmt(totals.net)}</td>
                                    <td />
                                  </tr>
                                </tbody>
                              </table>
                            </div>
                          )}
                        </CardContent>
                      </Card>
                    </>
                  )}
                </div>
              </div>
            )}
          </>
        )}

        {/* Employee self-service */}
        {mySlips.length > 0 && (
          <div>
            <h3 className="text-sm font-semibold mb-3">My Payslips</h3>
            <Card className="border-border">
              <CardContent className="p-0">
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2.5 text-left">Month</th>
                      <th className="px-3 py-2.5 text-right">Gross</th>
                      <th className="px-3 py-2.5 text-right">Deductions</th>
                      <th className="px-3 py-2.5 text-right">Net Pay</th>
                      <th className="px-3 py-2.5 text-left">Status</th>
                      <th className="px-3 py-2.5 text-center">Slip</th>
                    </tr>
                  </thead>
                  <tbody>
                    {mySlips.map((s) => (
                      <tr key={s.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5 font-medium">{s.run ? periodLabel(s.run.period) : "—"}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(s.gross_salary)}</td>
                        <td className="px-3 py-2.5 text-right text-red-500">-{fmt(s.total_deductions)}</td>
                        <td className="px-3 py-2.5 text-right font-semibold text-green-600">{fmt(s.net_pay)}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${statusBadge(s.run?.status || "")}`}>{s.run?.status}</Badge>
                        </td>
                        <td className="px-3 py-2.5 text-center">
                          <button className="p-1 rounded hover:bg-muted" title="Download slip"
                            onClick={() => s.run && exportSlip(s, s.run.period, s.run.status)}>
                            <Download className="h-3 w-3 text-muted-foreground" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </div>
        )}

        {!isPayrollViewer && mySlips.length === 0 && !loading && (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <FileText className="h-10 w-10 opacity-20" />
            <p className="text-sm">No payslips yet. They appear here once HR finalizes a payroll run.</p>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
