import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/portal")({
  head: () => ({ meta: [{ title: "Client Portal — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 7" title="Client Portal (preview)" description="What designated client contacts see when they sign in." />
      <div className="px-8 py-6 grid gap-6 md:grid-cols-3">
        {["My matters", "Documents", "Invoices", "Approvals pending", "Discussions", "Status timeline"].map((s) => (
          <Card key={s}><CardHeader><CardTitle className="font-display text-base">{s}</CardTitle></CardHeader>
            <CardContent className="text-sm text-muted-foreground">
              Read-only portal section. Activity log per contact, with email verification required before access.
            </CardContent></Card>))}
      </div>
    </div>
  );
}
