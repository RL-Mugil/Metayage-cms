import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { tasks,statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/tasks")({
  head: () => ({ meta: [{ title: "Tasks — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 5" title="Tasks" description="Hierarchical task management with time tracking and escalations."
        actions={<Button>New Task</Button>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Open" value="34" accent="primary" />
          <StatCard label="Due today" value="6" accent="gold" />
          <StatCard label="Overdue" value="2" accent="info" />
          <StatCard label="Blocked" value="3" accent="success" />
        </div>
        <Card><CardContent className="p-0">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
              <th className="px-4 py-2 text-left">Task</th><th className="px-4 py-2 text-left">Matter</th>
              <th className="px-4 py-2 text-left">Assignee</th><th className="px-4 py-2 text-left">Due</th>
              <th className="px-4 py-2 text-left">Status</th><th className="px-4 py-2 text-left">Priority</th>
            </tr></thead>
            <tbody>{tasks.map((t: any) => (
              <tr key={t.id} className="border-t border-border hover:bg-muted/30">
                <td className="px-4 py-3"><div className="font-medium">{t.title}</div><div className="text-xs font-mono text-muted-foreground">{t.id}</div></td>
                <td className="px-4 py-3 font-mono text-xs">{t.matter}</td>
                <td className="px-4 py-3">{t.assignee}</td><td className="px-4 py-3">{t.due}</td>
                <td className="px-4 py-3"><Badge variant={statusColor(t.status)}>{t.status}</Badge></td>
                <td className="px-4 py-3"><Badge variant={statusColor(t.priority)}>{t.priority}</Badge></td>
              </tr>))}</tbody>
          </table>
        </CardContent></Card>
      </div>
    </div>
  );
}
