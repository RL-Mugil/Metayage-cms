import { Head, Link, router } from "@inertiajs/react";
import { useEffect, useState, useCallback, useMemo } from "react";
import { Briefcase, Users, Wallet, Clock, ArrowUpRight, TrendingUp, Loader2, Download, X, Search, ChevronLeft, ChevronRight, Archive, Landmark, MapPin, GitBranch, PieChart as PieChartIcon } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV , type DashboardTrends } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { usePage } from "@inertiajs/react";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import { fmtDate } from "@/lib/date-utils";
import { ClientActionDashboard } from "@/pages/dashboard/ClientActionDashboard";
import { ClientFinanceDashboard } from "@/pages/dashboard/ClientFinanceDashboard";
import { InventorDashboard } from "@/pages/dashboard/InventorDashboard";
import { ActionItem, ActionItemRow, RenewalApproveModal } from "@/components/action-item-row";
import { DonutChart, StatusBar, JurisdictionStackedBar, officeLabel } from "@/components/portfolio-charts";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { FinanceTab } from "@/components/dashboard/finance-tab";
import { useMiniPager, MiniPager } from "@/components/mini-pager";

function formatCurrency(val: number) {
  if (val >= 100000) return `₹ ${(val / 100000).toFixed(1)}L`;
  return `₹ ${val.toLocaleString()}`;
}

type DrillKey = "active_cases" | "inactive_cases" | "active_clients" | "quoted" | "collected"
  | "dl_overdue" | "dl_next_7_days" | "dl_unreviewed" | "dl_critical";

/** An unpaged list endpoint shown in the drill dialog's pages. */
const pagedFrom = (load: () => Promise<any[]>) => async (params: URLSearchParams) => {
  const rows = await load();
  const page = Number(params.get("page") ?? 1); const per = Number(params.get("per_page") ?? 20);
  const q = (params.get("search") ?? "").toLowerCase();
  const list = q ? rows.filter((r) => JSON.stringify(r).toLowerCase().includes(q)) : rows;
  return { data: list.slice((page - 1) * per, page * per), total: list.length };
};

interface DrillConfig {
  title: string;
  subtitle: string;
  fetchFn: (params: URLSearchParams) => Promise<any>;
  columns: { label: string; render: (row: any) => React.ReactNode }[];
  /** Clicking a row (e.g. a deadline → the case's Deadlines tab). */
  onRowClick?: (row: any) => void;
}

