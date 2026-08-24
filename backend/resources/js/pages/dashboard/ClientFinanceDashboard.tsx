import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Landmark, Wallet, Scale, AlertTriangle, Clock } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";
import { ActionItem, ActionItemRow, RenewalApproveModal, formatCurrency } from "@/components/action-item-row";

/**
 * Billing-only dashboard for the client_finance role — no drafting/technical
 * case visibility (see RolePermissions::forRole('client_finance')), but it
 * does need finance-relevant action items and upcoming renewals (what's
 * coming due, how much it'll cost) alongside outstanding/collected/ledger.
 * Sourced from DashboardController::financeOnlyMetrics()'s finance-filtered
 * action_items/renewal_items (ActionItemService::financeActionFeed()).
 */
export function ClientFinanceDashboard() {
  const { props } = usePage() as any;
  const [metrics, setMetrics] = useState<Record<string, any> | null>(null);
  const [loading, setLoading] = useState(true);
  const [renewalItem, setRenewalItem] = useState<ActionItem | null>(null);

  const feeRates = props.systemSettings?.renewal_fee_rates ?? { government_fee: 0, professional_fee: 0, currency: "INR" };
  // Renewal approval stays a client_admin action; client_finance sees fee detail read-only.
  const canApprove = props.auth?.user?.role === "client_admin";

  const load = () => {
    api.getDashboardMetrics()
      .then((m: any) => setMetrics(m?.metrics ?? {}))
      .catch(() => setMetrics({}))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  if (loading || !metrics) {
    return (
      <AppLayout>
        <Head title="Billing" />
        <div className="flex h-screen items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  const pendingInvoices = metrics.pending_invoices ?? [];
  const actionItems: ActionItem[] = metrics.action_items ?? [];
  const renewalItems: ActionItem[] = metrics.renewal_items ?? actionItems.filter((i) => i.is_renewal);
  const otherActionItems = actionItems.filter((i) => !i.is_renewal);

  return (
    <AppLayout>
      <Head title="Billing" />
      <PageHeader
        eyebrow="Billing"
        title="Your Billing"
        description="Outstanding balance, collections, upcoming renewals, and pending invoices."
      />

      {renewalItem && (
        <RenewalApproveModal
          item={renewalItem}
          feeRates={feeRates}
          canApprove={canApprove}
          onClose={() => setRenewalItem(null)}
          onApproved={load}
        />
      )}

      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-3">
          <StatCard label="Outstanding" value={formatCurrency(metrics.zoho_outstanding ?? 0)} icon={Landmark} accent="info" />
          <StatCard label="Collected (MTD)" value={formatCurrency(metrics.zoho_collected_mtd ?? 0)} icon={Wallet} accent="success" />
          <StatCard label="Ledger Balance" value={formatCurrency(metrics.ledger_balance ?? 0)} icon={Scale} accent="neutral" />
        </div>

        {renewalItems.length > 0 && (
          <Card className="border-red-500/30">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2 text-red-600">
                <AlertTriangle className="h-4 w-4" /> Upcoming Renewals
              </CardTitle>
              <p className="text-xs text-muted-foreground">Renewal fees due — click a case to see the fee breakdown.</p>
            </CardHeader>
            <CardContent className="space-y-2">
              {renewalItems.map((item) => <ActionItemRow key={item.id} item={item} onRenewClick={setRenewalItem} />)}
            </CardContent>
          </Card>
        )}

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><Clock className="h-4 w-4" /> Action Required</CardTitle>
            <p className="text-xs text-muted-foreground mt-1">Payments and invoices awaiting your attention</p>
          </CardHeader>
          <CardContent className="space-y-2">
            {otherActionItems.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-10">Nothing needs your attention right now.</p>
            ) : (
              otherActionItems.map((item) => <ActionItemRow key={item.id} item={item} />)
            )}
          </CardContent>
        </Card>

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">Pending Invoices</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {pendingInvoices.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-10">No pending invoices.</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 text-left">Invoice</th>
                    <th className="px-4 py-2 text-left">Status</th>
                    <th className="px-4 py-2 text-right">Total</th>
                    <th className="px-4 py-2 text-right">Balance Due</th>
                    <th className="px-4 py-2 text-left">Due Date</th>
                  </tr>
                </thead>
                <tbody>
                  {pendingInvoices.map((inv: any) => (
                    <tr key={inv.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs">{inv.invoice_code}</td>
                      <td className="px-4 py-3"><Badge variant={statusColor(inv.status)}>{inv.status}</Badge></td>
                      <td className="px-4 py-3 text-right font-medium">{formatCurrency(parseFloat(inv.total_amount ?? 0))}</td>
                      <td className="px-4 py-3 text-right text-muted-foreground">{formatCurrency(parseFloat(inv.balance_due ?? 0))}</td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtDate(inv.due_date)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
