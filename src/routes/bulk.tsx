import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/bulk")({
  head: () => ({ meta: [{ title: "Bulk Operations — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 19" title="Bulk Operations Center" description="Email, invoicing, status updates, document tagging in batch." />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{[
        "Bulk email (clients / staff)", "Bulk invoice generation", "Bulk mark-as-paid (reconciliation)",
        "Bulk status update (matters)", "Bulk document tagging", "Bulk timesheet submission",
      ].map((b) => (
        <Card key={b}><CardHeader><CardTitle className="font-display text-base">{b}</CardTitle></CardHeader>
          <CardContent className="text-sm text-muted-foreground">Select rows from any data grid; preview operation; confirm; audit log captures the run.</CardContent></Card>))}</div>
    </div>
  );
}
