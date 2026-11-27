import { Head, router, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { motion } from "framer-motion";
import { AlertTriangle, Briefcase, CheckSquare, Clock, Inbox, Loader2, Wallet } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { KpiTile } from "@/components/modern/kpi-tile";
import { GlassCard, staggerParent } from "@/components/modern/glass-card";
import { Bars3D, Donut3D, inrShort } from "@/components/three/chart-3d";
import { api } from "@/lib/api-client";
import { ActionItem, ActionItemRow, RenewalApproveModal } from "@/components/action-item-row";
import { ClientHub } from "@/components/client/client-hub";
import { useClientBilling } from "@/components/client/client-billing-panel";

const BANDS = [
  { key: "not_due", label: "Not due", test: (d: number) => d <= 0 },
  { key: "1_30", label: "1–30", test: (d: number) => d > 0 && d <= 30 },
  { key: "31_60", label: "31–60", test: (d: number) => d > 30 && d <= 60 },
  { key: "61_90", label: "61–90", test: (d: number) => d > 60 && d <= 90 },
  { key: "90_plus", label: "90+", test: (d: number) => d > 90 },
];
const STATUS_COLORS = ["#3b82f6", "#8b5cf6", "#f59e0b", "#10b981", "#ef4444", "#06b6d4", "#64748b"];
const daysSince = (date: string | null) => (date ? Math.floor((Date.now() - new Date(date).getTime()) / 86400000) : 0);

/**
 * The client's dashboard (client / client_admin): what they owe (total payable), what is waiting
 * on them (approvals, renewals, quotes), their cases, in the same 3D style as the firm's pages.
 * Renewal items open the approve modal; everything else opens the case.
 */
export function ClientActionDashboard() {
  const { props } = usePage() as any;
  const [metrics, setMetrics] = useState<Record<string, any> | null>(null);
  const [cases, setCases] = useState<{ status: string }[]>([]);
  const [approvalsWaiting, setApprovalsWaiting] = useState(0);
  const [renewalItem, setRenewalItem] = useState<ActionItem | null>(null);
  const { data: billing } = useClientBilling();

  const feeRates = props.systemSettings?.renewal_fee_rates ?? { government_fee: 0, professional_fee: 0, currency: "INR" };
  const canApprove = (props.auth?.user?.client_access ?? []).includes("renewals");

  const load = () => { api.getDashboardMetrics().then((m: any) => setMetrics(m?.metrics ?? {})).catch(() => setMetrics({})); };
  useEffect(() => {
    load();
    api.request<any>("/projects?per_page=500").then((r) => setCases(Array.isArray(r) ? r : r?.data ?? [])).catch(() => setCases([]));
    api.request<any>("/approvals?per_page=200").then((r) => setApprovalsWaiting((r?.data ?? []).filter((a: any) => a.status === "pending").length)).catch(() => {});
  }, []);

  const items: ActionItem[] = metrics?.action_items ?? [];
  const renewals = items.filter((i) => i.is_renewal);
  const others = items.filter((i) => !i.is_renewal);
  const net = Number(billing?.summary.total_receivable ?? 0);

  // What is owed, by how long it has been due: invoices from their due date, proformas from their date.
  const ageing = useMemo(() => BANDS.map((b) => ({
    label: b.label,
    values: {
      invoices: (billing?.unpaid ?? []).filter((i) => b.test(i.days_overdue)).reduce((a, i) => a + i.balance_inr, 0),
      proformas: (billing?.proformas ?? []).filter((q) => b.test(daysSince(q.date))).reduce((a, q) => a + Number(q.net_due), 0),
    },
  })), [billing]);
  const byStatus = useMemo(() => {
    const m = new Map<string, number>();
    cases.forEach((c) => m.set(c.status || "Other", (m.get(c.status || "Other") ?? 0) + 1));
    return [...m.entries()].map(([name, value], i) => ({ code: name, name, value, color: STATUS_COLORS[i % STATUS_COLORS.length] }));
  }, [cases]);
  const active = cases.filter((c) => !["Closed", "Abandoned", "Granted", "Completed"].includes(c.status)).length;

  if (!metrics) {
    return <AppLayout><Head title="Dashboard" /><div className="flex h-[60vh] items-center justify-center"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div></AppLayout>;
  }

  return (
    <AppLayout>
      <Head title="Dashboard" />
      {renewalItem && <RenewalApproveModal item={renewalItem} feeRates={feeRates} canApprove={canApprove} onClose={() => setRenewalItem(null)} onApproved={load} />}
      <FitPage fit={false} eyebrow="Your account" title="Dashboard"
        summary={`${items.length} item${items.length === 1 ? "" : "s"} need your attention · ${renewals.length} renewal${renewals.length === 1 ? "" : "s"} due`}>
        <motion.div variants={staggerParent} initial="hidden" animate="show" className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <KpiTile label={net < -0.009 ? "Balance credit with us" : "Total payable"} value={Math.abs(net)} format="inr" icon={Wallet} accent={net > 0.009 ? "rose" : "emerald"}
              subtitle={billing ? `Invoices ${inrShort(billing.summary.unpaid_invoices)} · Proformas ${inrShort(billing.summary.quoted)}` : null}
              onClick={() => router.visit("/financial")} />
            <KpiTile label="Waiting on you" value={items.length} icon={Inbox} accent="gold" subtitle="Approvals, decisions and questions" />
            <KpiTile label="Approvals" value={approvalsWaiting} icon={CheckSquare} accent="violet" subtitle="Quotes and decisions to approve" onClick={() => router.visit("/approvals")} />
            <KpiTile label="Renewals due" value={renewals.length} icon={AlertTriangle} accent={renewals.length > 0 ? "rose" : "slate"} subtitle="Pay on time to keep rights in force" />
            <KpiTile label="Active cases" value={active} icon={Briefcase} accent="blue" subtitle={`${cases.length} cases in all`} onClick={() => router.visit("/projects")} />
          </div>

          <div className="grid gap-4 lg:grid-cols-5">
            <GlassCard className="card-3d lg:col-span-3" title="What you owe, by age" subtitle="Unpaid invoices (from due date) and proformas (from their date) · drag to turn, click to open">
              <div className="p-3 pt-0">
                <Bars3D height={240} format={inrShort} data={ageing}
                  series={[{ key: "invoices", label: "Unpaid invoices", color: "#3b82f6" }, { key: "proformas", label: "Proformas", color: "#8b5cf6" }]}
                  onSelect={(_l, key) => router.visit(`/pending-payments?doc=${key === "proformas" ? "quotes" : "invoices"}`)} />
              </div>
            </GlassCard>
            <GlassCard className="card-3d lg:col-span-2" title="Your cases" subtitle="By status · click a slice to open your cases">
              <div className="flex justify-center p-3 pt-0">
                {byStatus.length ? <Donut3D label="Cases" total={cases.length} data={byStatus} size={200} onSelect={() => router.visit("/projects")} />
                  : <p className="py-10 text-xs text-muted-foreground">No cases yet.</p>}
              </div>
            </GlassCard>
          </div>

          <GlassCard className="card-3d border border-gold/30" title="Waiting on you" icon={<Clock className="h-4 w-4 text-gold" />}
            subtitle="Approve quotes, decide on renewals, pay, or ask us about anything here.">
            <div className="p-4 pt-1"><ClientHub /></div>
          </GlassCard>

          {renewals.length > 0 && (
            <GlassCard className="card-3d border border-red-500/30" title="Renewals due" icon={<AlertTriangle className="h-4 w-4 text-red-600" />}
              subtitle="Missing a renewal fee deadline risks losing the right. Click to review and approve.">
              <div className="space-y-2 p-4 pt-1">{renewals.map((item) => <ActionItemRow key={item.id} item={item} onRenewClick={setRenewalItem} />)}</div>
            </GlassCard>
          )}

          <GlassCard className="card-3d" title="All action items" subtitle="Approvals, deadlines and cases awaiting a decision">
            <div className="space-y-2 p-4 pt-1">
              {others.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">Nothing needs your attention right now.</p>
                : others.map((item) => <ActionItemRow key={item.id} item={item} />)}
            </div>
          </GlassCard>
        </motion.div>
      </FitPage>
    </AppLayout>
  );
}
