import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/approvals")({
  head: () => ({ meta: [{ title: "Approvals — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 11" title="Approval Workflow Engine" description="Document, filing, invoice & internal approvals with e-sign integration." />
      <div className="px-8 py-6 space-y-4">{[
        { t: "Specification — Helios Lithium Cell v3.2", k: "Document", a: "K. Suresh", s: "Pending" },
        { t: "Invoice INV-2026-0341 — Helios", k: "Invoice", a: "Devika Rao", s: "Pending" },
        { t: "TM-O Counter Statement — Aurelia", k: "Filing", a: "Client", s: "Awaiting Client" },
        { t: "WFH policy update", k: "Internal", a: "Lina Joseph", s: "Approved" },
      ].map((a) => (
        <Card key={a.t}><CardContent className="flex items-center justify-between p-4">
          <div><div className="font-medium text-sm">{a.t}</div><div className="text-xs text-muted-foreground">{a.k} · approver: {a.a}</div></div>
          <div className="flex items-center gap-2"><Badge variant="outline">{a.s}</Badge><Button size="sm" variant="outline">Review</Button></div>
        </CardContent></Card>))}</div>
    </div>
  );
}
