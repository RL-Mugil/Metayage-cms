import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/")({
  head: () => ({ meta: [{ title: "HRMS — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 25" title="Enterprise HRMS" description="Employee records, attendance, leave, payroll, performance, recruitment & exit." />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Headcount" value="36" accent="primary" />
          <StatCard label="Present today" value="32" accent="gold" />
          <StatCard label="On leave" value="3" accent="info" />
          <StatCard label="Open positions" value="4" accent="success" />
        </div>
        <div className="grid gap-6 lg:grid-cols-2">
          <Card><CardHeader><CardTitle className="font-display">Headcount by department</CardTitle></CardHeader>
            <CardContent className="space-y-3">{[
              ["Patents", 14], ["Trademarks", 9], ["Litigation", 6], ["Finance", 4], ["People Ops", 3],
            ].map(([d, n]: any) => (
              <div key={d}><div className="flex justify-between text-xs mb-1"><span>{d}</span><span className="font-mono">{n}</span></div>
                <div className="h-2 rounded-full bg-muted overflow-hidden"><div className="h-full bg-gradient-to-r from-primary to-gold" style={{width: (n / 14 * 100) + "%"}} /></div></div>))}</CardContent></Card>
          <Card><CardHeader><CardTitle className="font-display">Upcoming HR events</CardTitle></CardHeader>
            <CardContent className="space-y-3 text-sm">{[
              "Aarav Khanna — 60-day probation review · Jul 31",
              "Quarterly performance cycle opens · Jul 01",
              "Payroll run — June · Jun 28",
              "PF challan filing · Jul 15",
            ].map((e) => (<div key={e} className="rounded-md border border-border p-3">{e}</div>))}</CardContent></Card>
        </div>
      </div>
    </div>
  );
}
