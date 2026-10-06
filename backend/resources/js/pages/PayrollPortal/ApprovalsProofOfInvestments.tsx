import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, FileBarChart2, Paperclip, Lock, CheckCircle2 } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string | null | undefined) => `₹${Number(n ?? 0).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

const SECTIONS: { key: string; label: string; declaredField: string; approvedField: string }[] = [
  { key: "80C", label: "Sec. 123 (80C)", declaredField: "section_80c_amount", approvedField: "approved_section_80c_amount" },
  { key: "80CCD1B", label: "Sec. 124(3) (80CCD1B)", declaredField: "section_80ccd1b_amount", approvedField: "approved_section_80ccd1b_amount" },
  { key: "80D", label: "Sec. 126 (80D)", declaredField: "section_80d_amount", approvedField: "approved_section_80d_amount" },
  { key: "80E", label: "Sec. 129 (80E)", declaredField: "section_80e_amount", approvedField: "approved_section_80e_amount" },
  { key: "80G", label: "Sec. 133 (80G)", declaredField: "section_80g_amount", approvedField: "approved_section_80g_amount" },
  { key: "80TTA", label: "Sec. 153 (80TTA)", declaredField: "section_80tta_amount", approvedField: "approved_section_80tta_amount" },
  { key: "HRA", label: "HRA Rent Paid", declaredField: "hra_rent_paid", approvedField: "approved_hra_rent_paid" },
  { key: "HomeLoanInterest", label: "Home Loan Interest", declaredField: "home_loan_interest_declared", approvedField: "approved_home_loan_interest_amount" },
  { key: "LTA", label: "LTA (Sec. 11/Sch.III)", declaredField: "lta_exemption_claimed", approvedField: "approved_lta_exemption_claimed" },
  { key: "80GG", label: "Sec. 134 (80GG)", declaredField: "section_80gg_amount", approvedField: "approved_section_80gg_amount" },
  { key: "80DD", label: "Sec. 127 (80DD)", declaredField: "section_80dd_amount", approvedField: "approved_section_80dd_amount" },
  { key: "80DDB", label: "Sec. 128 (80DDB)", declaredField: "section_80ddb_amount", approvedField: "approved_section_80ddb_amount" },
  { key: "80EE", label: "Sec. 130 (80EE)", declaredField: "section_80ee_amount", approvedField: "approved_section_80ee_amount" },
  { key: "80EEA", label: "Sec. 131 (80EEA)", declaredField: "section_80eea_amount", approvedField: "approved_section_80eea_amount" },
  { key: "80GGA", label: "Sec. 135 (80GGA)", declaredField: "section_80gga_amount", approvedField: "approved_section_80gga_amount" },
  { key: "80GGC", label: "Sec. 137 (80GGC)", declaredField: "section_80ggc_amount", approvedField: "approved_section_80ggc_amount" },
  { key: "PreviousEmployerSalary", label: "Previous Employer Salary", declaredField: "previous_employer_salary", approvedField: "approved_previous_employer_salary" },
  { key: "Other", label: "Other Income", declaredField: "other_income_declared", approvedField: "approved_other_income_amount" },
];

export default function PayrollPortalApprovalsProofOfInvestments() {
  const [fy, setFy] = useState(currentFiscalYear());
  const [rows, setRows] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [drafts, setDrafts] = useState<Record<number, Record<string, string>>>({});
  const [reasons, setReasons] = useState<Record<number, Record<string, string>>>({});
  const [busyId, setBusyId] = useState<number | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);

  function load() {
    setLoading(true);
    setError("");
    api.getPendingProofOfInvestments(fy)
      .then((data) => {
        setRows(data);
        const d: Record<number, Record<string, string>> = {};
        data.forEach((row: any) => {
          d[row.id] = {};
          SECTIONS.forEach((s) => {
            const declared = row.declaration?.[s.declaredField];
            const approved = row[s.approvedField];
            d[row.id][s.key] = String(approved ?? declared ?? 0);
          });
        });
        setDrafts(d);
      })
      .catch((e) => setError(e.message || "Failed to load."))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [fy]);

  function flash(msg: string) {
    setNotice(msg);
    setTimeout(() => setNotice(""), 4000);
  }

  async function save(row: any) {
    setBusyId(row.id);
    setError("");
    try {
      const payload: Record<string, any> = {};
      const reasonPayload: Record<string, string> = {};
      SECTIONS.forEach((s) => {
        payload[s.approvedField] = parseFloat(drafts[row.id]?.[s.key] ?? "0") || 0;
        const declared = Number(row.declaration?.[s.declaredField] ?? 0);
        const approved = Number(drafts[row.id]?.[s.key] ?? 0);
        if (approved < declared && reasons[row.id]?.[s.key]) {
          reasonPayload[s.key] = reasons[row.id][s.key];
        }
      });
      if (Object.keys(reasonPayload).length > 0) payload.rejection_reasons_json = reasonPayload;
      const updated = await api.setPoiApprovedAmounts(row.id, payload);
      setRows((prev) => prev.map((r) => (r.id === row.id ? { ...r, ...updated } : r)));
      flash("Saved.");
    } catch (e: any) {
      setError(e.message || "Failed to save.");
    } finally {
      setBusyId(null);
    }
  }

  async function considerForIt(row: any) {
    if (!confirm(`Lock in approved amounts for ${row.employee?.full_name}? This cannot be undone.`)) return;
    setBusyId(row.id);
    setError("");
    try {
      await save(row);
      await api.considerPoiForIt(row.id);
      setRows((prev) => prev.filter((r) => r.id !== row.id));
      flash("Locked in for tax computation.");
    } catch (e: any) {
      setError(e.message || "Failed to lock in.");
    } finally {
      setBusyId(null);
    }
  }

  async function approveAll() {
    if (!confirm(`Accept every declared figure as approved and lock in for all ${rows.length} pending row(s)?`)) return;
    setBulkBusy(true);
    setError("");
    try {
      const { considered_count } = await api.approveAllAndConsiderPoi(fy);
      flash(`${considered_count} row(s) approved and locked in.`);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to approve all.");
    } finally {
      setBulkBusy(false);
    }
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Proof of Investments" />
      <PageHeader eyebrow="Payroll Portal · Approvals" title="Proof of Investments"
        description="Review uploaded proof documents, adjust approved amounts per section, and lock in for tax computation"
        actions={
          rows.length > 0 ? (
            <Button className="bg-gold hover:bg-gold/90 text-black" disabled={bulkBusy} onClick={approveAll}>
              {bulkBusy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <CheckCircle2 className="h-4 w-4 mr-2" />}Approve All & Consider for IT
            </Button>
          ) : undefined
        }
      />

      <div className="px-8 py-6 space-y-4">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}
        {notice && <div className="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-400">{notice}</div>}

        <select value={fy} onChange={(e) => setFy(Number(e.target.value))}
          className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
          {[currentFiscalYear(), currentFiscalYear() - 1].map((y) => <option key={y} value={y}>FY {y}-{String(y + 1).slice(-2)}</option>)}
        </select>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : rows.length === 0 ? (
          <Card className="border-border">
            <CardContent className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
              <FileBarChart2 className="h-10 w-10 opacity-20" />
              <p className="text-sm">No pending proof-of-investment reviews for this FY.</p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-4">
            {rows.map((row) => (
              <Card key={row.id} className="border-border">
                <CardContent className="p-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <div className="font-medium text-sm">{row.employee?.full_name}</div>
                      <div className="text-muted-foreground text-xs">{row.employee?.employee_code} · {row.declaration?.regime_choice} regime</div>
                    </div>
                    <Badge variant="outline" className="text-[10px]">{row.status}</Badge>
                  </div>

                  <table className="w-full text-xs">
                    <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
                      <tr>
                        <th className="text-left py-1.5">Section</th>
                        <th className="text-right py-1.5">Declared</th>
                        <th className="text-right py-1.5">Approved</th>
                        <th className="text-left py-1.5 pl-3">Reason (if lower)</th>
                        <th className="text-center py-1.5">Docs</th>
                      </tr>
                    </thead>
                    <tbody>
                      {SECTIONS.map((s) => {
                        const declared = Number(row.declaration?.[s.declaredField] ?? 0);
                        const approvedDraft = Number(drafts[row.id]?.[s.key] ?? 0);
                        const docs = (row.documents || []).filter((d: any) => d.section === s.key);
                        return (
                          <tr key={s.key} className="border-t border-border">
                            <td className="py-1.5">{s.label}</td>
                            <td className="py-1.5 text-right text-muted-foreground">{fmt(declared)}</td>
                            <td className="py-1.5 text-right">
                              <input type="number" value={drafts[row.id]?.[s.key] ?? "0"}
                                onChange={(e) => setDrafts((prev) => ({ ...prev, [row.id]: { ...prev[row.id], [s.key]: e.target.value } }))}
                                className="w-24 h-7 rounded border border-border bg-background px-2 text-right text-xs" />
                            </td>
                            <td className="py-1.5 pl-3">
                              {approvedDraft < declared && (
                                <input value={reasons[row.id]?.[s.key] ?? ""} placeholder="Reason for reduction"
                                  onChange={(e) => setReasons((prev) => ({ ...prev, [row.id]: { ...prev[row.id], [s.key]: e.target.value } }))}
                                  className="w-full h-7 rounded border border-border bg-background px-2 text-xs" />
                              )}
                            </td>
                            <td className="py-1.5 text-center">
                              {docs.length > 0 ? (
                                <div className="flex items-center justify-center gap-1">
                                  {docs.map((d: any) => (
                                    <a key={d.id} href={api.poiDocumentDownloadUrl(d.id)} target="_blank" rel="noreferrer" title={d.original_filename}>
                                      <Paperclip className="h-3 w-3 text-gold" />
                                    </a>
                                  ))}
                                </div>
                              ) : (
                                <span className="text-muted-foreground">—</span>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>

                  <div className="flex gap-2 pt-1">
                    <Button size="sm" variant="outline" disabled={busyId === row.id} onClick={() => save(row)}>
                      {busyId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Save
                    </Button>
                    <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={busyId === row.id} onClick={() => considerForIt(row)}>
                      <Lock className="h-3.5 w-3.5 mr-1.5" />Consider for IT
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
