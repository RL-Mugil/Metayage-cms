import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { projects } from "@/lib/mock-data";

export const Route = createFileRoute("/timeline")({
  head: () => ({ meta: [{ title: "Timeline — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 8" title="Project Timeline & Milestones" />
      <div className="px-8 py-6">
        <Card><CardContent className="p-6 space-y-6">{projects.slice(0,4).map((p: any, i: number) => (
          <div key={p.id} className="space-y-2">
            <div className="flex justify-between"><div><div className="font-medium">{p.title}</div><div className="text-xs text-muted-foreground font-mono">{p.id}</div></div><Badge variant="secondary">{p.stage}</Badge></div>
            <div className="relative h-10 rounded-md bg-muted/40 overflow-hidden">
              <div className="absolute inset-y-0 left-0 bg-gradient-to-r from-primary to-gold/70" style={{width: [35, 55, 78, 22][i] + "%"}}></div>
              <div className="absolute inset-0 flex items-center justify-between px-3 text-xs text-foreground/80"><span>Filed</span><span>Examination</span><span>Decision</span></div>
            </div>
          </div>))}</CardContent></Card>
      </div>
    </div>
  );
}
