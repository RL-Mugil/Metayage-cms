import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { documents } from "@/lib/mock-data";

export const Route = createFileRoute("/documents")({
  head: () => ({ meta: [{ title: "Documents (DMS) — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 9" title="Document Management" description="Versioned, audit-tracked vault with role-based access."
        actions={<><Button variant="outline">New folder</Button><Button>Upload</Button></>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Documents" value="12,408" accent="primary" />
          <StatCard label="Storage" value="184 GB" accent="gold" />
          <StatCard label="Pending review" value="23" accent="info" />
          <StatCard label="Locked" value="6" accent="success" />
        </div>
        <Card><CardContent className="p-0">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
              <th className="px-4 py-2 text-left">Name</th><th className="px-4 py-2 text-left">Type</th>
              <th className="px-4 py-2 text-left">Matter</th><th className="px-4 py-2 text-left">Version</th>
              <th className="px-4 py-2 text-left">Size</th><th className="px-4 py-2 text-left">Uploaded</th><th className="px-4 py-2 text-left">By</th>
            </tr></thead>
            <tbody>{documents.map((d: any) => (
              <tr key={d.id} className="border-t border-border hover:bg-muted/30">
                <td className="px-4 py-3 font-medium">{d.name}</td>
                <td className="px-4 py-3"><Badge variant="outline">{d.type}</Badge></td>
                <td className="px-4 py-3 font-mono text-xs">{d.matter}</td>
                <td className="px-4 py-3 font-mono text-xs">{d.version}</td>
                <td className="px-4 py-3">{d.size}</td><td className="px-4 py-3 text-muted-foreground">{d.uploaded}</td><td className="px-4 py-3">{d.by}</td>
              </tr>))}</tbody>
          </table>
        </CardContent></Card>
      </div>
    </div>
  );
}