function DashboardDrillModal({ config, onClose }: { config: DrillConfig; onClose: () => void }) {
  const [result, setResult]   = useState<{ data: any[]; total: number }>({ data: [], total: 0 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(false);
  const [page, setPage]       = useState(1);
  const PER_PAGE = 15;

  const fetchPage = useCallback(async (pg: number, q: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pg) });
      if (q) params.set("search", q);
      const res: any = await config.fetchFn(params);
      setResult({ data: Array.isArray(res) ? res : (res?.data ?? []), total: res?.total ?? 0 });
    } finally { setLoading(false); }
  }, [config]);

  useEffect(() => { fetchPage(1, ""); }, [fetchPage]);

  function handleSearch(q: string) { setSearch(q); setPage(1); fetchPage(1, q); }
  function goPage(pg: number) { setPage(pg); fetchPage(pg, search); }
  const totalPages = Math.max(1, Math.ceil(result.total / PER_PAGE));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{config.title}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{config.subtitle} · {result.total} records</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
              className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search…"
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
            />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  {config.columns.map((col) => (
                    <th key={col.label} className="px-4 py-3 text-left">{col.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {result.data.map((row, i) => (
                  <tr key={row.id ?? i} onClick={() => config.onRowClick?.(row)}
                    className={`border-t border-border hover:bg-muted/30 ${config.onRowClick ? "cursor-pointer" : ""}`}>
                    {config.columns.map((col, c) => (
                      <td key={`${col.label}-${c}`} className="px-4 py-2.5">{col.render(row)}</td>
                    ))}
                  </tr>
                ))}
                {result.data.length === 0 && (
                  <tr><td colSpan={config.columns.length} className="px-4 py-10 text-center text-muted-foreground">No records found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <div className="flex items-center justify-between px-6 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
          <span>Showing {result.data.length} of {result.total}</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1}
              className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40">
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="px-2">{page} / {totalPages}</span>
            <button onClick={() => goPage(page + 1)} disabled={page >= totalPages}
              className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40">
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Role router: client_finance and client/client_admin get their own dedicated,
 * interactive dashboards (each a self-contained component with its own data
 * fetching) instead of the internal staff dashboard below — kept as a separate
 * component per role rather than a mid-component conditional return, so hooks
 * stay unconditional within each one.
 */
export default function Dashboard() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;

  if (role === "client_finance") return <ClientFinanceDashboard />;
  if (role === "client" || role === "client_admin") return <ClientActionDashboard />;
  if (role === "inventor") return <InventorDashboard />;
  return <StaffDashboard />;
}

function StaffDashboard() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [metrics, setMetrics]   = useState<any>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [tasks, setTasks]       = useState<any[]>([]);
  const [invoices, setInvoices] = useState<any[]>([]);
  const [portfolio, setPortfolio] = useState<any>(null);
  const [loading, setLoading]   = useState(true);
  const [drillKey, setDrillKey] = useState<DrillKey | null>(null);
  const [renewalItem, setRenewalItem] = useState<ActionItem | null>(null);
  const [trends, setTrends] = useState<DashboardTrends | null>(null);
  useEffect(() => { api.getDashboardTrends().then(setTrends).catch(() => setTrends(null)); }, []);

  const feeRates = props.systemSettings?.renewal_fee_rates ?? { government_fee: 0, professional_fee: 0, currency: "INR" };

  useEffect(() => {
    const isAnalyst = user?.role === 'associate';
    const load = async () => {
      // Only Patent Analysts get role-filtered data; all other roles see everything.
      const rf = isAnalyst && roleFilter !== 'all' ? roleFilter : undefined;
      const projectParams = new URLSearchParams({ per_page: '500' });
      if (rf) projectParams.set('role_filter', rf);
      const [m, p, t, i, pf] = await Promise.all([
        api.getDashboardMetrics(rf).catch(() => ({ metrics: {} })),
        api.getProjectsPaged(projectParams).catch(() => []),
        api.getTasks().catch(() => []),
        api.getInvoices().catch(() => []),
        api.getPatentPortfolioStats(null, rf).catch(() => null),
      ]);
      setMetrics((m as any)?.metrics ?? {});
      setProjects(Array.isArray(p) ? p : (p as any).data || []);
      setTasks(Array.isArray(t) ? t : (t as any).data || []);
      setInvoices(Array.isArray(i) ? i : (i as any).data || []);
      setPortfolio(pf);
      setLoading(false);
    };
    load();
  }, [roleFilter]);

  // IP Portfolio Analytics — derived entirely from the same case-level dedup the
  // /patent-portfolio page uses, so the two stay consistent. Kept read-only here
  // (no click-to-filter) since Dashboard has no drill-down table to filter.
  const statusMix = useMemo(() => {
    if (!portfolio) return { data: [], total: 0 };
    const sum = (key: string) => Object.values((portfolio[key] ?? {}) as Record<string, number>).reduce((s: number, v) => s + Number(v), 0);
    const data = [
      { code: "granted", name: "Granted", value: sum("granted_by_office"), color: "#22c55e" },
      { code: "active", name: "Active / Pending", value: sum("active_by_office"), color: "#f59e0b" },
      { code: "refused", name: "Refused / Abandoned", value: sum("refused_by_office"), color: "#ef4444" },
      { code: "closed", name: "Closed / Completed", value: sum("closed_by_office"), color: "#6b7280" },
    ].filter(d => d.value > 0);
    return { data, total: data.reduce((s, d) => s + d.value, 0) };
  }, [portfolio]);

  const jurisdictionRows = useMemo(() => {
    if (!portfolio) return { rows: [], max: 0 };
    const granted: Record<string, number> = portfolio.granted_by_office ?? {};
    const active: Record<string, number> = portfolio.active_by_office ?? {};
    const codes = new Set([...Object.keys(granted), ...Object.keys(active)]);
    const rows = [...codes]
      .map((code) => ({
        code,
        name: officeLabel(code),
        granted: Number(granted[code] ?? 0),
        pending: Number(active[code] ?? 0),
      }))
      .map(r => ({ ...r, total: r.granted + r.pending }))
      .sort((a, b) => b.total - a.total)
      .slice(0, 8);
    const max = rows.reduce((m, r) => Math.max(m, r.total), 0);
    return { rows, max };
  }, [portfolio]);

  const stageData = useMemo(() => {
    if (!portfolio) return { rows: [], max: 0 };
    const rows = (portfolio.pending_by_stage as any[] ?? []).map((s: any) => ({ name: s.stage_name, count: Number(s.count) }));
    const max = rows.reduce((m: number, r: any) => Math.max(m, r.count), 0);
    return { rows, max };
  }, [portfolio]);

  // Paginated client-side (all three lists are already fully loaded above) so
  // the dashboard doesn't grow taller as cases/tasks/action-items accumulate.
  const actionItemsPager = useMiniPager<ActionItem>(metrics?.action_items ?? [], 5);
  const casesPager = useMiniPager(projects, 5);
  const tasksPager = useMiniPager(tasks, 5);

  const handleExport = () => {
    const rows = projects.map(p => ({
      Code: p.project_code, Name: p.project_name, Client: p.client?.company_name,
      Type: p.project_type, Status: p.status, Urgency: p.urgency, Deadline: p.hard_deadline ?? "",
    }));
    downloadCSV(`dashboard-cases-${new Date().toISOString().slice(0,10)}.csv`, rows);
  };

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

  const welcomeName = user?.name?.split(" ")[0] ?? "User";

  function fmtD(d: string | null | undefined) {
    if (!d) return "—";
    const p = d.split("T")[0]; const [y, m, day] = p.split("-");
    return (!y || !m || !day) ? d : `${day}-${m}-${y}`;
  }

  const DRILL_CONFIGS: Record<DrillKey, DrillConfig> = {
    active_cases: {
      title: "Active Cases",
      subtitle: "Open & In Progress",
      fetchFn: (params) => {
        params.set("status", "Open,In Progress");
        return api.getProjectsPaged(params);
      },
      columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? r.project_code ?? "—"}</span> },
        { label: "Patent Title", render: (r) => <span className="max-w-[200px] truncate block font-medium">{r.project_name}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client?.company_name ?? "—"}</span> },
        { label: "Status", render: (r) => <span className="text-xs">{r.status}</span> },
        { label: "Deadline", render: (r) => { const od = r.hard_deadline && new Date(r.hard_deadline) < new Date(); return <span className={`text-xs font-mono ${od ? "text-destructive font-semibold" : "text-muted-foreground"}`}>{fmtD(r.hard_deadline)}</span>; } },
      ],
    },
    inactive_cases: {
      title: "Inactive Cases",
      subtitle: "Completed, Archived, Closed & other terminal statuses",
      fetchFn: (params) => {
        params.set("exclude_status", "Open,In Progress");
        return api.getProjectsPaged(params);
      },
      columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? r.project_code ?? "—"}</span> },
        { label: "Patent Title", render: (r) => <span className="max-w-[200px] truncate block font-medium">{r.project_name}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client?.company_name ?? "—"}</span> },
        { label: "Status", render: (r) => <span className="text-xs">{r.status}</span> },
        { label: "Deadline", render: (r) => <span className="text-xs font-mono text-muted-foreground">{fmtD(r.hard_deadline)}</span> },
      ],
    },
    active_clients: {
      title: "Active Clients",
      subtitle: "Status = Active",
      fetchFn: (params) => {
        params.set("status", "Active");
        return api.getClients(params);
      },
      columns: [
        { label: "Code", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.client_code ?? "—"}</span> },
        { label: "Name", render: (r) => <span className="font-medium">{r.legal_name ?? r.company_name}</span> },
        { label: "Type", render: (r) => <span className="text-xs text-muted-foreground">{r.client_type}</span> },
        { label: "GST Type", render: (r) => <span className="text-xs">{r.gst_type}</span> },
        { label: "Onboarded", render: (r) => <span className="text-xs font-mono text-muted-foreground">{fmtD(r.date_onboarded)}</span> },
      ],
    },
    quoted: {
      title: "Quoted, not invoiced",
      subtitle: "Quotes sent or accepted, not yet turned into an invoice",
      fetchFn: pagedFrom(() => api.getDashboardQuoted()),
      onRowClick: (r: any) => router.visit(`/financial`),
      columns: [
        { label: "Quote", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.number ?? "—"}</span> },
        { label: "Client", render: (r) => <span className="text-sm">{r.client ?? "—"}</span> },
        { label: "Amount", render: (r) => <span className="text-sm font-medium">{formatCurrency(r.amount)}</span> },
        { label: "Status", render: (r) => <span className="text-xs capitalize">{r.status}</span> },
        { label: "Date", render: (r) => <span className="text-xs font-mono text-muted-foreground">{fmtD(r.date)}</span> },
      ],
    },
    collected: {
      title: "Collected this month",
      subtitle: "Payments received this month, by payment date",
      fetchFn: pagedFrom(() => api.getDashboardCollected()),
      columns: [
        { label: "Date", render: (r) => <span className="text-xs font-mono">{fmtD(r.date)}</span> },
        { label: "Invoice", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.number ?? "—"}</span> },
        { label: "Client", render: (r) => <span className="text-sm">{r.client ?? "—"}</span> },
        { label: "Amount", render: (r) => <span className="text-sm font-medium text-success">{formatCurrency(r.amount)}</span> },
        { label: "Mode", render: (r) => <span className="text-xs text-muted-foreground">{r.estimated ? "Date estimated" : (r.mode ?? "—")}</span> },
      ],
    },
    dl_overdue: { title: "Overdue deadlines", subtitle: "Open statutory deadlines past their due date", fetchFn: pagedFrom(() => api.getDashboardDeadlines("overdue")), onRowClick: (r: any) => router.visit(`/projects/${r.project_id}?tab=deadlines`), columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? "—"}</span> },
        { label: "Deadline", render: (r) => <span className="text-sm">{r.title}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client ?? "—"}</span> },
        { label: "Due", render: (r) => <span className="text-xs font-mono">{fmtD(r.due_date)}</span> },
        { label: "", render: (r) => <span className={`text-xs font-semibold ${r.days != null && r.days < 0 ? "text-destructive" : "text-muted-foreground"}`}>{r.days == null ? "" : r.days < 0 ? `${-r.days}d overdue` : `in ${r.days}d`}</span> },
      ] },
    dl_next_7_days: { title: "Deadlines in the next 7 days", subtitle: "Open statutory deadlines due this week", fetchFn: pagedFrom(() => api.getDashboardDeadlines("next_7_days")), onRowClick: (r: any) => router.visit(`/projects/${r.project_id}?tab=deadlines`), columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? "—"}</span> },
        { label: "Deadline", render: (r) => <span className="text-sm">{r.title}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client ?? "—"}</span> },
        { label: "Due", render: (r) => <span className="text-xs font-mono">{fmtD(r.due_date)}</span> },
        { label: "", render: (r) => <span className={`text-xs font-semibold ${r.days != null && r.days < 0 ? "text-destructive" : "text-muted-foreground"}`}>{r.days == null ? "" : r.days < 0 ? `${-r.days}d overdue` : `in ${r.days}d`}</span> },
      ] },
    dl_unreviewed: { title: "Unreviewed deadlines", subtitle: "Computed deadlines awaiting a reviewer", fetchFn: pagedFrom(() => api.getDashboardDeadlines("unreviewed")), onRowClick: (r: any) => router.visit(`/projects/${r.project_id}?tab=deadlines`), columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? "—"}</span> },
        { label: "Deadline", render: (r) => <span className="text-sm">{r.title}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client ?? "—"}</span> },
        { label: "Due", render: (r) => <span className="text-xs font-mono">{fmtD(r.due_date)}</span> },
        { label: "", render: (r) => <span className={`text-xs font-semibold ${r.days != null && r.days < 0 ? "text-destructive" : "text-muted-foreground"}`}>{r.days == null ? "" : r.days < 0 ? `${-r.days}d overdue` : `in ${r.days}d`}</span> },
      ] },
    dl_critical: { title: "Critical deadlines", subtitle: "Open deadlines marked Critical", fetchFn: pagedFrom(() => api.getDashboardDeadlines("critical")), onRowClick: (r: any) => router.visit(`/projects/${r.project_id}?tab=deadlines`), columns: [
        { label: "Docket", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.docket_number ?? "—"}</span> },
        { label: "Deadline", render: (r) => <span className="text-sm">{r.title}</span> },
        { label: "Client", render: (r) => <span className="text-xs text-muted-foreground">{r.client ?? "—"}</span> },
        { label: "Due", render: (r) => <span className="text-xs font-mono">{fmtD(r.due_date)}</span> },
        { label: "", render: (r) => <span className={`text-xs font-semibold ${r.days != null && r.days < 0 ? "text-destructive" : "text-muted-foreground"}`}>{r.days == null ? "" : r.days < 0 ? `${-r.days}d overdue` : `in ${r.days}d`}</span> },
      ] },
  };

  return (
    <AppLayout>
      <Head title="Dashboard" />
      {/* Viewport-locked shell: header + tab strip stay fixed, each tab scrolls its
          own content independently instead of the whole page scrolling. */}
      <div className="flex h-[calc(100vh-3.5rem)] flex-col overflow-hidden">
        <PageHeader
          eyebrow="Overview"
          title={`Good morning, ${welcomeName}`}
          description={`${metrics.active_matters} active · ${metrics.inactive_matters ?? 0} inactive · ${metrics.clients} clients · ${formatCurrency(metrics.zoho_outstanding ?? 0)} outstanding`}
          actions={
            <>
              <AnalystRoleFilter value={roleFilter} onChange={setRoleFilter} />
              <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            </>
          }
        />

        {drillKey && (
          <DashboardDrillModal config={DRILL_CONFIGS[drillKey]} onClose={() => setDrillKey(null)} />
        )}

        {renewalItem && (
          <RenewalApproveModal
            item={renewalItem}
            feeRates={feeRates}
            canApprove={false /* renewal approval is a client_admin action, not staff's */}
            onClose={() => setRenewalItem(null)}
            onApproved={() => setRenewalItem(null)}
          />
        )}

        <Tabs defaultValue="overview" className="flex flex-1 flex-col overflow-hidden">
          <TabsList className="mx-8 mt-4 w-fit shrink-0">
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="portfolio">IP Portfolio</TabsTrigger>
            <TabsTrigger value="financial">Financial</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-0 flex-1 overflow-y-auto px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-6">
          <StatCard
            label="Active Cases"
            value={metrics.active_matters.toString()}
            delta={metrics.opened_this_month != null ? `+${metrics.opened_this_month} opened this month` : undefined}
            trend="up"
            icon={Briefcase}
            accent="primary"
            onClick={() => setDrillKey("active_cases")}
            spark={trends?.opened}
            subtitle={metrics.distinct_matters != null && metrics.distinct_matters !== metrics.active_matters
              ? `${metrics.distinct_matters} distinct matters`
              : undefined}
          />
          <StatCard label="Inactive Cases" value={(metrics.inactive_matters ?? 0).toString()} icon={Archive} accent="neutral" onClick={() => setDrillKey("inactive_cases")} />
          <StatCard label="Active Clients" value={metrics.clients.toString()} delta={metrics.clients_delta ?? undefined} trend={metrics.clients_delta_trend ?? "up"} icon={Users} accent="gold" onClick={() => setDrillKey("active_clients")} />
          <StatCard label="Quoted, not invoiced" value={formatCurrency(metrics.quoted_open ?? 0)} icon={Clock} accent="info" subtitle={`${metrics.quoted_count ?? 0} open quotes`} spark={trends?.quoted} onClick={() => setDrillKey("quoted")} />
          <StatCard label="Collected this month" value={formatCurrency(metrics.collected_mtd ?? 0)} delta={metrics.revenue_delta ?? undefined} trend={metrics.revenue_delta_trend ?? "up"} icon={Wallet} accent="success" spark={trends?.collected} onClick={() => setDrillKey("collected")} />
          <StatCard label="Outstanding" value={formatCurrency(metrics.zoho_outstanding ?? 0)} icon={Landmark} accent="info" subtitle={`${metrics.overdue_invoices ?? 0} invoices overdue`} onClick={() => router.visit("/pending-payments")} />
        </div>

        <section className="border-y border-border py-4">
          <div className="mb-3 flex items-center justify-between"><div><h2 className="text-sm font-semibold">Statutory deadline risk</h2><p className="mt-1 text-xs text-muted-foreground">Open deadlines visible within your matter scope</p></div><Link href="/projects" className="text-xs font-medium text-gold hover:underline">Open matters</Link></div>
          <div className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border lg:grid-cols-4">
            {([["Overdue", "dl_overdue", metrics.deadline_risk?.overdue ?? 0], ["Next 7 days", "dl_next_7_days", metrics.deadline_risk?.next_7_days ?? 0], ["Unreviewed", "dl_unreviewed", metrics.deadline_risk?.unreviewed ?? 0], ["Critical", "dl_critical", metrics.deadline_risk?.critical ?? 0]] as [string, DrillKey, number][]).map(([label, key, value]) => (
              <button key={label} onClick={() => Number(value) > 0 && setDrillKey(key)} title={Number(value) > 0 ? `Show the ${String(label).toLowerCase()} deadlines` : undefined}
                className={`px-4 py-3 text-left transition-colors ${Number(value) > 0 ? "cursor-pointer hover:bg-muted/40" : "cursor-default"} ${(label === "Overdue" || label === "Critical") && Number(value) > 0 ? "bg-destructive/5" : "bg-background"}`}>
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className={`mt-1 text-2xl font-semibold ${(label === "Overdue" || label === "Critical") && Number(value) > 0 ? "text-destructive" : ""}`}>{value}</p>
                {Number(value) > 0 && <p className="mt-0.5 text-[10px] text-muted-foreground">Click to view →</p>}
              </button>
            ))}
          </div>
        </section>

        {(metrics.action_items ?? []).length > 0 && (
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2">
                <Clock className="h-4 w-4" /> Action Required
              </CardTitle>
              <p className="text-xs text-muted-foreground mt-1">
                Renewals first, then urgency and nearest deadline — click a case for details, click a renewal for the fee breakdown.
              </p>
            </CardHeader>
            <CardContent className="space-y-2">
              {actionItemsPager.pageItems.map((item) => (
                <ActionItemRow key={item.id} item={item} onRenewClick={setRenewalItem} showOwnerBadge />
              ))}
            </CardContent>
            <MiniPager
              page={actionItemsPager.page}
              totalPages={actionItemsPager.totalPages}
              pageSize={actionItemsPager.pageSize}
              total={actionItemsPager.total}
              onPageChange={actionItemsPager.setPage}
              onPageSizeChange={actionItemsPager.setPageSize}
            />
          </Card>
        )}

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2 border-border">
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle className="font-display">Cases needing attention</CardTitle>
                <p className="text-xs text-muted-foreground mt-1">Sorted by deadline proximity & priority</p>
              </div>
              <Button asChild variant="ghost" size="sm">
                <Link href="/projects">View all <ArrowUpRight className="ml-1 h-3 w-3" /></Link>
              </Button>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 text-left">Case</th>
                    <th className="px-4 py-2 text-left">Client</th>
                    <th className="px-4 py-2 text-left">Stage</th>
                    <th className="px-4 py-2 text-left">Priority</th>
                    <th className="px-4 py-2 text-left">Due</th>
                  </tr>
                </thead>
                <tbody>
                  {casesPager.pageItems.map((p) => {
                    const activeStage = p.stages?.find((s: any) => s.status === "In Progress")?.stage_name || "Invention Disclosure";
                    return (
                      <tr key={p.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="font-medium">{p.project_name}</div>
                          <div className="text-xs text-muted-foreground font-mono">{p.project_code}</div>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{p.client?.company_name}</td>
                        <td className="px-4 py-3"><Badge variant="secondary">{activeStage}</Badge></td>
                        <td className="px-4 py-3"><Badge variant={p.urgency === "High" ? "destructive" : "outline"}>{p.urgency}</Badge></td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtDate(p.hard_deadline) === "—" ? "No deadline" : fmtDate(p.hard_deadline)}</td>
                      </tr>
                    );
                  })}
                  {projects.length === 0 && (
                    <tr><td colSpan={5} className="px-4 py-8 text-center text-muted-foreground text-sm">No cases yet. Create your first case above.</td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
            <MiniPager
              page={casesPager.page}
              totalPages={casesPager.totalPages}
              pageSize={casesPager.pageSize}
              total={casesPager.total}
              onPageChange={casesPager.setPage}
              onPageSizeChange={casesPager.setPageSize}
            />
          </Card>

          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display">My tasks</CardTitle>
              <p className="text-xs text-muted-foreground mt-1">{tasks.length} open tasks</p>
            </CardHeader>
            <CardContent className="space-y-3">
              {tasksPager.pageItems.map((t) => (
                <div key={t.id} className="flex items-start gap-3 rounded-md border border-border p-3 hover:bg-muted/30">
                  <div className="mt-1 h-2 w-2 rounded-full bg-gold flex-shrink-0" />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate">{t.title}</div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                      <span className="font-mono">{t.project?.project_code}</span>
                      {t.due_date && <><span>·</span><span>{fmtDate(t.due_date)}</span></>}
                    </div>
                  </div>
                  <Badge variant={statusColor(t.status)} className="text-[10px] flex-shrink-0">{t.status}</Badge>
                </div>
              ))}
              {tasks.length === 0 && <p className="text-sm text-muted-foreground text-center py-4">No open tasks</p>}
            </CardContent>
            <MiniPager
              page={tasksPager.page}
              totalPages={tasksPager.totalPages}
              pageSize={tasksPager.pageSize}
              total={tasksPager.total}
              onPageChange={tasksPager.setPage}
              onPageSizeChange={tasksPager.setPageSize}
            />
          </Card>
        </div>
          </TabsContent>

          <TabsContent value="portfolio" className="mt-0 flex-1 overflow-y-auto px-8 py-6 space-y-6">
            {portfolio && statusMix.total > 0 ? (
              <section>
                <div className="mb-3 flex items-center justify-between">
                  <div>
                    <h2 className="text-sm font-semibold flex items-center gap-2"><PieChartIcon className="h-4 w-4 text-gold" /> IP Portfolio Analytics</h2>
                    <p className="mt-1 text-xs text-muted-foreground">Patent case mix, jurisdiction spread, and prosecution pipeline across the firm</p>
                  </div>
                  <Link href="/patent-portfolio" className="text-xs font-medium text-gold hover:underline flex items-center gap-1">
                    View full portfolio <ArrowUpRight className="h-3 w-3" />
                  </Link>
                </div>
                <div className="grid gap-4 lg:grid-cols-3">
                  <Card className="border-border">
                    <CardHeader className="pb-1">
                      <CardTitle className="font-display text-sm">Case Status Mix</CardTitle>
                      <p className="text-xs text-muted-foreground">{statusMix.total} unique patent cases, deduped by invention &amp; office</p>
                    </CardHeader>
                    <CardContent className="flex items-center justify-center pt-2">
                      <DonutChart data={statusMix.data} total={statusMix.total} label="All Cases" activeCode={null} onSelect={() => {}} />
                    </CardContent>
                  </Card>

                  <Card className="border-border">
                    <CardHeader className="pb-1">
                      <CardTitle className="font-display text-sm flex items-center gap-2"><MapPin className="h-4 w-4 text-gold" /> Where The Patents Are</CardTitle>
                      <p className="text-xs text-muted-foreground">Granted vs. pending records by jurisdiction</p>
                    </CardHeader>
                    <CardContent className="pt-2">
                      {jurisdictionRows.rows.length === 0 ? (
                        <p className="text-xs text-muted-foreground py-6 text-center">No jurisdiction data yet.</p>
                      ) : (
                        <>
                          <div className="space-y-2">
                            {jurisdictionRows.rows.map((r) => (
                              <JurisdictionStackedBar key={r.code} name={r.name} granted={r.granted} pending={r.pending} max={jurisdictionRows.max} />
                            ))}
                          </div>
                          <div className="flex items-center gap-3 mt-3 text-[10px] text-muted-foreground">
                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{ background: "#22c55e" }} />Granted</span>
                            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full" style={{ background: "#f59e0b" }} />Pending</span>
                          </div>
                        </>
                      )}
                    </CardContent>
                  </Card>

                  <Card className="border-border">
                    <CardHeader className="pb-1">
                      <CardTitle className="font-display text-sm flex items-center gap-2"><GitBranch className="h-4 w-4 text-gold" /> Prosecution Pipeline</CardTitle>
                      <p className="text-xs text-muted-foreground">Pending patents by current lifecycle stage</p>
                    </CardHeader>
                    <CardContent className="pt-2">
                      {stageData.rows.length === 0 ? (
                        <p className="text-xs text-muted-foreground py-6 text-center">No pending patents.</p>
                      ) : (
                        <div className="space-y-1.5">
                          {stageData.rows.map((s) => (
                            <StatusBar key={s.name} name={s.name} count={s.count} max={stageData.max} active={false} onClick={() => {}} />
                          ))}
                        </div>
                      )}
                    </CardContent>
                  </Card>
                </div>
              </section>
            ) : (
              <p className="text-sm text-muted-foreground text-center py-12">No patent portfolio data available for your scope yet.</p>
            )}
          </TabsContent>

          <TabsContent value="financial" className="mt-0 flex-1 overflow-y-auto px-8 py-6 space-y-6">
            <FinanceTab />
          </TabsContent>
        </Tabs>
      </div>
    </AppLayout>
  );
}
