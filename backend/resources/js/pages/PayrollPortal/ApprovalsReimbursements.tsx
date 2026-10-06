import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Check, X } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

export default function PayrollPortalApprovalsReimbursements() {
  const [claims, setClaims] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState("");

  function load() {
    setLoading(true);
    api.getPendingReimbursements().then(setClaims).catch((e) => setError(e.message || "Failed to load.")).finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  async function resolve(id: number, action: "Approved" | "Rejected") {
    setBusyId(id);
    setError("");
    try {
      await api.resolveApproval("Expense", id, action);
      setClaims((prev) => prev.filter((c) => c.id !== id));
    } catch (e: any) {
      setError(e.message || "Failed to resolve claim.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Reimbursements" />
      <PageHeader eyebrow="Payroll Portal · Approvals" title="Reimbursements" description="Pending employee reimbursement claims" />

      <div className="px-8 py-6 space-y-4">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              {claims.length === 0 ? (
                <p className="text-xs text-muted-foreground text-center py-10">No pending reimbursement claims.</p>
              ) : (
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2.5 text-left">Employee</th>
                      <th className="px-3 py-2.5 text-left">Type</th>
                      <th className="px-3 py-2.5 text-right">Amount</th>
                      <th className="px-3 py-2.5 text-left">Date</th>
                      <th className="px-3 py-2.5 text-left">Description</th>
                      <th className="px-3 py-2.5 text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {claims.map((c) => (
                      <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5">
                          <div className="font-medium">{c.employee?.full_name}</div>
                          <div className="text-muted-foreground text-[10px]">{c.employee?.employee_code}</div>
                        </td>
                        <td className="px-3 py-2.5">{c.reimbursement_type?.name || "—"}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(c.amount)}</td>
                        <td className="px-3 py-2.5 text-muted-foreground">
                          {new Date(c.claim_date).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })}
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground">{c.description || "—"}</td>
                        <td className="px-3 py-2.5 text-center whitespace-nowrap">
                          <Button size="sm" className="h-6 px-2 text-[10px] bg-green-600 hover:bg-green-700 text-white mr-1" disabled={busyId === c.id} onClick={() => resolve(c.id, "Approved")}>
                            <Check className="h-3 w-3" />
                          </Button>
                          <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] border-red-200 text-red-600" disabled={busyId === c.id} onClick={() => resolve(c.id, "Rejected")}>
                            <X className="h-3 w-3" />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
