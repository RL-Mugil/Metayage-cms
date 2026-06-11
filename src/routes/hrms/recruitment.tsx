import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/recruitment")({
  head: () => ({ meta: [{ title: "Recruitment — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.7" title="Recruitment & Onboarding"
        actions={<Button>New Requisition</Button>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Open roles" value="4" accent="primary" />
          <StatCard label="Active candidates" value="38" accent="gold" />
          <StatCard label="Offers out" value="3" accent="info" />
          <StatCard label="Onboarding" value="2" accent="success" />
        </div>
        <Card><CardHeader><CardTitle className="font-display">Open requisitions</CardTitle></CardHeader>
          <CardContent className="p-0"><table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
              <th className="px-4 py-2 text-left">Role</th><th className="px-4 py-2 text-left">Department</th>
              <th className="px-4 py-2 text-left">Location</th><th className="px-4 py-2 text-right">Candidates</th><th className="px-4 py-2 text-left">Stage</th>
            </tr></thead><tbody>{[
              ["Senior Patent Associate", "Patents", "Bengaluru", 12, "Interview"],
              ["Trademark Paralegal", "Trademarks", "Mumbai", 14, "Screening"],
              ["Finance Analyst", "Finance", "Bengaluru", 8, "Offer"],
              ["IT Administrator", "People Ops", "Remote", 4, "Sourcing"],
            ].map((r, i) => (
              <tr key={i} className="border-t border-border hover:bg-muted/30">
                <td className="px-4 py-3 font-medium">{r[0]}</td><td className="px-4 py-3">{r[1]}</td>
                <td className="px-4 py-3">{r[2]}</td><td className="px-4 py-3 text-right font-mono">{r[3]}</td>
                <td className="px-4 py-3"><Badge variant="secondary">{r[4]}</Badge></td>
              </tr>))}</tbody></table></CardContent></Card>
      </div>
    </div>
  );
}
