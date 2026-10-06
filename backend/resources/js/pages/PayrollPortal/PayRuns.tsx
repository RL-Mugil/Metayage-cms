import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { Loader2, BadgeCheck, CheckCircle, Lock, Plus, X, Send, ThumbsDown, Undo2, PauseCircle, PlayCircle, Download, FileDown, Pencil, Trash2 } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function periodLabel(period: string): string {
  return new Date(period).toLocaleString("default", { month: "long", year: "numeric" });
}

const hoursFmt = (minutes?: number | null) => minutes == null ? "—" : `${Math.floor(Number(minutes) / 60)}:${String(Number(minutes) % 60).padStart(2, "0")}`;

function statusBadge(status: string) {
  if (status === "Paid") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Finalized") return "bg-blue-100 text-blue-700 border-blue-200";
  if (status === "PendingApproval") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Rejected") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

export default function PayrollPortalPayRuns() {
  const [runs, setRuns] = useState<any[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [canPay, setCanPay] = useState(false);
  const [selectedRun, setSelectedRun] = useState<any | null>(null);
  const [slips, setSlips] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [slipsLoading, setSlipsLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [checkedIds, setCheckedIds] = useState<number[]>([]);
  const [withholdTarget, setWithholdTarget] = useState<any | null>(null);
  const [withholdReason, setWithholdReason] = useState("");
  const [rejectReason, setRejectReason] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [createMonth, setCreateMonth] = useState(new Date().toISOString().slice(0, 7));
  const [createRunType, setCreateRunType] = useState<"Regular" | "OffCycle">("Regular");
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState("");
  const [editSlip, setEditSlip] = useState<any | null>(null);
  const [editReimbursements, setEditReimbursements] = useState("");
  const [editHours, setEditHours] = useState("");
  const [editTds, setEditTds] = useState("");
  const [breakdownSlip, setBreakdownSlip] = useState<any | null>(null);
  const [bankAdvicePreview, setBankAdvicePreview] = useState<any | null>(null);
  const [bankAdviceBusy, setBankAdviceBusy] = useState(false);

  function loadRuns(selectId?: number) {
    api.getPayrollRuns()
      .then((data) => {
        setRuns(data.runs);
        setCanManage(data.can_manage);
        setCanPay(data.can_pay);
        const target = selectId ? data.runs.find((r: any) => r.id === selectId) : data.runs[0];
        if (target) selectRun(target);
        else { setSelectedRun(null); setSlips([]); }
      })
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadRuns(); }, []);

  // Payroll generation runs in the queue. Keep the selected run and its
  // breakdown synchronized until the worker changes Processing to Draft or
  // Failed, so a newly-created run never appears blank just because the first
  // response arrived before the queue job finished.
  useEffect(() => {
    if (!selectedRun || selectedRun.status !== "Processing") return;
    const timer = window.setInterval(() => {
      api.getPayrollRun(selectedRun.id).then((data) => {
        setSlips(data.payslips || []);
        setSelectedRun(data);
        setRuns((current) => current.map((run) => run.id === data.id ? data : run));
      });
    }, 3000);
    return () => window.clearInterval(timer);
  }, [selectedRun?.id, selectedRun?.status]);

  function selectRun(run: any) {
    setSelectedRun(run);
    setCheckedIds([]);
    setSlipsLoading(true);
    api.getPayrollRun(run.id).then((data) => setSlips(data.payslips || [])).finally(() => setSlipsLoading(false));
  }

  function flash(message: string) {
    setNotice(message);
    setTimeout(() => setNotice(""), 4000);
  }

  async function openBankAdvicePreview() {
    if (!selectedRun) return;
    setBankAdviceBusy(true);
    try {
      const data = await api.getBankAdvicePreview(selectedRun.id);
      setBankAdvicePreview({ ...data, pin: "", verified: false });
    } catch (e: any) {
      flash(e.message || "Could not load Bank Advice preview.");
    } finally {
      setBankAdviceBusy(false);
    }
  }

  async function confirmBankAdvice() {
    if (!selectedRun || !bankAdvicePreview) return;
    setBankAdviceBusy(true);
    try {
      await api.signBankAdvice(selectedRun.id, { pin: bankAdvicePreview.pin, verified: bankAdvicePreview.verified });
      setBankAdvicePreview(null);
      await api.downloadPdf(api.bankAdviceUrl(selectedRun.id), `bank-advice-${selectedRun.id}.pdf`);
    } catch (e: any) {
      flash(e.message || "Bank Advice verification failed.");
    } finally {
      setBankAdviceBusy(false);
    }
  }

  async function run<T>(action: () => Promise<T>, successMessage: string) {
    setBusy(true);
    try {
      await action();
      flash(successMessage);
      loadRuns(selectedRun?.id);
    } finally {
      setBusy(false);
    }
  }

  const isDraft = selectedRun?.status === "Draft";
  const isPendingApproval = selectedRun?.status === "PendingApproval";
  const isRejected = selectedRun?.status === "Rejected";
  const isFinalized = selectedRun?.status === "Finalized";
  const canEditSelectedRun = canManage && selectedRun && selectedRun.status !== "Processing";

  const payableUnpaidSlips = useMemo(
    () => slips.filter((s) => !s.withheld && !s.paid_at),
    [slips]
  );

  function toggleChecked(id: number) {
    setCheckedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  async function payCheckedSlips() {
    if (!selectedRun || checkedIds.length === 0) return;
    await run(() => api.payPayrollRunSelected(selectedRun.id, checkedIds), `Paid ${checkedIds.length} payslip(s).`);
    setCheckedIds([]);
  }

  async function submitWithhold() {
    if (!withholdTarget || !withholdReason.trim()) return;
    await run(async () => {
      await api.withholdPayslip(withholdTarget.id, withholdReason.trim());
      const data = await api.getPayrollRun(selectedRun.id);
      setSlips(data.payslips || []);
    }, `Withheld salary for ${withholdTarget.employee_name}.`);
    setWithholdTarget(null);
    setWithholdReason("");
  }

  async function submitRevertWithhold(slip: any) {
    await run(async () => {
      await api.revertWithholdPayslip(slip.id);
      const data = await api.getPayrollRun(selectedRun.id);
      setSlips(data.payslips || []);
    }, `Reverted withhold for ${slip.employee_name}.`);
  }

  async function submitReject() {
    if (!selectedRun || rejectReason === null || !rejectReason.trim()) return;
    await run(() => api.rejectPayrollRun(selectedRun.id, rejectReason.trim()), "Pay run rejected.");
    setRejectReason(null);
  }

  async function handleCreate() {
    setCreateBusy(true);
    setCreateError("");
    try {
      const result = await api.createPayrollRun(createMonth, createRunType);
      setShowCreate(false);
      const skipped = result.skipped_employees || [];
      flash(skipped.length ? `Run created. Skipped (no salary set): ${skipped.join(", ")}` : "Draft payroll run created.");
      loadRuns(result.run?.id);
    } catch (e: any) {
      setCreateError(e.message || "Failed to create payroll run.");
    } finally {
      setCreateBusy(false);
    }
  }

  async function saveSlipEdit() {
    if (!editSlip || !selectedRun) return;
    await run(async () => {
      await api.updatePayslip(editSlip.id, { reimbursements: Number(editReimbursements || 0), hours_logged: editHours || undefined, tds: Number(editSlip.gross_salary || 0) * Number(editTds || 0) / 100 });
      const data = await api.getPayrollRun(selectedRun.id);
      setSlips(data.payslips || []);
    }, "Draft payslip updated.");
    setEditSlip(null);
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Pay Runs" />
      <PageHeader eyebrow="Payroll Portal" title="Pay Runs" description="Create, submit, approve, and pay monthly payroll runs"
        actions={
          canManage ? (
            <Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowCreate(true)}>
              <Plus className="h-4 w-4 mr-2" />Create Pay Run
            </Button>
          ) : undefined
        }
      />

      <div className="px-8 py-6 space-y-4">
        {notice && <div className="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-400">{notice}</div>}

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : runs.length === 0 ? (
          <p className="text-sm text-muted-foreground py-10 text-center">
            {canManage ? 'No payroll runs yet. Use "Create Pay Run" to create the first draft run.' : "No payroll runs yet."}
          </p>
        ) : (
          <div className="grid grid-cols-3 gap-6">
            <div className="col-span-1">
              <h3 className="text-sm font-semibold mb-3">Runs</h3>
              <div className="space-y-1">
                {runs.map((run) => (
                  <button key={run.id} onClick={() => selectRun(run)}
                    className={`w-full text-left p-3 rounded-lg border transition-all ${selectedRun?.id === run.id ? "border-gold bg-gold/5" : "border-border hover:border-gold/30"}`}>
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium">
                        {periodLabel(run.period)}
                        {run.run_type === "OffCycle" && <span className="ml-1.5 text-[10px] text-muted-foreground">(Off-Cycle)</span>}
                      </span>
                      <Badge variant="outline" className={`text-[10px] ${statusBadge(run.status)}`}>{run.status}</Badge>
                    </div>
                    <div className="text-xs text-muted-foreground mt-0.5">Net: {fmt(run.net_total)} · {run.employee_count} employees</div>
                  </button>
                ))}
              </div>
            </div>

            <div className="col-span-2">
              {selectedRun && (
                <>
                  <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                    <h3 className="text-sm font-semibold">
                      {periodLabel(selectedRun.period)} — Breakdown
                      {!isDraft && !isPendingApproval && <Lock className="inline h-3 w-3 ml-1.5 text-muted-foreground" />}
                    </h3>
                    <div className="flex gap-2 flex-wrap">
                      {canManage && isDraft && (
                        <Button size="sm" className="h-7 text-xs bg-blue-600 hover:bg-blue-700 text-white" disabled={busy}
                          onClick={() => run(() => api.submitPayrollRunForApproval(selectedRun.id), "Submitted for approval.")}>
                          <Send className="h-3 w-3 mr-1" />Submit for Approval
                        </Button>
                      )}
                      {canEditSelectedRun && (
                        <Button size="sm" variant="outline" className="h-7 text-xs text-red-600 border-red-300" disabled={busy}
                          onClick={() => { if (window.confirm(`Delete the ${selectedRun.status.toLowerCase()} payroll run? This reverses linked loan deductions and removes its TDS liability.`)) run(() => api.deletePayrollRun(selectedRun.id), "Payroll run deleted."); }}>
                          <Trash2 className="h-3 w-3 mr-1" />Delete Run
                        </Button>
                      )}
                      {canManage && isPendingApproval && (
                        <>
                          <Button size="sm" className="h-7 text-xs bg-green-600 hover:bg-green-700 text-white" disabled={busy}
                            onClick={() => run(() => api.approvePayrollRun(selectedRun.id), "Pay run approved and finalized.")}>
                            <BadgeCheck className="h-3 w-3 mr-1" />Approve
                          </Button>
                          <Button size="sm" variant="outline" className="h-7 text-xs text-red-600 border-red-300" disabled={busy}
                            onClick={() => setRejectReason("")}>
                            <ThumbsDown className="h-3 w-3 mr-1" />Reject
                          </Button>
                        </>
                      )}
                      {canManage && isRejected && (
                        <Button size="sm" variant="outline" className="h-7 text-xs" disabled={busy}
                          onClick={() => run(() => api.recallPayrollRun(selectedRun.id), "Recalled to draft.")}>
                          <Undo2 className="h-3 w-3 mr-1" />Recall to Draft
                        </Button>
                      )}
                      {canPay && isFinalized && (
                        <Button size="sm" className="h-7 text-xs bg-green-600 hover:bg-green-700 text-white" disabled={busy}
                          onClick={() => run(() => api.payPayrollRun(selectedRun.id), "All payable payslips marked paid.")}>
                          <CheckCircle className="h-3 w-3 mr-1" />Mark All Paid
                        </Button>
                      )}
                      {canPay && isFinalized && checkedIds.length > 0 && (
                        <Button size="sm" variant="outline" className="h-7 text-xs" disabled={busy} onClick={payCheckedSlips}>
                          <CheckCircle className="h-3 w-3 mr-1" />Pay Selected ({checkedIds.length})
                        </Button>
                      )}
                      {(isFinalized || selectedRun.status === "Paid") && (
                        <>
                          <Button size="sm" variant="outline" className="h-7 text-xs" disabled={bankAdviceBusy} onClick={openBankAdvicePreview}><Download className="h-3 w-3 mr-1" />Bank Advice PDF</Button>
                          <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => api.downloadPdf(api.tdsWorksheetsUrl(selectedRun.id), `payslips-${selectedRun.id}.zip`).catch((e) => flash(e.message))}><FileDown className="h-3 w-3 mr-1" />Payslips ZIP</Button>
                        </>
                      )}
                    </div>
                  </div>

                  {isRejected && selectedRun.rejection_reason && (
                    <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">
                      Rejected: {selectedRun.rejection_reason}
                    </div>
                  )}

                  {rejectReason !== null && (
                    <div className="mb-3 rounded-md border border-border p-3 space-y-2">
                      <label className="text-xs font-medium">Rejection reason</label>
                      <textarea className="w-full text-xs rounded-md border border-border bg-background p-2" rows={2}
                        value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} placeholder="Why is this run being rejected?" />
                      <div className="flex gap-2">
                        <Button size="sm" className="h-7 text-xs bg-red-600 hover:bg-red-700 text-white" disabled={busy || !rejectReason.trim()} onClick={submitReject}>Confirm Reject</Button>
                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setRejectReason(null)}>Cancel</Button>
                      </div>
                    </div>
                  )}

                  {withholdTarget && (
                    <div className="mb-3 rounded-md border border-border p-3 space-y-2">
                      <label className="text-xs font-medium">Withhold reason for {withholdTarget.employee_name}</label>
                      <textarea className="w-full text-xs rounded-md border border-border bg-background p-2" rows={2}
                        value={withholdReason} onChange={(e) => setWithholdReason(e.target.value)} placeholder="Reason for withholding this salary" />
                      <div className="flex gap-2">
                        <Button size="sm" className="h-7 text-xs" disabled={busy || !withholdReason.trim()} onClick={submitWithhold}>Confirm Withhold</Button>
                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => { setWithholdTarget(null); setWithholdReason(""); }}>Cancel</Button>
                      </div>
                    </div>
                  )}

                  <Card className="border-border">
                    <CardContent className="p-0">
                      {slipsLoading ? (
                        <div className="py-10 text-center"><Loader2 className="h-6 w-6 animate-spin text-gold mx-auto" /></div>
                      ) : (
                        <table className="w-full text-xs">
                          <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                            <tr>
                              {isFinalized && <th className="px-3 py-2.5 text-left w-8"></th>}
                              <th className="px-3 py-2.5 text-left">Employee</th>
                              <th className="px-3 py-2.5 text-right">Gross</th>
                              <th className="px-3 py-2.5 text-right">Deductions</th>
                              <th className="px-3 py-2.5 text-right">Net Salary</th>
                              <th className="px-3 py-2.5 text-right">Reimbursement</th>
                              <th className="px-3 py-2.5 text-right">Hours</th>
                              <th className="px-3 py-2.5 text-left">Status</th>
                              {isDraft && <th className="px-3 py-2.5 text-left">Withhold</th>}
                            </tr>
                          </thead>
                          <tbody>
                            {slips.map((s) => (
                              <tr key={s.id} className="border-t border-border hover:bg-muted/20">
                                {isFinalized && (
                                  <td className="px-3 py-2.5">
                                    {!s.withheld && !s.paid_at && (
                                      <input type="checkbox" checked={checkedIds.includes(s.id)} onChange={() => toggleChecked(s.id)} />
                                    )}
                                  </td>
                                )}
                                <td className="px-3 py-2.5">
                                  <div className="font-medium">{s.employee_name}</div>
                                  <div className="text-muted-foreground text-[10px]">{s.designation || s.employee_code}</div>
                                </td>
                                <td className="px-3 py-2.5 text-right">{fmt(s.gross_salary)}</td>
                                <td className="px-3 py-2.5 text-right text-red-500"><button className="underline decoration-dotted" onClick={() => setBreakdownSlip(s)}>-{fmt(s.total_deductions)}</button></td>
                                <td className="px-3 py-2.5 text-right font-semibold text-green-600">{fmt(s.net_pay)}</td>
                                <td className="px-3 py-2.5 text-right">{fmt(s.reimbursements || 0)}</td>
                                <td className={`px-3 py-2.5 text-right ${s.hours_logged_minutes != null && s.hours_logged_minutes < 181 * 60 ? "text-amber-600" : ""}`}>{hoursFmt(s.hours_logged_minutes)}</td>
                                <td className="px-3 py-2.5">
                                  {s.withheld ? (
                                    <Badge variant="outline" className="text-[10px] bg-amber-100 text-amber-700 border-amber-200">Withheld</Badge>
                                  ) : s.paid_at ? (
                                    <Badge variant="outline" className="text-[10px] bg-green-100 text-green-700 border-green-200">Paid</Badge>
                                  ) : (
                                    <span className="text-muted-foreground text-[10px]">—</span>
                                  )}
                                </td>
                                {canEditSelectedRun && (
                                  <td className="px-3 py-2.5">
                                    <Button size="sm" variant="outline" className="h-6 text-[10px] mr-1" disabled={busy} onClick={() => { setEditSlip(s); setEditReimbursements(String(s.reimbursements || 0)); setEditHours(s.hours_logged_minutes == null ? "" : hoursFmt(s.hours_logged_minutes)); setEditTds(String((Number(s.tds || 0) / Math.max(1, Number(s.gross_salary || 0)) * 100).toFixed(2))); }}><Pencil className="h-3 w-3 mr-1" />Edit</Button>
                                    {isFinalized && <Button size="sm" variant="outline" className="h-6 text-[10px] mr-1 text-green-700 border-green-300" disabled={busy} onClick={() => run(() => api.signInternalPayslip(s.id), "Payslip signed by Accounts.")}><BadgeCheck className="h-3 w-3 mr-1" />Sign</Button>}
                                    {s.employee_id && <button onClick={() => api.downloadSalaryCertificate(s.employee_id).catch((e) => flash(e.message))} className="inline-flex h-6 items-center rounded border border-border px-2 text-[10px]">Certificate</button>}
                                    {isDraft && (s.withheld ? (
                                      <Button size="sm" variant="outline" className="h-6 text-[10px]" disabled={busy} onClick={() => submitRevertWithhold(s)}>
                                        <PlayCircle className="h-3 w-3 mr-1" />Revert
                                      </Button>
                                    ) : (
                                      <Button size="sm" variant="outline" className="h-6 text-[10px]" disabled={busy} onClick={() => setWithholdTarget(s)}>
                                        <PauseCircle className="h-3 w-3 mr-1" />Withhold
                                      </Button>
                                    ))}
                                  </td>
                                )}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </CardContent>
                  </Card>
                  {isFinalized && (
                    <p className="text-[11px] text-muted-foreground mt-2">
                      {payableUnpaidSlips.length} of {slips.length} payslip(s) still payable. Withheld salaries are excluded until released in a later draft run.
                    </p>
                  )}
                </>
              )}
            </div>
          </div>
        )}
      </div>

      {showCreate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Create Pay Run</h2>
              <button onClick={() => setShowCreate(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {createError && (
              <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{createError}</div>
            )}
            <p className="text-sm text-muted-foreground mb-4">
              Creates a draft run for every active employee with a salary. You can withhold or adjust payslips before submitting for approval.
            </p>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <div className="text-[10px] text-muted-foreground mb-0.5">Payroll Month</div>
                <input type="month" value={createMonth} onChange={(e) => setCreateMonth(e.target.value)}
                  className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div>
                <div className="text-[10px] text-muted-foreground mb-0.5">Run Type</div>
                <select value={createRunType} onChange={(e) => setCreateRunType(e.target.value as "Regular" | "OffCycle")}
                  className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                  <option value="Regular">Regular (one per month)</option>
                  <option value="OffCycle">Off-Cycle (coexists with Regular)</option>
                </select>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={createBusy} onClick={handleCreate}>
                {createBusy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Create {createRunType === "OffCycle" ? "Off-Cycle" : "Draft"} Run
              </Button>
              <Button variant="outline" onClick={() => setShowCreate(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}
      {editSlip && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4 space-y-4">
            <div className="flex items-center justify-between"><h2 className="font-display text-lg font-semibold">Edit draft payslip</h2><button onClick={() => setEditSlip(null)}><X className="h-5 w-5 text-muted-foreground" /></button></div>
            <p className="text-xs text-muted-foreground">{editSlip.employee_name}. Enter hours as <code>[hh]:[mm]</code>. Monthly shortages are carried into the annual April reconciliation; overtime is not paid.</p>
            <label className="block text-xs">Reimbursement (₹)<input type="number" min="0" step="0.01" value={editReimbursements} onChange={e => setEditReimbursements(e.target.value)} className="mt-1 w-full h-8 rounded border border-border bg-background px-2 text-xs" /></label>
            <label className="block text-xs">Hours logged<input placeholder="181:00" value={editHours} onChange={e => setEditHours(e.target.value)} className="mt-1 w-full h-8 rounded border border-border bg-background px-2 text-xs" /></label>
            <label className="block text-xs">TDS (%)<input type="number" min="0" max="100" step="0.01" value={editTds} onChange={e => setEditTds(e.target.value)} className="mt-1 w-full h-8 rounded border border-border bg-background px-2 text-xs" /></label>
            <div className="flex gap-2"><Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={busy} onClick={saveSlipEdit}>Save changes</Button><Button variant="outline" onClick={() => setEditSlip(null)}>Cancel</Button></div>
          </div>
        </div>
      )}
      {breakdownSlip && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4"><div className="flex items-center justify-between mb-4"><h2 className="font-display text-lg font-semibold">Deduction breakdown</h2><button onClick={() => setBreakdownSlip(null)}><X className="h-5 w-5 text-muted-foreground" /></button></div><div className="space-y-2 text-sm"><div className="flex justify-between"><span>Professional Tax</span><b>{fmt(breakdownSlip.professional_tax || 0)}</b></div><div className="flex justify-between"><span>TDS</span><b>{fmt(breakdownSlip.tds || 0)}</b></div><div className="flex justify-between"><span>Health insurance</span><b>{fmt(breakdownSlip.health_insurance || 0)}</b></div><div className="flex justify-between"><span>Other deductions</span><b>{fmt(breakdownSlip.other_deductions || 0)}</b></div><hr/><div className="flex justify-between font-semibold"><span>Total deductions</span><b>{fmt(breakdownSlip.total_deductions || 0)}</b></div></div></div></div>
      )}
      {bankAdvicePreview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-6xl p-6 m-4 max-h-[90vh] overflow-auto">
            <div className="flex items-center justify-between mb-4"><div><h2 className="font-display text-lg font-semibold">Bank Advice Preview — {bankAdvicePreview.period}</h2><p className="text-xs text-muted-foreground mt-1">Review the complete document, then confirm it once with your internal signature PIN.</p></div><button onClick={() => setBankAdvicePreview(null)}><X className="h-5 w-5 text-muted-foreground" /></button></div>
            <div className="overflow-x-auto border border-border rounded-md"><table className="w-full text-xs"><thead className="bg-muted/40"><tr><th className="p-2 text-left">Employee</th><th className="p-2 text-left">Bank</th><th className="p-2 text-left">Account</th><th className="p-2 text-right">Hrs</th><th className="p-2 text-right">Net Pay</th><th className="p-2 text-right">Reimbursement</th><th className="p-2 text-right">Total</th></tr></thead><tbody>{(bankAdvicePreview.rows || []).map((row: any) => <tr key={row.payslip_id} className="border-t border-border"><td className="p-2"><div className="font-medium">{row.employee_name}</div><div className="text-[10px] text-muted-foreground">{row.employee_code}</div></td><td className="p-2">{row.bank_name || "—"}</td><td className="p-2 font-mono">{row.account_number || "—"}</td><td className="p-2 text-right">{row.hours_logged_minutes == null ? "—" : `${Math.floor(row.hours_logged_minutes / 60)}:${String(row.hours_logged_minutes % 60).padStart(2, "0")}`}</td><td className="p-2 text-right">{fmt(row.net_pay)}</td><td className="p-2 text-right">{fmt(row.reimbursement)}</td><td className="p-2 text-right font-semibold">{fmt(row.total)}</td></tr>)}</tbody></table></div>
            <div className="mt-5 rounded-lg border border-border bg-muted/20 p-4"><label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={Boolean(bankAdvicePreview.verified)} onChange={(e) => setBankAdvicePreview((current: any) => ({ ...current, verified: e.target.checked }))} /> I have verified all employees, bank details, net pay, reimbursements, and the total document.</label><div className="mt-3 flex items-center gap-3"><label className="text-sm text-muted-foreground" htmlFor="bank-advice-document-pin">Document PIN</label><input id="bank-advice-document-pin" type="password" inputMode="numeric" maxLength={4} value={bankAdvicePreview.pin} onChange={(e) => setBankAdvicePreview((current: any) => ({ ...current, pin: e.target.value.replace(/\D/g, "").slice(0, 4) }))} className="w-24 h-8 rounded border border-border bg-background px-2 text-center tracking-widest" placeholder="0000" /><span className="text-xs text-muted-foreground">4 digits</span></div></div>
            <div className="flex justify-end gap-2 mt-5"><Button variant="outline" onClick={() => setBankAdvicePreview(null)}>Cancel</Button><Button className="bg-gold hover:bg-gold/90 text-black" disabled={bankAdviceBusy} onClick={confirmBankAdvice}>{bankAdviceBusy ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <BadgeCheck className="h-4 w-4 mr-2" />}Verify &amp; Sign &amp; Download</Button></div>
          </div>
        </div>
      )}
    </PayrollPortalLayout>
  );
}
