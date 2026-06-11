import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/reminders")({
  head: () => ({ meta: [{ title: "Reminders — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 6" title="Reminder & Notification Engine" description="Statutory deadlines, renewals, response windows, internal SLAs."
        actions={<Button>New rule</Button>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Active rules" value="84" accent="primary" />
          <StatCard label="Triggered today" value="12" accent="gold" />
          <StatCard label="Snoozed" value="5" accent="info" />
          <StatCard label="Escalated" value="1" accent="success" />
        </div>
        <Card><CardHeader><CardTitle className="font-display">Upcoming statutory deadlines</CardTitle></CardHeader>
        <CardContent className="space-y-3">{[
          { d: "Jun 09", t: "Form 18 — Request for Examination", m: "M-2057", s: "T-2d" },
          { d: "Jun 12", t: "TM-O Counter Statement window closes", m: "M-2054", s: "T-5d" },
          { d: "Jun 28", t: "PCT national-phase entry deadline", m: "M-2049", s: "T-21d" },
          { d: "Jul 30", t: "Annuity payment — Year 4", m: "M-2031", s: "T-53d" },
        ].map((r) => (
          <div key={r.t} className="flex items-center gap-4 rounded-md border border-border p-3">
            <div className="w-16 text-center"><div className="font-display text-lg font-semibold">{r.d.split(" ")[1]}</div><div className="text-[10px] uppercase text-muted-foreground">{r.d.split(" ")[0]}</div></div>
            <div className="flex-1"><div className="font-medium text-sm">{r.t}</div><div className="text-xs text-muted-foreground font-mono">{r.m}</div></div>
            <Badge variant="outline" className="border-gold/40 text-gold">{r.s}</Badge>
            <Button variant="ghost" size="sm">Snooze</Button>
          </div>))}</CardContent></Card>
      </div>
    </div>
  );
}
