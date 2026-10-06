import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { Loader2, Receipt } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

function statusBadge(status: string) {
  if (status === "Paid") return "bg-green-100 text-green-700 border-green-200";
  if (status === "PartiallyPaid") return "bg-amber-100 text-amber-700 border-amber-200";
  return "bg-muted text-muted-foreground border-border";
}

const inputCls = "h-8 rounded-md border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";

export default function PayrollPortalTaxesTdsLiabilities() {
  const [liabilities, setLiabilities] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [fy, setFy] = useState(currentFiscalYear());
  const [quarter, setQuarter] = useState<string>("");
  const [status, setStatus] = useState<string>("");
  const [search, setSearch] = useState("");

  function load() {
    setLoading(true);
    setError("");
    const params: Record<string, string | number> = { financial_year: fy };
    if (quarter) params.quarter = quarter;
    if (status) params.status = status;
    api.getTdsLiabilities(params)
      .then(setLiabilities)
      .catch((e) => setError(e.message || "Failed to load TDS liabilities."))
      .finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, [fy, quarter, status]);

  const filtered = useMemo(() => {
    if (!search.trim()) return liabilities;
    const q = search.trim().toLowerCase();
    return liabilities.filter((l) =>
      (l.employee?.full_name || "").toLowerCase().includes(q) || (l.employee?.employee_code || "").toLowerCase().includes(q)
    );
  }, [liabilities, search]);

  const totals = filtered.reduce(
    (acc, l) => ({ deducted: acc.deducted + Number(l.tds_deducted), paid: acc.paid + Number(l.tds_paid) }),
    { deducted: 0, paid: 0 }
  );

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — TDS Liabilities" />
      <PageHeader eyebrow="Payroll Portal · Taxes & Forms" title="TDS Liabilities"
        description="Auto-generated per employee once a pay run is approved — tracks TDS deducted vs. paid via associated challans" />

      <div className="px-8 py-6 space-y-4">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}

        <div className="flex items-center gap-2 flex-wrap">
          <select value={fy} onChange={(e) => setFy(Number(e.target.value))} className={inputCls}>
            {[currentFiscalYear(), currentFiscalYear() - 1, currentFiscalYear() - 2].map((y) => (
              <option key={y} value={y}>FY {y}-{String(y + 1).slice(-2)}</option>
            ))}
          </select>
          <select value={quarter} onChange={(e) => setQuarter(e.target.value)} className={inputCls}>
            <option value="">All Quarters</option>
            <option value="1">Q1 (Apr-Jun)</option>
            <option value="2">Q2 (Jul-Sep)</option>
            <option value="3">Q3 (Oct-Dec)</option>
            <option value="4">Q4 (Jan-Mar)</option>
          </select>
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={inputCls}>
            <option value="">All Statuses</option>
            <option value="Open">Open</option>
            <option value="PartiallyPaid">Partially Paid</option>
            <option value="Paid">Paid</option>
          </select>
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search employee…" className={`${inputCls} flex-1 min-w-[160px]`} />
        </div>

        <Card className="border-border">
          <CardContent className="p-0">
            {loading ? (
              <div className="py-16 text-center"><Loader2 className="h-8 w-8 animate-spin text-gold mx-auto" /></div>
            ) : filtered.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
                <Receipt className="h-10 w-10 opacity-20" />
                <p className="text-sm">No TDS liabilities for this filter.</p>
              </div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Employee</th>
                    <th className="px-3 py-2.5 text-left">Period</th>
                    <th className="px-3 py-2.5 text-right">Taxable Amount</th>
                    <th className="px-3 py-2.5 text-right">TDS Deducted</th>
                    <th className="px-3 py-2.5 text-right">TDS Paid</th>
                    <th className="px-3 py-2.5 text-right">Outstanding</th>
                    <th className="px-3 py-2.5 text-left">Due Date</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((l) => {
                    const outstanding = Number(l.tds_deducted) - Number(l.tds_paid);
                    const overdue = l.status !== "Paid" && new Date(l.due_date) < new Date();
                    return (
                      <tr key={l.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5">
                          <div className="font-medium">{l.employee?.full_name}</div>
                          <div className="text-muted-foreground text-[10px]">{l.employee?.employee_code}</div>
                        </td>
                        <td className="px-3 py-2.5">{new Date(l.period).toLocaleString("default", { month: "short", year: "numeric" })}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(l.taxable_amount)}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(l.tds_deducted)}</td>
                        <td className="px-3 py-2.5 text-right text-green-600">{fmt(l.tds_paid)}</td>
                        <td className="px-3 py-2.5 text-right font-medium">{fmt(outstanding)}</td>
                        <td className={`px-3 py-2.5 ${overdue ? "text-red-500 font-medium" : ""}`}>{new Date(l.due_date).toLocaleDateString("en-IN")}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${statusBadge(l.status)}`}>{l.status}</Badge>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot>
                  <tr className="border-t border-border bg-muted/20 font-medium">
                    <td className="px-3 py-2.5" colSpan={3}>Total</td>
                    <td className="px-3 py-2.5 text-right">{fmt(totals.deducted)}</td>
                    <td className="px-3 py-2.5 text-right text-green-600">{fmt(totals.paid)}</td>
                    <td className="px-3 py-2.5 text-right">{fmt(totals.deducted - totals.paid)}</td>
                    <td colSpan={2}></td>
                  </tr>
                </tfoot>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </PayrollPortalLayout>
  );
}
