import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Wallet, Users, TrendingUp, ShieldCheck } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api-client";

const fmt = (n: number) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function periodLabel(period: string): string {
  return new Date(`${period}-01`).toLocaleString("default", { month: "long", year: "numeric" });
}

export default function PayrollPortalDashboard() {
  const [data, setData] = useState<Awaited<ReturnType<typeof api.getPayrollPortalDashboard>> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    api.getPayrollPortalDashboard()
      .then(setData)
      .catch((e) => setError(e.message || "Failed to load dashboard."))
      .finally(() => setLoading(false));
  }, []);

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Dashboard" />
      <PageHeader eyebrow="Payroll Portal" title="Dashboard" description="Pay runs, statutory contributions, and cost trends at a glance" />

      <div className="px-8 py-6 space-y-6">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : data && (
          <>
            <div className="grid grid-cols-4 gap-4">
              <Card className="border-border">
                <CardContent className="p-4">
                  <div className="flex items-center gap-2 mb-2"><Wallet className="h-4 w-4 text-gold" /><span className="text-xs text-muted-foreground">Upcoming Pay Run</span></div>
                  <div className="text-xl font-bold">{periodLabel(data.upcoming_run.period)}</div>
                  <div className="text-xs text-muted-foreground mt-0.5">{data.upcoming_run.already_created ? "Already created" : "Not yet created"}</div>
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="p-4">
                  <div className="flex items-center gap-2 mb-2"><Users className="h-4 w-4 text-gold" /><span className="text-xs text-muted-foreground">Active Employees</span></div>
                  <div className="text-xl font-bold">{data.employee_count}</div>
                  <div className="text-xs text-muted-foreground mt-0.5">On active payroll</div>
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="p-4">
                  <div className="flex items-center gap-2 mb-2"><TrendingUp className="h-4 w-4 text-gold" /><span className="text-xs text-muted-foreground">Latest Run Net</span></div>
                  <div className="text-xl font-bold">{data.latest_run ? fmt(data.latest_run.net_total) : "—"}</div>
                  <div className="text-xs text-muted-foreground mt-0.5">{data.latest_run ? periodLabel(String(data.latest_run.period).slice(0, 7)) : "No runs yet"}</div>
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="p-4">
                  <div className="flex items-center gap-2 mb-2"><ShieldCheck className="h-4 w-4 text-gold" /><span className="text-xs text-muted-foreground">YTD TDS</span></div>
                  <div className="text-xl font-bold">{fmt(data.ytd.tds)}</div>
                  <div className="text-xs text-muted-foreground mt-0.5">Calendar {new Date().getFullYear()}</div>
                </CardContent>
              </Card>
            </div>

            <div className="grid grid-cols-2 gap-6">
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display text-base">Benefits and Deductions (YTD)</CardTitle></CardHeader>
                <CardContent className="space-y-2 text-sm">
                  <div className="flex justify-between"><span className="text-muted-foreground">Income Tax (TDS)</span><span className="font-medium">{fmt(data.ytd.tds)}</span></div>
                  <div className="flex justify-between border-t border-border pt-2 mt-2"><span className="text-muted-foreground">Total Deductions</span><span className="font-semibold">{fmt(data.ytd.deductions)}</span></div>
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display text-base">Payroll Cost Summary (last 6 runs)</CardTitle></CardHeader>
                <CardContent>
                  {data.cost_trend.length === 0 ? (
                    <p className="text-xs text-muted-foreground text-center py-8">No payroll runs yet.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead className="text-muted-foreground">
                        <tr><th className="text-left py-1">Period</th><th className="text-right py-1">Gross</th><th className="text-right py-1">Deductions</th><th className="text-right py-1">Net</th></tr>
                      </thead>
                      <tbody>
                        {data.cost_trend.map((row) => (
                          <tr key={row.period} className="border-t border-border">
                            <td className="py-1.5">{row.period}</td>
                            <td className="py-1.5 text-right">{fmt(row.gross)}</td>
                            <td className="py-1.5 text-right text-red-500">-{fmt(row.deductions)}</td>
                            <td className="py-1.5 text-right text-green-600 font-medium">{fmt(row.net)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </CardContent>
              </Card>
            </div>
          </>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
