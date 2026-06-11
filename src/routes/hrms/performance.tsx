import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/performance")({
  head: () => ({ meta: [{ title: "Performance — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.6" title="Performance Management" description="OKR / KPI, 360° reviews, calibration, career pathing." />
      <div className="px-8 py-6 grid gap-6 lg:grid-cols-2">
        <Card><CardHeader><CardTitle className="font-display">Cycle: H1 2026</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">{[
            ["Goals set", "92%"], ["Mid-year reviews", "68%"], ["Self-assessments", "54%"], ["Manager reviews", "22%"],
          ].map(([k, v]: any) => (
            <div key={k}><div className="flex justify-between text-xs mb-1"><span>{k}</span><span className="font-mono">{v}</span></div>
              <div className="h-2 rounded-full bg-muted overflow-hidden"><div className="h-full bg-primary" style={{width: v}} /></div></div>
          ))}</CardContent></Card>
        <Card><CardHeader><CardTitle className="font-display">Top performers (last cycle)</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">{[
            ["Anika Mehra", "4.8 / 5"], ["K. Suresh", "4.7 / 5"], ["Devika Rao", "4.6 / 5"], ["Ravi Nair", "4.4 / 5"],
          ].map(([n, r]: any) => (<div key={n} className="flex justify-between rounded-md border border-border p-3"><span>{n}</span><span className="font-mono text-gold">{r}</span></div>))}</CardContent></Card>
      </div>
    </div>
  );
}
