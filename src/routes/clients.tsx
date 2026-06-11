import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/clients")({
  head: () => ({ meta: [{ title: "Clients — IPFlow" }] }),
  component: Page,
});

import { useEffect, useState } from "react";
import { api } from "@/lib/api-client";
import { Loader2 } from "lucide-react";

function Page() {
  const [liveClients, setLiveClients] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  const fetchClients = async (query = "") => {
    try {
      const data = await api.getClients(query);
      setLiveClients(data);
    } catch (err) {
      console.error("Error loading clients:", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchClients();
  }, []);

  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setSearch(val);
    fetchClients(val);
  };

  return (
    <div>
      <PageHeader eyebrow="Module 1 · CRM" title="Clients" description="Metayage's assigned internal clients. No external onboarding."
        actions={<><Button variant="outline">Import CSV</Button><Button>New Client</Button></>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Total clients" value={liveClients.length.toString()} accent="primary" />
          <StatCard label="Strategic accounts" value={liveClients.filter(c => c.sla_tier === "Enterprise").length.toString()} accent="gold" />
          <StatCard label="Premium tier" value={liveClients.filter(c => c.sla_tier === "Premium").length.toString()} accent="info" />
          <StatCard label="Active Status" value={liveClients.filter(c => c.status === "Active").length.toString()} accent="success" />
        </div>
        
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="font-display">Directory</CardTitle>
            <div className="flex gap-2">
              <Input 
                placeholder="Search clients" 
                className="w-64" 
                value={search}
                onChange={handleSearchChange}
              />
              <Button variant="outline">Filters</Button>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-12">
                <Loader2 className="h-6 w-6 animate-spin text-gold" />
              </div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                  <th className="px-4 py-2 text-left">Code</th><th className="px-4 py-2 text-left">Name</th><th className="px-4 py-2 text-left">Type</th>
                  <th className="px-4 py-2 text-left">Primary contact</th><th className="px-4 py-2 text-left">Country</th>
                  <th className="px-4 py-2 text-right">SLA Tier</th><th className="px-4 py-2 text-right">Credit Limit</th><th className="px-4 py-2 text-left">Status</th>
                </tr></thead>
                <tbody>{liveClients.map((c: any) => {
                  const contact = c.contacts?.[0]?.name || "N/A";
                  return (
                    <tr key={c.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{c.client_code}</td>
                      <td className="px-4 py-3 font-medium">{c.company_name}</td>
                      <td className="px-4 py-3"><Badge variant="outline">{c.entity_type}</Badge></td>
                      <td className="px-4 py-3">{contact}</td>
                      <td className="px-4 py-3 font-mono">{c.primary_jurisdiction}</td>
                      <td className="px-4 py-3 text-right font-medium text-amber-500">{c.sla_tier}</td>
                      <td className="px-4 py-3 text-right font-mono">₹ {parseFloat(c.credit_limit || 0).toLocaleString()}</td>
                      <td className="px-4 py-3"><Badge variant={statusColor(c.status)}>{c.status}</Badge></td>
                    </tr>
                  );
                })}</tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
