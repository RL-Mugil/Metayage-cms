import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { employees,departments,statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/hrms/employees")({
  head: () => ({ meta: [{ title: "Employees — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.1 / 28.2" title="Employees & Org" description="Records, lifecycle docs, departments & locations."
        actions={<Button>Add Employee</Button>} />
      <div className="px-8 py-6 space-y-6">
        <Tabs defaultValue="emp">
          <TabsList><TabsTrigger value="emp">Employees</TabsTrigger><TabsTrigger value="dept">Departments</TabsTrigger><TabsTrigger value="org">Org Chart</TabsTrigger></TabsList>
          <TabsContent value="emp">
            <Card><CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                  <th className="px-4 py-2 text-left">ID</th><th className="px-4 py-2 text-left">Name</th>
                  <th className="px-4 py-2 text-left">Role</th><th className="px-4 py-2 text-left">Dept</th>
                  <th className="px-4 py-2 text-left">Location</th><th className="px-4 py-2 text-left">Joined</th><th className="px-4 py-2 text-left">Status</th>
                </tr></thead>
                <tbody>{employees.map((e: any) => (
                  <tr key={e.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-3 font-mono text-xs">{e.id}</td>
                    <td className="px-4 py-3"><div className="font-medium">{e.name}</div><div className="text-xs text-muted-foreground">{e.email}</div></td>
                    <td className="px-4 py-3">{e.role}</td><td className="px-4 py-3">{e.dept}</td>
                    <td className="px-4 py-3">{e.location}</td><td className="px-4 py-3 font-mono text-xs">{e.join}</td>
                    <td className="px-4 py-3"><Badge variant={statusColor(e.status)}>{e.status}</Badge></td>
                  </tr>))}</tbody>
              </table>
            </CardContent></Card>
          </TabsContent>
          <TabsContent value="dept">
            <Card><CardContent className="p-0"><table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                <th className="px-4 py-2 text-left">Department</th><th className="px-4 py-2 text-left">Head</th>
                <th className="px-4 py-2 text-right">Headcount</th><th className="px-4 py-2 text-right">Annual Budget</th>
              </tr></thead><tbody>{departments.map((d: any) => (
                <tr key={d.name} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-3 font-medium">{d.name}</td><td className="px-4 py-3">{d.head}</td>
                  <td className="px-4 py-3 text-right font-mono">{d.count}</td><td className="px-4 py-3 text-right font-mono">{d.budget}</td>
                </tr>))}</tbody></table></CardContent></Card>
          </TabsContent>
          <TabsContent value="org"><Card><CardContent className="p-8 text-sm text-muted-foreground">Interactive org chart with reporting lines, succession indicators, and inter-location transfer workflow.</CardContent></Card></TabsContent>
        </Tabs>
      </div>
    </div>
  );
}
