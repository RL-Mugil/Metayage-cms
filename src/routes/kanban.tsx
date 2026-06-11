import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { projects,statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/kanban")({
  head: () => ({ meta: [{ title: "Kanban — IPFlow" }] }),
  component: Page,
});

function Page() {
  const cols = ["Pre-Filing", "Drafting", "Filing", "Examination", "Opposition", "Registered"];
  return (
    <div>
      <PageHeader eyebrow="Module 4" title="Kanban Board"
        actions={<><Button variant="outline">My board</Button><Button variant="outline">Team board</Button><Button>+ Card</Button></>} />
      <div className="px-8 py-6">
        <div className="grid gap-4" style={{gridTemplateColumns: "repeat(6, minmax(220px, 1fr))"}}>
          {cols.map((col: string) => {
            const items = projects.filter((p: any) => p.stage === col);
            return (<div key={col} className="rounded-lg bg-muted/40 p-3">
              <div className="flex items-center justify-between mb-3"><div className="font-medium text-sm">{col}</div><Badge variant="outline">{items.length}</Badge></div>
              <div className="space-y-2">{items.map((p: any) => (
                <Card key={p.id} className="border-border shadow-none hover:shadow-sm transition cursor-grab">
                  <CardContent className="p-3 space-y-2">
                    <div className="text-[10px] font-mono text-muted-foreground">{p.id}</div>
                    <div className="text-sm font-medium leading-snug">{p.title}</div>
                    <div className="text-xs text-muted-foreground">{p.client}</div>
                    <div className="flex items-center justify-between pt-1"><Badge variant={statusColor(p.priority)} className="text-[10px]">{p.priority}</Badge><div className="text-[10px] text-muted-foreground font-mono">{p.due}</div></div>
                  </CardContent>
                </Card>))}
              </div>
            </div>);
          })}
        </div>
      </div>
    </div>
  );
}
