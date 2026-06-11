import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/compliance")({
  head: () => ({ meta: [{ title: "Compliance — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 21" title="Governance, Compliance & Audit" description="Immutable audit trail (7-year retention), permission audits, data handling logs." />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Audit events (24h)" value="3,184" accent="primary" />
          <StatCard label="High-risk events" value="2" accent="gold" />
          <StatCard label="Retention policy" value="7 years" accent="info" />
          <StatCard label="Compliance score" value="98%" accent="success" />
        </div>
        <Card><CardHeader><CardTitle className="font-display">Activity log</CardTitle></CardHeader>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                <th className="px-4 py-2 text-left">Time</th><th className="px-4 py-2 text-left">Actor</th><th className="px-4 py-2 text-left">Action</th><th className="px-4 py-2 text-left">Subject</th><th className="px-4 py-2 text-left">IP</th>
              </tr></thead>
              <tbody>{[
                ["12:14:08", "Anika Mehra", "document.download", "D-44021", "10.0.4.12"],
                ["12:11:42", "K. Suresh", "approval.grant", "DOC-APP-9012", "10.0.4.6"],
                ["11:58:11", "Ravi Nair", "matter.update", "M-2054", "10.0.4.21"],
                ["11:33:09", "Devika Rao", "invoice.send", "INV-2026-0341", "10.0.4.18"],
              ].map((r, i) => (
                <tr key={i} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-3 font-mono text-xs">{r[0]}</td><td className="px-4 py-3">{r[1]}</td>
                  <td className="px-4 py-3"><Badge variant="outline">{r[2]}</Badge></td>
                  <td className="px-4 py-3 font-mono text-xs">{r[3]}</td><td className="px-4 py-3 text-muted-foreground font-mono text-xs">{r[4]}</td>
                </tr>))}</tbody>
            </table>
          </CardContent></Card>
      </div>
    </div>
  );
}
