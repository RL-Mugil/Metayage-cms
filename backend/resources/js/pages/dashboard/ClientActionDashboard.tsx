import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, AlertTriangle, Landmark, Clock } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api-client";
import { ActionItem, ActionItemRow, RenewalApproveModal, formatCurrency } from "@/components/action-item-row";

/**
 * Action-centric dashboard for client / client_admin — "what do I need to
 * decide right now", renewals first (abandonment risk), then urgency, then
 * nearest deadline. Sourced from DashboardController::metrics()'s
 * action_items field (ActionItemService::clientActionFeed). Renewal items
 * open the approve → invoice modal directly; everything else opens the case.
 */
export function ClientActionDashboard() {
  const { props } = usePage() as any;
  const [metrics, setMetrics] = useState<Record<string, any> | null>(null);
  const [loading, setLoading] = useState(true);
  const [renewalItem, setRenewalItem] = useState<ActionItem | null>(null);

  const feeRates = props.systemSettings?.renewal_fee_rates ?? { government_fee: 0, professional_fee: 0, currency: "INR" };
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
        <Head title="Dashboard" />
        <div className="flex h-screen items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  const items: ActionItem[] = metrics.action_items ?? [];
  const renewals = items.filter((i) => i.is_renewal);
  const others = items.filter((i) => !i.is_renewal);

  return (
    <AppLayout>
      <Head title="Dashboard" />
      <PageHeader
        eyebrow="Overview"
        title="Action Items"
        description={`${items.length} item${items.length === 1 ? "" : "s"} need your attention · ${renewals.length} renewal${renewals.length === 1 ? "" : "s"} due`}
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
          <StatCard label="Renewals Due" value={renewals.length.toString()} icon={AlertTriangle} accent={renewals.length > 0 ? "gold" : "neutral"} />
          <StatCard label="Open Action Items" value={items.length.toString()} icon={Clock} accent="primary" />
          <StatCard label="Zoho Outstanding" value={formatCurrency(metrics.zoho_outstanding ?? 0)} icon={Landmark} accent="info" subtitle={`Collected MTD ${formatCurrency(metrics.zoho_collected_mtd ?? 0)}`} />
        </div>

        {renewals.length > 0 && (
          <Card className="border-red-500/30">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2 text-red-600">
                <AlertTriangle className="h-4 w-4" /> Renewals Due
              </CardTitle>
              <p className="text-xs text-muted-foreground">Missing a renewal fee deadline risks abandonment — these come first. Click to review and approve.</p>
            </CardHeader>
            <CardContent className="space-y-2">
              {renewals.map((item) => <ActionItemRow key={item.id} item={item} onRenewClick={setRenewalItem} />)}
            </CardContent>
          </Card>
        )}

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">All Action Items</CardTitle>
            <p className="text-xs text-muted-foreground mt-1">Approvals, deadlines, and cases awaiting a decision</p>
          </CardHeader>
          <CardContent className="space-y-2">
            {others.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-10">Nothing needs your attention right now.</p>
            ) : (
              others.map((item) => <ActionItemRow key={item.id} item={item} />)
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
