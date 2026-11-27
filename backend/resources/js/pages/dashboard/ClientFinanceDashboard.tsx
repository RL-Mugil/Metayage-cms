import { Head, Link, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Landmark, Wallet, Scale, AlertTriangle, Clock, Upload } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";
import { ActionItem, ActionItemRow, RenewalApproveModal, formatCurrency } from "@/components/action-item-row";
import { ClientHub } from "@/components/client/client-hub";
import { ClientBillingPanel } from "@/components/client/client-billing-panel";

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
  const canApprove = (props.auth?.user?.client_access ?? []).includes("renewals");

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
  const totalDue = pendingInvoices.reduce((sum: number, inv: any) => sum + (parseFloat(inv.balance_due ?? 0) || 0), 0);

  return (
    <AppLayout>
      <Head title="Billing" />
      <PageHeader
        eyebrow="Billing"
        title="Your Billing"
        description="Invoices to pay, payments made, and upcoming renewal fees."
        actions={
          <Link href="/pending-payments" className="inline-flex h-9 items-center gap-2 rounded-md bg-gold px-4 text-sm font-medium text-black hover:bg-gold/90">
            <Upload className="h-4 w-4" />Make a payment
          </Link>
        }
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

      <div className="px-4 py-4 sm:px-8 sm:py-6 space-y-6">
        <Card className="card-3d border-gold/30">
          <CardHeader className="pb-2">
            <CardTitle className="font-display">Waiting on you</CardTitle>
            <p className="text-xs text-muted-foreground">Approve, decide on renewals, pay, or ask us about anything here.</p>
          </CardHeader>
          <CardContent><ClientHub /></CardContent>
        </Card>

        {/* The portal's own billing: total payable, unpaid invoices and proformas, statement (3D). */}
        <ClientBillingPanel />

        {renewalItems.length > 0 && (
          <Card className="card-3d border-red-500/30">
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

        <Card className="card-3d border-border">
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

      </div>
    </AppLayout>
  );
}
