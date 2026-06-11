import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { projects,statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/projects")({
  head: () => ({ meta: [{ title: "Projects — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 2 · Matters" title="Projects / Matters" description="End-to-end IP matter management with configurable workflows."
        actions={<><Button variant="outline">Templates</Button><Button>New Matter</Button></>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Open matters" value="142" accent="primary" />
          <StatCard label="Patent — Utility" value="78" accent="gold" />
          <StatCard label="Trademarks" value="49" accent="info" />
          <StatCard label="Copyright / Design" value="15" accent="success" />
        </div>
        <Tabs defaultValue="all">
          <TabsList><TabsTrigger value="all">All</TabsTrigger><TabsTrigger value="mine">Mine</TabsTrigger><TabsTrigger value="team">My team</TabsTrigger><TabsTrigger value="watch">Watching</TabsTrigger></TabsList>
          <TabsContent value="all">
            <Card><CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                  <th className="px-4 py-2 text-left">Matter</th><th className="px-4 py-2 text-left">Client</th><th className="px-4 py-2 text-left">Type</th>
                  <th className="px-4 py-2 text-left">Stage</th><th className="px-4 py-2 text-left">Lead</th><th className="px-4 py-2 text-left">Priority</th><th className="px-4 py-2 text-left">Due</th>
                </tr></thead>
                <tbody>{projects.map((p: any) => (
                  <tr key={p.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-3"><div className="font-medium">{p.title}</div><div className="text-xs font-mono text-muted-foreground">{p.id}</div></td>
                    <td className="px-4 py-3">{p.client}</td><td className="px-4 py-3"><Badge variant="outline">{p.type}</Badge></td>
                    <td className="px-4 py-3"><Badge variant="secondary">{p.stage}</Badge></td>
                    <td className="px-4 py-3">{p.lead}</td>
                    <td className="px-4 py-3"><Badge variant={statusColor(p.priority)}>{p.priority}</Badge></td>
                    <td className="px-4 py-3 font-mono text-xs">{p.due}</td>
                  </tr>))}</tbody>
              </table>
            </CardContent></Card>
          </TabsContent>
          <TabsContent value="mine"><Card><CardContent className="p-8 text-sm text-muted-foreground">Filtered to your assignments — 12 active matters.</CardContent></Card></TabsContent>
          <TabsContent value="team"><Card><CardContent className="p-8 text-sm text-muted-foreground">Filtered to your team — 47 active matters.</CardContent></Card></TabsContent>
          <TabsContent value="watch"><Card><CardContent className="p-8 text-sm text-muted-foreground">3 matters you are watching.</CardContent></Card></TabsContent>
        </Tabs>
      </div>
    </div>
  );
}
