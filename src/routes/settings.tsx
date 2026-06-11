import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/settings")({
  head: () => ({ meta: [{ title: "Settings — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 24" title="Settings & Administration" />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{[
        "Firm profile", "Branding & email templates", "Users & roles (RBAC)",
        "Stage pipelines", "Document categories", "Approval workflows",
        "Tax & financial config", "Reminder rules", "Data retention & archival",
        "Backup configuration", "API tokens", "Export center",
      ].map((s) => (
        <Card key={s}><CardHeader><CardTitle className="font-display text-base">{s}</CardTitle></CardHeader>
          <CardContent className="text-sm text-muted-foreground">Configure {s.toLowerCase()}. Changes are audit-logged.</CardContent></Card>))}</div>
    </div>
  );
}
