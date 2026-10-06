import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { Loader2, Plus, X, Receipt, Link2 } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

function statusBadge(status: string) {
  if (status === "Associated") return "bg-green-100 text-green-700 border-green-200";
  if (status === "PartiallyAssociated") return "bg-amber-100 text-amber-700 border-amber-200";
  return "bg-muted text-muted-foreground border-border";
}

const emptyForm = {
  bsr_code: "", payment_date: new Date().toISOString().slice(0, 10), serial_number: "",
  amount: "", interest_amount: "", penalty_amount: "", financial_year: String(currentFiscalYear()), quarter: "", notes: "",
};

export default function PayrollPortalTaxesChallans() {
  const [challans, setChallans] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [showRecord, setShowRecord] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const [associateTarget, setAssociateTarget] = useState<any | null>(null);
  const [openLiabilities, setOpenLiabilities] = useState<any[]>([]);
  const [liabilitiesLoading, setLiabilitiesLoading] = useState(false);
  const [checkedAmounts, setCheckedAmounts] = useState<Record<number, string>>({});
  const [associating, setAssociating] = useState(false);

  function load() {
    setLoading(true);
    api.getChallans().then(setChallans).catch((e) => setError(e.message || "Failed to load challans.")).finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  async function handleRecord() {
    setSaving(true);
    setError("");
    try {
      await api.recordChallan({
        bsr_code: form.bsr_code,
        payment_date: form.payment_date,
        serial_number: form.serial_number,
        amount: parseFloat(form.amount) || 0,
        interest_amount: parseFloat(form.interest_amount) || 0,
        penalty_amount: parseFloat(form.penalty_amount) || 0,
        financial_year: parseInt(form.financial_year, 10),
        quarter: form.quarter ? parseInt(form.quarter, 10) : undefined,
        notes: form.notes || undefined,
      });
      setShowRecord(false);
      setForm(emptyForm);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to record challan.");
    } finally {
      setSaving(false);
    }
  }

  function openAssociate(challan: any) {
    setAssociateTarget(challan);
    setCheckedAmounts({});
    setLiabilitiesLoading(true);
    api.getTdsLiabilities({ financial_year: challan.financial_year })
      .then((rows) => setOpenLiabilities(rows.filter((r: any) => r.status !== "Paid")))
      .catch((e) => setError(e.message || "Failed to load open liabilities."))
      .finally(() => setLiabilitiesLoading(false));
  }

  const checkedTotal = useMemo(
    () => Object.values(checkedAmounts).reduce((sum, v) => sum + (parseFloat(v) || 0), 0),
    [checkedAmounts]
  );
  const remaining = associateTarget ? Number(associateTarget.amount) - (associateTarget.allocations?.reduce((s: number, a: any) => s + Number(a.amount), 0) || 0) : 0;

  async function submitAssociate() {
    if (!associateTarget) return;
    const allocations = Object.entries(checkedAmounts)
      .map(([id, amt]) => ({ tds_liability_id: Number(id), amount: parseFloat(amt) || 0 }))
      .filter((a) => a.amount > 0);
    if (allocations.length === 0) return;
    setAssociating(true);
    setError("");
    try {
      await api.associateChallan(associateTarget.id, allocations);
      setAssociateTarget(null);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to associate challan.");
    } finally {
      setAssociating(false);
    }
  }

  async function removeAllocation(allocationId: number) {
    if (!confirm("Remove this allocation?")) return;
    try {
      await api.dissociateChallanAllocation(allocationId);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to remove allocation.");
    }
  }

  // Compact, dense field style for the wide Record Challan modal.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Challans" />
      <PageHeader eyebrow="Payroll Portal · Taxes & Forms" title="Challans"
        description="Record government TDS tax payments and associate them against open employee liabilities"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowRecord(true)}><Plus className="h-4 w-4 mr-2" />Record Challan</Button>}
      />

      <div className="px-8 py-6 space-y-4">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}

        <Card className="border-border">
          <CardContent className="p-0">
            {loading ? (
              <div className="py-16 text-center"><Loader2 className="h-8 w-8 animate-spin text-gold mx-auto" /></div>
            ) : challans.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
                <Receipt className="h-10 w-10 opacity-20" />
                <p className="text-sm">No challans recorded yet.</p>
              </div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">BSR Code</th>
                    <th className="px-3 py-2.5 text-left">Payment Date</th>
                    <th className="px-3 py-2.5 text-left">Serial No.</th>
                    <th className="px-3 py-2.5 text-right">Amount</th>
                    <th className="px-3 py-2.5 text-right">Allocated</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    <th className="px-3 py-2.5 text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {challans.map((c) => {
                    const allocated = (c.allocations || []).reduce((s: number, a: any) => s + Number(a.amount), 0);
                    return (
                      <>
                        <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                          <td className="px-3 py-2.5 font-mono">{c.bsr_code}</td>
                          <td className="px-3 py-2.5">{new Date(c.payment_date).toLocaleDateString("en-IN")}</td>
                          <td className="px-3 py-2.5 font-mono">{c.serial_number}</td>
                          <td className="px-3 py-2.5 text-right">{fmt(c.amount)}</td>
                          <td className="px-3 py-2.5 text-right">{fmt(allocated)}</td>
                          <td className="px-3 py-2.5"><Badge variant="outline" className={`text-[10px] ${statusBadge(c.status)}`}>{c.status}</Badge></td>
                          <td className="px-3 py-2.5 text-center">
                            {c.status !== "Associated" && (
                              <Button size="sm" variant="outline" className="h-6 px-2 text-[10px]" onClick={() => openAssociate(c)}>
                                <Link2 className="h-3 w-3 mr-1" />Associate
                              </Button>
                            )}
                          </td>
                        </tr>
                        {(c.allocations || []).length > 0 && (
                          <tr className="border-t border-border bg-muted/10">
                            <td colSpan={7} className="px-3 py-2 pl-6">
                              <div className="flex flex-wrap gap-2">
                                {c.allocations.map((a: any) => (
                                  <span key={a.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-background px-2 py-0.5 text-[10px]">
                                    {a.tds_liability?.employee?.full_name || `Liability #${a.tds_liability_id}`}: {fmt(a.amount)}
                                    <button onClick={() => removeAllocation(a.id)} title="Remove allocation"><X className="h-2.5 w-2.5 text-muted-foreground hover:text-red-500" /></button>
                                  </span>
                                ))}
                              </div>
                            </td>
                          </tr>
                        )}
                      </>
                    );
                  })}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>

      {showRecord && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-4xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Record Challan</h2>
              <button onClick={() => setShowRecord(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <div className="space-y-5">
              <section>
                <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                  <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                  Challan Details
                </h3>
                <div className="grid grid-cols-4 gap-2">
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">BSR Code *</div>
                    <input value={form.bsr_code} onChange={(e) => setForm((p) => ({ ...p, bsr_code: e.target.value }))} maxLength={7} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Serial No. *</div>
                    <input value={form.serial_number} onChange={(e) => setForm((p) => ({ ...p, serial_number: e.target.value }))} maxLength={10} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Payment Date *</div>
                    <input type="date" value={form.payment_date} onChange={(e) => setForm((p) => ({ ...p, payment_date: e.target.value }))} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Amount (₹) *</div>
                    <input type="number" value={form.amount} onChange={(e) => setForm((p) => ({ ...p, amount: e.target.value }))} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Interest (₹)</div>
                    <input type="number" value={form.interest_amount} onChange={(e) => setForm((p) => ({ ...p, interest_amount: e.target.value }))} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Penalty (₹)</div>
                    <input type="number" value={form.penalty_amount} onChange={(e) => setForm((p) => ({ ...p, penalty_amount: e.target.value }))} className={fInp} /></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Financial Year *</div>
                    <select value={form.financial_year} onChange={(e) => setForm((p) => ({ ...p, financial_year: e.target.value }))} className={fInp}>
                      {[currentFiscalYear(), currentFiscalYear() - 1].map((y) => <option key={y} value={y}>FY {y}-{String(y + 1).slice(-2)}</option>)}
                    </select></div>
                  <div><div className="text-[10px] text-muted-foreground mb-0.5">Quarter</div>
                    <select value={form.quarter} onChange={(e) => setForm((p) => ({ ...p, quarter: e.target.value }))} className={fInp}>
                      <option value="">—</option>
                      <option value="1">Q1</option><option value="2">Q2</option><option value="3">Q3</option><option value="4">Q4</option>
                    </select></div>
                  <div className="col-span-4"><div className="text-[10px] text-muted-foreground mb-0.5">Notes</div>
                    <textarea value={form.notes} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} rows={1}
                      className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" /></div>
                </div>
              </section>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleRecord} disabled={saving || !form.bsr_code || !form.serial_number || !form.amount}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Record Challan
              </Button>
              <Button variant="outline" onClick={() => setShowRecord(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {associateTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Associate Challan — {associateTarget.bsr_code} / {associateTarget.serial_number}</h2>
              <button onClick={() => setAssociateTarget(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-xs text-muted-foreground mb-3">Unallocated: {fmt(remaining)} of {fmt(associateTarget.amount)}</p>
            {liabilitiesLoading ? (
              <div className="py-10 text-center"><Loader2 className="h-6 w-6 animate-spin text-gold mx-auto" /></div>
            ) : openLiabilities.length === 0 ? (
              <p className="text-sm text-muted-foreground py-6 text-center">No open liabilities for this financial year.</p>
            ) : (
              <div className="max-h-80 overflow-y-auto space-y-1">
                {openLiabilities.map((l) => {
                  const outstanding = Number(l.tds_deducted) - Number(l.tds_paid);
                  const checked = l.id in checkedAmounts;
                  return (
                    <div key={l.id} className="flex items-center gap-3 rounded-md border border-border px-3 py-2 text-xs">
                      <input type="checkbox" checked={checked}
                        onChange={(e) => setCheckedAmounts((prev) => {
                          const next = { ...prev };
                          if (e.target.checked) next[l.id] = String(outstanding); else delete next[l.id];
                          return next;
                        })} />
                      <div className="flex-1">
                        <div className="font-medium">{l.employee?.full_name}</div>
                        <div className="text-muted-foreground text-[10px]">{new Date(l.period).toLocaleString("default", { month: "short", year: "numeric" })} · outstanding {fmt(outstanding)}</div>
                      </div>
                      {checked && (
                        <input type="number" value={checkedAmounts[l.id]} max={outstanding}
                          onChange={(e) => setCheckedAmounts((prev) => ({ ...prev, [l.id]: e.target.value }))}
                          className="w-24 h-7 rounded border border-border bg-background px-2 text-right text-xs" />
                      )}
                    </div>
                  );
                })}
              </div>
            )}
            <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
              <span className="text-xs text-muted-foreground">Allocating: <span className="font-medium text-foreground">{fmt(checkedTotal)}</span></span>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => setAssociateTarget(null)}>Cancel</Button>
                <Button className="bg-gold hover:bg-gold/90 text-black" disabled={associating || checkedTotal <= 0} onClick={submitAssociate}>
                  {associating ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Associate
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </PayrollPortalLayout>
  );
}
