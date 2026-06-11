import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/notifications")({
  head: () => ({ meta: [{ title: "Notifications — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 12" title="Real-Time Notifications" description="In-app, email, SMS, push. Quiet hours 8 PM — 8 AM unless critical." />
      <div className="px-8 py-6 space-y-3">{[
        { t: "Office Action received — M-2057", ch: "Email · In-app", time: "12m ago", c: "destructive" },
        { t: "Document approved by partner — D-44021", ch: "In-app", time: "30m ago", c: "default" },
        { t: "New comment from client (Aurelia)", ch: "Email · In-app", time: "2h ago", c: "secondary" },
        { t: "Annuity reminder — M-2031 due in 53 days", ch: "In-app", time: "Today", c: "outline" },
      ].map((n) => (
        <div key={n.t} className="flex items-center gap-3 rounded-md border border-border bg-card p-3">
          <span className={"h-2 w-2 rounded-full " + (n.c === "destructive" ? "bg-destructive" : "bg-gold")}></span>
          <div className="flex-1"><div className="text-sm font-medium">{n.t}</div><div className="text-xs text-muted-foreground">{n.ch} · {n.time}</div></div>
          <Button variant="ghost" size="sm">Mark read</Button>
        </div>))}</div>
    </div>
  );
}
