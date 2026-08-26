import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { ZohoBooksPanel } from "@/components/zoho-books-panel";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";

interface Props {
  clientId: number;
}

export default function ClientShow({ clientId }: Props) {
  const [client, setClient] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getClient(clientId).then((data) => {
      setClient(data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [clientId]);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Client" />
        <div className="flex items-center justify-center h-screen">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title={client?.company_name || "Client"} />
      <PageHeader
        eyebrow="CRM"
        title={client?.company_name || "Client"}
        description={`${client?.client_code} · ${client?.entity_type}`}
        actions={<Badge variant={statusColor(client?.status)}>{client?.status}</Badge>}
      />
      <div className="px-8 py-6 grid gap-6 lg:grid-cols-3">
        <Card className="border-border">
          <CardHeader><CardTitle className="font-display">Client Details</CardTitle></CardHeader>
          <CardContent className="space-y-3 text-sm">
            <div className="flex justify-between"><span className="text-muted-foreground">Industry</span><span>{client?.industry || "—"}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">Jurisdiction</span><span className="font-mono">{client?.primary_jurisdiction || "—"}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">Credit Limit</span><span>{client?.credit_limit ? `₹ ${client.credit_limit.toLocaleString()}` : "—"}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">Payment Terms</span><span>{client?.payment_terms || "—"}</span></div>
            <div className="flex justify-between"><span className="text-muted-foreground">Status</span><Badge variant="outline">{client?.status || "Active"}</Badge></div>
          </CardContent>
        </Card>
        <Card className="lg:col-span-2 border-border">
          <CardHeader><CardTitle className="font-display">Contacts</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            {client?.contacts?.map((c: any) => (
              <div key={c.id} className="flex items-center justify-between rounded-md border border-border p-3">
                <div>
                  <div className="font-medium text-sm">{c.name}</div>
                  <div className="text-xs text-muted-foreground">{c.email} · {c.phone || "—"}</div>
                </div>
                <Badge variant="outline">{c.role_type || "Contact"}</Badge>
              </div>
            ))}
          </CardContent>
        </Card>
        <div className="lg:col-span-3">
          <ZohoBooksPanel fetchSummary={() => api.getZohoClientSummary(clientId)} />
        </div>
      </div>
    </AppLayout>
  );
}
