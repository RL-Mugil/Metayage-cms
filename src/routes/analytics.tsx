import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/analytics")({
  head: () => ({ meta: [{ title: "Analytics — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 13" title="Dashboards & Analytics" description="Practice, financial, operational & staff dashboards." />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Matters opened (30d)" value="22" accent="primary" />
          <StatCard label="Avg cycle time" value="118 d" accent="gold" />
          <StatCard label="Examiner response rate" value="64%" accent="info" />
          <StatCard label="Utilization" value="71%" accent="success" />
        </div>
        <div className="grid gap-6 lg:grid-cols-2">
          <Card><CardHeader><CardTitle className="font-display">Matter mix by type</CardTitle></CardHeader>
            <CardContent>
              <div className="space-y-3">{[
                { l: "Patent — Utility", v: 55, c: "bg-primary" },
                { l: "Trademark", v: 34, c: "bg-gold" },
                { l: "PCT / National Phase", v: 18, c: "bg-info" },
                { l: "Copyright / Design", v: 10, c: "bg-success" },
              ].map((b) => (
                <div key={b.l}><div className="flex justify-between text-xs mb-1"><span>{b.l}</span><span className="font-mono">{b.v}</span></div>
                  <div className="h-2 rounded-full bg-muted overflow-hidden"><div className={"h-full " + b.c} style={{width: (b.v / 55 * 100) + "%"}}></div></div>
                </div>))}</div>
            </CardContent></Card>
          <Card><CardHeader><CardTitle className="font-display">Revenue (6 mo)</CardTitle></CardHeader>
            <CardContent><div className="flex items-end gap-2 h-40">{[32, 41, 38, 49, 44, 53].map((v, i) => (
              <div key={i} className="flex-1 rounded-t-md bg-gradient-to-t from-primary to-gold" style={{height: v * 2 + "px"}} />
            ))}</div></CardContent></Card>
        </div>
      </div>
    </div>
  );
}
