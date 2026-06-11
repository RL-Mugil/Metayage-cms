import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/payroll")({
  head: () => ({ meta: [{ title: "Payroll — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.5" title="Payroll" description="Structures, runs, TDS, PF/ESI, payslips, Form 16."
        actions={<Button>Run Payroll</Button>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Last run" value="May 2026" accent="primary" />
          <StatCard label="Net payout" value="₹ 84.2L" accent="gold" />
          <StatCard label="TDS deducted" value="₹ 12.8L" accent="info" />
          <StatCard label="PF + ESI" value="₹ 6.4L" accent="success" />
        </div>
        <Card><CardHeader><CardTitle className="font-display">Salary breakdown (sample — Anika Mehra)</CardTitle></CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">{[
              ["Basic", "₹ 75,000"], ["HRA", "₹ 30,000"], ["Special allowance", "₹ 25,000"], ["LTA", "₹ 5,000"],
            ].map(([k, v]) => (<div key={k} className="flex justify-between text-sm border-b border-border py-2"><span className="text-muted-foreground">{k}</span><span className="font-mono">{v}</span></div>))}</div>
            <div className="space-y-2">{[
              ["PF (employee)", "₹ 9,000"], ["Professional tax", "₹ 200"], ["TDS", "₹ 11,400"], ["Net pay", "₹ 1,14,400"],
            ].map(([k, v]) => (<div key={k} className="flex justify-between text-sm border-b border-border py-2"><span className="text-muted-foreground">{k}</span><span className="font-mono">{v}</span></div>))}</div>
          </CardContent></Card>
      </div>
    </div>
  );
}
