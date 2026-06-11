import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/discussions")({
  head: () => ({ meta: [{ title: "Discussions — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 10" title="Client Questions & Discussions" description="Threaded conversations, attachment support, FAQ promotion." />
      <div className="px-8 py-6 grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2"><CardHeader><CardTitle className="font-display">Open threads</CardTitle></CardHeader>
        <CardContent className="space-y-3">{[
          { t: "Clarification on inventor declaration", c: "Helios Robotics", r: 4, u: "10m" },
          { t: "Use of mark in Class 30 — variants", c: "Aurelia Foods", r: 7, u: "1h" },
          { t: "PCT regional choice — EP vs IN", c: "Northwind Biotech", r: 12, u: "3h" },
          { t: "Logo color variants for filing", c: "Quantix", r: 2, u: "Yesterday" },
        ].map((th) => (
          <div key={th.t} className="rounded-md border border-border p-4 hover:bg-muted/30">
            <div className="flex justify-between"><div className="font-medium text-sm">{th.t}</div><Badge variant="outline">{th.r} replies</Badge></div>
            <div className="mt-1 text-xs text-muted-foreground flex gap-2"><span>{th.c}</span><span>·</span><span>updated {th.u}</span></div>
          </div>))}</CardContent></Card>
        <Card><CardHeader><CardTitle className="font-display">Promote to FAQ</CardTitle></CardHeader>
          <CardContent className="text-sm text-muted-foreground">Resolved threads can be tagged and indexed into the internal knowledge base.</CardContent></Card>
      </div>
    </div>
  );
}
