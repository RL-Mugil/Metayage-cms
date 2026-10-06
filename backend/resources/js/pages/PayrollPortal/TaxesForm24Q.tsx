import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Loader2, AlertTriangle, FileDown, CheckCircle2 } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

const inputCls = "h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

export default function PayrollPortalTaxesForm24Q() {
  const [fy, setFy] = useState(currentFiscalYear());
  const [quarter, setQuarter] = useState(1);
  const [checking, setChecking] = useState(false);
  const [checked, setChecked] = useState(false);
  const [exceptions, setExceptions] = useState<{ employee_code: string; employee_name: string; issue: string }[]>([]);
  const [error, setError] = useState("");

  async function checkIssues() {
    setChecking(true);
    setError("");
    setChecked(false);
    try {
      const rows = await api.getForm24QExceptions(fy, quarter);
      setExceptions(rows);
      setChecked(true);
    } catch (e: any) {
      setError(e.message || "Failed to check for issues.");
    } finally {
      setChecking(false);
    }
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Form 24Q" />
      <PageHeader eyebrow="Payroll Portal · Taxes & Forms" title="Form 24Q"
        description="Quarterly TDS return data — Challan Details, Deductee Annexure, and Exceptions, for filing via the government's RPU/FVU tool" />

      <div className="px-8 py-6 space-y-4 max-w-2xl">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}

        <Card className="border-border">
          <CardContent className="p-6 space-y-4">
            <div className="flex items-center gap-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Financial Year</label>
                <select value={fy} onChange={(e) => { setFy(Number(e.target.value)); setChecked(false); }} className={inputCls}>
                  {[currentFiscalYear(), currentFiscalYear() - 1].map((y) => <option key={y} value={y}>FY {y}-{String(y + 1).slice(-2)}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Quarter</label>
                <select value={quarter} onChange={(e) => { setQuarter(Number(e.target.value)); setChecked(false); }} className={inputCls}>
                  <option value={1}>Q1 (Apr-Jun)</option>
                  <option value={2}>Q2 (Jul-Sep)</option>
                  <option value={3}>Q3 (Oct-Dec)</option>
                  <option value={4}>Q4 (Jan-Mar)</option>
                </select>
              </div>
            </div>

            <Button variant="outline" onClick={checkIssues} disabled={checking}>
              {checking ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <AlertTriangle className="h-4 w-4 mr-2" />}Check for Issues
            </Button>

            {checked && (
              exceptions.length === 0 ? (
                <div className="flex items-center gap-2 rounded-md border border-green-500/40 bg-green-500/10 px-3 py-2 text-sm text-green-500">
                  <CheckCircle2 className="h-4 w-4" />No issues found for this quarter.
                </div>
              ) : (
                <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3">
                  <p className="text-xs font-medium text-amber-600 mb-2">{exceptions.length} issue(s) found — export will still include these rows:</p>
                  <ul className="space-y-1 text-xs text-amber-700">
                    {exceptions.map((ex, i) => (
                      <li key={i}>{ex.employee_name} ({ex.employee_code}) — {ex.issue}</li>
                    ))}
                  </ul>
                </div>
              )
            )}

            <a href={api.form24QDownloadUrl(fy, quarter)} target="_blank" rel="noreferrer">
              <Button className="bg-gold hover:bg-gold/90 text-black">
                <FileDown className="h-4 w-4 mr-2" />Generate & Download
              </Button>
            </a>
          </CardContent>
        </Card>
      </div>
    </PayrollPortalLayout>
  );
}
