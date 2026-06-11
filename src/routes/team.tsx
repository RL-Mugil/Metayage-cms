import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { teamMembers } from "@/lib/mock-data";

export const Route = createFileRoute("/team")({
  head: () => ({ meta: [{ title: "Team Workspace — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 17" title="Internal Team Workspace" description="Shared notes, knowledge base, resource booking, internal calendar." />
      <div className="px-8 py-6 grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2"><CardHeader><CardTitle className="font-display">Recent team notes</CardTitle></CardHeader>
          <CardContent className="space-y-3">{[
            "Examiner X — typical objections under §103 and how we've addressed them",
            "Standard PCT national-phase budget memo (FY26)",
            "Trademark — recovery playbook for missed deadlines",
          ].map((n) => (<div key={n} className="rounded-md border border-border p-3 text-sm hover:bg-muted/30">{n}</div>))}</CardContent></Card>
        <Card><CardHeader><CardTitle className="font-display text-base">Workload</CardTitle></CardHeader>
          <CardContent className="space-y-3">{teamMembers.map((m: string, i: number) => (
            <div key={m}><div className="flex justify-between text-xs mb-1"><span>{m}</span><span className="font-mono">{[12, 9, 7, 14, 5][i]} matters</span></div>
              <div className="h-2 rounded-full bg-muted overflow-hidden"><div className="h-full bg-primary" style={{width: [85, 65, 50, 95, 35][i] + "%"}}></div></div></div>
          ))}</CardContent></Card>
      </div>
    </div>
  );
}
