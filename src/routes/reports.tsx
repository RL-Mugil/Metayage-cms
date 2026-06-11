import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/reports")({
  head: () => ({ meta: [{ title: "Reports — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 14" title="Reporting Engine" description="Standard & custom reports — PDF, Excel, CSV exports." />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{[
        { t: "Matter Pipeline", d: "Active matters by stage and aging." },
        { t: "Client Profitability", d: "Revenue, WIP, write-offs per client." },
        { t: "Staff Utilization", d: "Billable hours, realization, idle time." },
        { t: "AR Aging", d: "Receivables bucketed 0-30/30-60/60-90/90+." },
        { t: "Statutory Deadlines", d: "All upcoming office deadlines (60d)." },
        { t: "Document Audit", d: "Access log per document and folder." },
      ].map((r) => (
        <Card key={r.t}><CardHeader><CardTitle className="font-display text-base">{r.t}</CardTitle></CardHeader>
          <CardContent className="text-sm text-muted-foreground space-y-3"><p>{r.d}</p>
            <div className="flex gap-2"><Button size="sm" variant="outline">PDF</Button><Button size="sm" variant="outline">Excel</Button><Button size="sm" variant="ghost">Schedule</Button></div>
          </CardContent></Card>))}</div>
    </div>
  );
}
