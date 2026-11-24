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
import { FinanceTab } from "@/components/dashboard/finance-tab";
import { TeamTab } from "@/components/dashboard/team-tab";
import { OverviewGrid } from "@/components/dashboard/overview-grid";
import { PortfolioGrid } from "@/components/dashboard/portfolio-grid";
import { SegmentTabs } from "@/components/modern/segment-tabs";
import { AnimatePresence, motion } from "framer-motion";

const TEAM_ROLES = ["super_admin", "partner", "director", "manager", "galvanizer", "associate", "paralegal"];
/** Roles that see money (same list as DashboardInsightsController::FINANCE_ROLES, staff side). */
const FINANCE_ROLES = ["super_admin", "partner", "manager", "finance", "galvanizer"];
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
  const [tab, setTab] = useState<"overview" | "portfolio" | "financial" | "team">("overview");
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
      .sort((a, b) => b.total - a.total);
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

  const showMoney = FINANCE_ROLES.includes(user?.role);
  const tabs = [
    { value: "overview" as const, label: "Overview" },
    { value: "portfolio" as const, label: "IP Portfolio" },
    ...(showMoney ? [{ value: "financial" as const, label: "Financial" }] : []),
    ...(TEAM_ROLES.includes(user?.role) ? [{ value: "team" as const, label: "Team" }] : []),
  ];
  const hour = new Date().getHours();
  const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";

  return (
    <AppLayout>
      <Head title="Dashboard" />
      {/* One screen: compact header + tabs; each tab fits the window (lists scroll inside their tiles). */}
      <div className="surface-mesh flex min-h-[calc(100dvh-3.5rem)] flex-col fit:h-[calc(100dvh-3.5rem)] fit:overflow-hidden">
        <header className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 px-4 pt-4 pb-3 sm:px-6">
          <div className="min-w-0 flex-1">
            <h1 className="truncate font-display text-xl font-semibold tracking-tight sm:text-2xl">
              {greeting}, <span className="text-gradient-gold">{welcomeName}</span>
            </h1>
            <p className="truncate text-xs text-muted-foreground">
              {metrics.active_matters} active · {metrics.inactive_matters ?? 0} inactive · {metrics.clients} clients{showMoney ? ` · ${formatCurrency(metrics.zoho_outstanding ?? 0)} outstanding` : ""}
            </p>
          </div>
          <SegmentTabs value={tab} onChange={setTab} options={tabs} />
          <div className="flex items-center gap-2">
            <AnalystRoleFilter value={roleFilter} onChange={setRoleFilter} />
            <Button variant="outline" size="sm" onClick={handleExport} className="glass rounded-full border-0" title="Export cases to CSV">
              <Download className="h-3.5 w-3.5 sm:mr-1.5" /><span className="hidden sm:inline">Export</span>
            </Button>
          </div>
        </header>

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

        <main className="min-h-0 flex-1 px-4 pb-4 sm:px-6 fit:overflow-hidden">
          <AnimatePresence mode="wait">
            <motion.div key={tab} className="h-full" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.18 }}>
              {tab === "overview" && (
                <OverviewGrid metrics={metrics} trends={trends} projects={projects} tasks={tasks} statusMix={statusMix}
                  showMoney={showMoney} onDrill={(k) => setDrillKey(k)} onRenew={setRenewalItem} />
              )}
              {tab === "portfolio" && <PortfolioGrid statusMix={statusMix} offices={jurisdictionRows} stages={stageData} />}
              {tab === "financial" && showMoney && <FinanceTab />}
              {tab === "team" && TEAM_ROLES.includes(user?.role) && <TeamTab />}
            </motion.div>
          </AnimatePresence>
        </main>
      </div>
    </AppLayout>
  );
}
