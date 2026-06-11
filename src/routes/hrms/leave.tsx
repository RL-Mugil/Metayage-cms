import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/leave")({
  head: () => ({ meta: [{ title: "Leave — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.4" title="Leave Management" description="Casual, Sick, Earned, Maternity/Paternity, Comp-off." actions={<Button>Apply Leave</Button>} />
      <div className="px-8 py-6 space-y-6">
        <Card><CardHeader><CardTitle className="font-display">Leave balances (Anika Mehra)</CardTitle></CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-5">{[
            ["Casual", 12, 4], ["Sick", 10, 2], ["Earned", 18, 9], ["Comp-off", 4, 1], ["Maternity", 0, 0],
          ].map(([t, total, used]: any) => (
            <div key={t} className="rounded-md border border-border p-4">
              <div className="text-xs uppercase tracking-wider text-muted-foreground">{t}</div>
              <div className="mt-2 font-display text-2xl font-semibold">{total - used}<span className="text-sm text-muted-foreground"> / {total}</span></div>
              <div className="mt-2 h-1.5 rounded-full bg-muted overflow-hidden"><div className="h-full bg-gold" style={{width: total ? (used / total * 100) + "%" : "0%"}} /></div>
            </div>))}</CardContent></Card>
        <Card><CardHeader><CardTitle className="font-display">Pending approvals</CardTitle></CardHeader>
          <CardContent className="p-0"><table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
              <th className="px-4 py-2 text-left">Employee</th><th className="px-4 py-2 text-left">Type</th>
              <th className="px-4 py-2 text-left">From</th><th className="px-4 py-2 text-left">To</th>
              <th className="px-4 py-2 text-left">Days</th><th className="px-4 py-2 text-left">Action</th>
            </tr></thead><tbody>{[
              ["Ravi Nair", "Earned", "Jun 12", "Jun 15", 4],
              ["Maya Bhat", "Sick", "Jun 04", "Jun 04", 1],
              ["Aarav Khanna", "Casual", "Jun 20", "Jun 20", 1],
            ].map((r, i) => (
              <tr key={i} className="border-t border-border"><td className="px-4 py-3">{r[0]}</td><td className="px-4 py-3"><Badge variant="outline">{r[1]}</Badge></td>
              <td className="px-4 py-3 font-mono text-xs">{r[2]}</td><td className="px-4 py-3 font-mono text-xs">{r[3]}</td>
              <td className="px-4 py-3 font-mono">{r[4]}</td><td className="px-4 py-3"><Button size="sm" variant="outline" className="mr-2">Approve</Button><Button size="sm" variant="ghost">Reject</Button></td></tr>
            ))}</tbody></table></CardContent></Card>
      </div>
    </div>
  );
}
