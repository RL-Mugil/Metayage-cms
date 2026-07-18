import { Head, Link } from "@inertiajs/react";
import { useEffect, useState, useCallback } from "react";
import { Briefcase, Users, Wallet, Clock, ArrowUpRight, TrendingUp, Loader2, Download, X, Search, ChevronLeft, ChevronRight, Archive } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { usePage } from "@inertiajs/react";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import { fmtDate } from "@/lib/date-utils";

function formatCurrency(val: number) {
  if (val >= 100000) return `₹ ${(val / 100000).toFixed(1)}L`;
  return `₹ ${val.toLocaleString()}`;
}

type DrillKey = "active_cases" | "inactive_cases" | "active_clients" | "wip" | "revenue";

interface DrillConfig {
  title: string;
  subtitle: string;
  fetchFn: (params: URLSearchParams) => Promise<any>;
  columns: { label: string; render: (row: any) => React.ReactNode }[];
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
                  <tr key={row.id ?? i} className="border-t border-border hover:bg-muted/30">
                    {config.columns.map((col) => (
                      <td key={col.label} className="px-4 py-2.5">{col.render(row)}</td>
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

export default function Dashboard() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [metrics, setMetrics]   = useState<any>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [tasks, setTasks]       = useState<any[]>([]);
  const [invoices, setInvoices] = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [drillKey, setDrillKey] = useState<DrillKey | null>(null);

  useEffect(() => {
    const isAnalyst = user?.role === 'associate';
    const load = async () => {
      // Only Patent Analysts get role-filtered data; all other roles see everything.
      const rf = isAnalyst && roleFilter !== 'all' ? roleFilter : undefined;
      const projectParams = new URLSearchParams({ per_page: '500' });
      if (rf) projectParams.set('role_filter', rf);
      const [m, p, t, i] = await Promise.all([
        api.getDashboardMetrics(rf).catch(() => ({ metrics: {} })),
        api.getProjectsPaged(projectParams).catch(() => []),
        api.getTasks().catch(() => []),
        api.getInvoices().catch(() => []),
      ]);
      setMetrics((m as any)?.metrics ?? {});
      setProjects(Array.isArray(p) ? p : (p as any).data || []);
      setTasks(Array.isArray(t) ? t : (t as any).data || []);
      setInvoices(Array.isArray(i) ? i : (i as any).data || []);
      setLoading(false);
    };
    load();
  }, [roleFilter]);

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
    wip: {
      title: "WIP (Unbilled)",
      subtitle: "Draft & Sent invoices",
      fetchFn: (params) => {
        params.set("status", "Draft");
        return api.getInvoicesPaged(params);
      },
      columns: [
        { label: "Invoice #", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.invoice_code ?? "—"}</span> },
        { label: "Client", render: (r) => <span className="text-sm">{r.client?.company_name ?? "—"}</span> },
        { label: "Amount", render: (r) => <span className="text-sm font-medium">{formatCurrency(parseFloat(r.total_amount ?? 0))}</span> },
        { label: "Status", render: (r) => <span className="text-xs">{r.status}</span> },
        { label: "Due", render: (r) => <span className="text-xs font-mono text-muted-foreground">{fmtD(r.due_date)}</span> },
      ],
    },
    revenue: {
      title: "MTD Revenue",
      subtitle: "Paid invoices",
      fetchFn: (params) => {
        params.set("status", "Paid");
        return api.getInvoicesPaged(params);
      },
      columns: [
        { label: "Invoice #", render: (r) => <span className="font-mono text-xs text-gold font-semibold">{r.invoice_code ?? "—"}</span> },
        { label: "Client", render: (r) => <span className="text-sm">{r.client?.company_name ?? "—"}</span> },
        { label: "Amount", render: (r) => <span className="text-sm font-medium text-success">{formatCurrency(parseFloat(r.total_amount ?? 0))}</span> },
        { label: "Issue Date", render: (r) => <span className="text-xs font-mono text-muted-foreground">{fmtD(r.issue_date)}</span> },
        { label: "Project", render: (r) => <span className="text-xs font-mono text-muted-foreground">{r.project?.project_code ?? "—"}</span> },
      ],
    },
  };

  return (
    <AppLayout>
      <Head title="Dashboard" />
      <PageHeader
        eyebrow="Overview"
        title={`Good morning, ${welcomeName}`}
        description={`${metrics.active_matters} active · ${metrics.inactive_matters ?? 0} inactive · ${metrics.clients} clients · WIP ${formatCurrency(metrics.wip_balance)}`}
        actions={
          <>
            <AnalystRoleFilter value={roleFilter} onChange={setRoleFilter} />
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
          </>
        }
      />

      <div className="px-8 py-6 space-y-6">
        {drillKey && (
          <DashboardDrillModal config={DRILL_CONFIGS[drillKey]} onClose={() => setDrillKey(null)} />
        )}

        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
          <StatCard
            label="Active Cases"
            value={metrics.active_matters.toString()}
            delta={metrics.active_matters_delta ?? undefined}
            trend={metrics.active_matters_delta_trend ?? "up"}
            icon={Briefcase}
            accent="primary"
            onClick={() => setDrillKey("active_cases")}
            subtitle={metrics.distinct_matters != null && metrics.distinct_matters !== metrics.active_matters
              ? `${metrics.distinct_matters} distinct matters`
              : undefined}
          />
          <StatCard label="Inactive Cases" value={(metrics.inactive_matters ?? 0).toString()} icon={Archive} accent="neutral" onClick={() => setDrillKey("inactive_cases")} />
          <StatCard label="Active Clients" value={metrics.clients.toString()} delta={metrics.clients_delta ?? undefined} trend={metrics.clients_delta_trend ?? "up"} icon={Users} accent="gold" onClick={() => setDrillKey("active_clients")} />
          <StatCard label="WIP (unbilled)" value={formatCurrency(metrics.wip_balance)} delta={metrics.wip_delta ?? undefined} trend={metrics.wip_delta_trend ?? "neutral"} icon={Clock} accent="info" onClick={() => setDrillKey("wip")} />
          <StatCard label="MTD Revenue" value={formatCurrency(metrics.received_payments)} delta={metrics.revenue_delta ?? undefined} trend={metrics.revenue_delta_trend ?? "up"} icon={Wallet} accent="success" onClick={() => setDrillKey("revenue")} />
        </div>

        <section className="border-y border-border py-4">
          <div className="mb-3 flex items-center justify-between"><div><h2 className="text-sm font-semibold">Statutory deadline risk</h2><p className="mt-1 text-xs text-muted-foreground">Open deadlines visible within your matter scope</p></div><Link href="/projects" className="text-xs font-medium text-gold hover:underline">Open matters</Link></div>
          <div className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-border bg-border lg:grid-cols-4">
            {[["Overdue", metrics.deadline_risk?.overdue ?? 0], ["Next 7 days", metrics.deadline_risk?.next_7_days ?? 0], ["Unreviewed", metrics.deadline_risk?.unreviewed ?? 0], ["Critical", metrics.deadline_risk?.critical ?? 0]].map(([label, value]) => <div key={label as string} className="bg-background px-4 py-3"><p className="text-xs text-muted-foreground">{label}</p><p className={`mt-1 text-2xl font-semibold ${label === "Overdue" && Number(value) > 0 ? "text-destructive" : ""}`}>{value}</p></div>)}
          </div>
        </section>

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
                  {projects.slice(0, 5).map((p) => {
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
          </Card>

          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display">My tasks</CardTitle>
              <p className="text-xs text-muted-foreground mt-1">{tasks.length} open tasks</p>
            </CardHeader>
            <CardContent className="space-y-3">
              {tasks.slice(0, 5).map((t) => (
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
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-gold" /> Collection Rate
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="font-display text-4xl font-semibold">{metrics.realization_rate}%</div>
              <p className="text-xs text-muted-foreground mt-2">Paid vs. Billed ratio</p>
              <div className="mt-4 h-2 w-full rounded-full bg-muted overflow-hidden">
                <div className="h-full rounded-full bg-gradient-to-r from-primary to-gold"
                  style={{ width: `${Math.min(metrics.realization_rate, 100)}%` }} />
              </div>
            </CardContent>
          </Card>

          <Card className="lg:col-span-2 border-border">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="font-display">Recent invoices</CardTitle>
              <Button asChild variant="ghost" size="sm">
                <Link href="/financial">View all <ArrowUpRight className="ml-1 h-3 w-3" /></Link>
              </Button>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <tbody>
                  {invoices.slice(0, 4).map((i) => (
                    <tr key={i.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs">{i.invoice_code}</td>
                      <td className="px-4 py-3">{i.client?.company_name}</td>
                      <td className="px-4 py-3 font-medium">{formatCurrency(parseFloat(i.total_amount))}</td>
                      <td className="px-4 py-3"><Badge variant={statusColor(i.status)}>{i.status}</Badge></td>
                    </tr>
                  ))}
                  {invoices.length === 0 && <tr><td colSpan={4} className="px-4 py-6 text-center text-muted-foreground text-sm">No invoices yet</td></tr>}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
