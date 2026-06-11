import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/feedback")({
  head: () => ({ meta: [{ title: "Feedback — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 18" title="Client Satisfaction & Feedback" description="Post-project surveys, NPS, testimonials." />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-4">
        <StatCard label="NPS (12 mo)" value="64" accent="primary" />
        <StatCard label="Avg CSAT" value="4.6 / 5" accent="gold" />
        <StatCard label="Surveys sent (mo)" value="28" accent="info" />
        <StatCard label="Response rate" value="71%" accent="success" />
      </div>
      <div className="px-8 pb-8 space-y-3">{[
        { c: "Quantix Semiconductors", s: 5, t: "Anika and team were responsive and clear. Filing on time." },
        { c: "Northwind Biotech", s: 5, t: "Exceptional handling of the PCT national phase strategy." },
        { c: "Aurelia Foods", s: 4, t: "Good outcome, slightly slow on opposition response window." },
      ].map((r) => (
        <Card key={r.c}><CardContent className="p-4">
          <div className="flex justify-between"><div className="font-medium">{r.c}</div><div className="text-gold">{"★".repeat(r.s)}<span className="text-muted-foreground">{"★".repeat(5 - r.s)}</span></div></div>
          <p className="mt-1 text-sm text-muted-foreground">"{r.t}"</p>
        </CardContent></Card>))}</div>
    </div>
  );
}
