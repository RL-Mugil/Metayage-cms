import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/offboarding")({
  head: () => ({ meta: [{ title: "Offboarding — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.8" title="Offboarding & Exit" description="Resignation workflow, clearance, F&F settlement." />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-2">{[
        { n: "Sara Iyer", role: "Associate (Patents)", last: "Jun 30", stage: "Clearance" },
        { n: "Vishal Sahni", role: "Paralegal (TM)", last: "Jul 15", stage: "Notice" },
      ].map((p) => (
        <Card key={p.n}><CardHeader><CardTitle className="font-display text-base">{p.n}</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">
            <div className="text-muted-foreground">{p.role} · Last day: {p.last}</div>
            <Badge variant="secondary">{p.stage}</Badge>
            <div className="grid gap-2">{["Exit interview", "IT asset return", "Knowledge handover", "F&F settlement", "Relieving letter"].map((s, i) => (
              <div key={s} className="flex items-center justify-between rounded-md border border-border p-2 text-xs">
                <span>{s}</span><Badge variant={i < 2 ? "default" : "outline"}>{i < 2 ? "Done" : "Pending"}</Badge>
              </div>))}</div>
          </CardContent></Card>))}</div>
    </div>
  );
}
