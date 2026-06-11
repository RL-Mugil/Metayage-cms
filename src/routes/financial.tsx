import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import { invoices,statusColor } from "@/lib/mock-data";

export const Route = createFileRoute("/financial")({
  head: () => ({ meta: [{ title: "Financial Suite — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 15" title="Financial Suite" description="Quotations, proformas, tax invoices, payments, WIP & ledgers."
        actions={<><Button variant="outline">Quotation</Button><Button>New Invoice</Button></>} />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="MTD Revenue" value="₹ 42.7L" accent="primary" />
          <StatCard label="AR Outstanding" value="₹ 28.1L" accent="gold" />
          <StatCard label="WIP value" value="₹ 18.4L" accent="info" />
          <StatCard label="Avg DSO" value="38 d" accent="success" />
        </div>
        <Tabs defaultValue="inv">
          <TabsList>
            <TabsTrigger value="inv">Invoices</TabsTrigger><TabsTrigger value="quo">Quotations</TabsTrigger>
            <TabsTrigger value="pay">Payments</TabsTrigger><TabsTrigger value="time">Time entries</TabsTrigger><TabsTrigger value="exp">Expenses</TabsTrigger>
          </TabsList>
          <TabsContent value="inv">
            <Card><CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
                  <th className="px-4 py-2 text-left">Invoice</th><th className="px-4 py-2 text-left">Client</th>
                  <th className="px-4 py-2 text-right">Amount</th><th className="px-4 py-2 text-left">Due</th><th className="px-4 py-2 text-left">Status</th>
                </tr></thead>
                <tbody>{invoices.map((i: any) => (
                  <tr key={i.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-3 font-mono text-xs">{i.id}</td><td className="px-4 py-3">{i.client}</td>
                    <td className="px-4 py-3 text-right font-mono">{i.amount}</td>
                    <td className="px-4 py-3 font-mono text-xs">{i.due}</td>
                    <td className="px-4 py-3"><Badge variant={statusColor(i.status)}>{i.status}</Badge></td>
                  </tr>))}</tbody>
              </table>
            </CardContent></Card>
          </TabsContent>
          <TabsContent value="quo"><Card><CardContent className="p-8 text-sm text-muted-foreground">12 quotations in draft, 4 sent. Default validity 30 days.</CardContent></Card></TabsContent>
          <TabsContent value="pay"><Card><CardContent className="p-8 text-sm text-muted-foreground">Payment ledger with reconciliation, refunds & credit notes.</CardContent></Card></TabsContent>
          <TabsContent value="time"><Card><CardContent className="p-8 text-sm text-muted-foreground">Timesheets with approval workflow (associate → partner).</CardContent></Card></TabsContent>
          <TabsContent value="exp"><Card><CardContent className="p-8 text-sm text-muted-foreground">Disbursements & reimbursables tagged to matters.</CardContent></Card></TabsContent>
        </Tabs>
      </div>
    </div>
  );
}
