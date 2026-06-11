import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { stages,projects } from "@/lib/mock-data";

export const Route = createFileRoute("/tracker")({
  head: () => ({ meta: [{ title: "Project Tracker — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 3" title="Project Tracker & Stage Management" description="Configurable stage pipeline with branching logic per IP type." />
      <div className="px-8 py-6 space-y-6">
        <Card><CardHeader><CardTitle className="font-display">Patent — Utility pipeline</CardTitle></CardHeader>
        <CardContent>
          <div className="flex items-center gap-1 overflow-x-auto pb-3">{stages.map((s: string, i: number) => (
            <div key={s} className="flex items-center gap-1 shrink-0">
              <div className="flex flex-col items-center gap-2">
                <div className={"h-9 w-9 rounded-full flex items-center justify-center text-xs font-semibold " + (i <= 3 ? "bg-primary text-primary-foreground" : "bg-muted text-muted-foreground")}>{i + 1}</div>
                <div className="text-xs font-medium whitespace-nowrap">{s}</div>
                <div className="text-[10px] text-muted-foreground">{[42, 28, 31, 18, 9, 8, 6][i]}</div>
              </div>
              {i < stages.length - 1 && <div className={"h-px w-12 " + (i < 3 ? "bg-primary" : "bg-border")} />}
            </div>))}</div>
        </CardContent></Card>
        <Card><CardHeader><CardTitle className="font-display">By matter</CardTitle></CardHeader>
        <CardContent className="space-y-3">{projects.map((p: any) => {
          const idx = stages.indexOf(p.stage);
          const pct = ((idx + 1) / stages.length) * 100;
          return (<div key={p.id} className="rounded-md border border-border p-4">
            <div className="flex justify-between mb-2"><div><div className="font-medium text-sm">{p.title}</div><div className="text-xs text-muted-foreground font-mono">{p.id} · {p.client}</div></div><Badge variant="secondary">{p.stage}</Badge></div>
            <div className="h-2 w-full rounded-full bg-muted overflow-hidden"><div className="h-full bg-gradient-to-r from-primary to-gold" style={{width: pct + "%"}} /></div>
          </div>);
        })}</CardContent></Card>
      </div>
    </div>
  );
}
