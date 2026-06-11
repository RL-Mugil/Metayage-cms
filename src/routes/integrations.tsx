import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/integrations")({
  head: () => ({ meta: [{ title: "Integrations — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 22" title="Integrations" />
      <div className="px-8 py-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{[
        { n: "SMTP / IMAP", c: "Connected", s: "default" },
        { n: "Outlook / Microsoft 365", c: "Connected", s: "default" },
        { n: "Google Workspace", c: "Disconnected", s: "outline" },
        { n: "USPTO Private PAIR", c: "Connected", s: "default" },
        { n: "EPO Online Filing", c: "Pending", s: "secondary" },
        { n: "WIPO ePCT", c: "Connected", s: "default" },
        { n: "INPO (India)", c: "Connected", s: "default" },
        { n: "DocuSign / Adobe Sign", c: "Connected", s: "default" },
        { n: "Payment Gateway", c: "Disconnected", s: "outline" },
      ].map((i) => (
        <Card key={i.n}><CardContent className="flex items-center justify-between p-4">
          <div><div className="font-medium text-sm">{i.n}</div><div className="text-xs text-muted-foreground">Last sync: 6 min ago</div></div>
          <Badge variant={i.s as any}>{i.c}</Badge>
        </CardContent></Card>))}</div>
    </div>
  );
}
