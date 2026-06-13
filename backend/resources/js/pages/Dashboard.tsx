import { Head, Link } from "@inertiajs/react";
import { useEffect, useState, useCallback } from "react";
import { Briefcase, Users, Wallet, Clock, ArrowUpRight, TrendingUp, Loader2, Plus, Download, X, Search, ChevronLeft, ChevronRight } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/mock-data";
import { usePage } from "@inertiajs/react";
import { fmtDate } from "@/lib/date-utils";

function formatCurrency(val: number) {
  if (val >= 100000) return `₹ ${(val / 100000).toFixed(1)}L`;
  return `₹ ${val.toLocaleString()}`;
}

const PROJECT_TYPES = [
  "Patent Filing (Utility)", "Patent Filing (Design)", "PCT Filing",
  "Trademark Filing", "Copyright Registration", "Patent Drafting",
  "IP Litigation", "IP Audit", "Technology Transfer",
];

type DrillKey = "active_cases" | "active_clients" | "wip" | "revenue";

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

  const [metrics, setMetrics]   = useState<any>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [tasks, setTasks]       = useState<any[]>([]);
  const [invoices, setInvoices] = useState<any[]>([]);
  const [clients, setClients]   = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [drillKey, setDrillKey] = useState<DrillKey | null>(null);

  // New Case modal
  const [showNewCase, setShowNewCase] = useState(false);
  const [caseForm, setCaseForm] = useState({
    project_name: "", project_type: "Patent Filing (Utility)",
    client_id: "", urgency: "Normal", hard_deadline: "",
  });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  useEffect(() => {
    const load = async () => {
      try {
        const [m, p, t, i, c] = await Promise.all([
          api.getDashboardMetrics(), api.getProjects(),
          api.getTasks(), api.getInvoices(), api.getClients(),
        ]);
        setMetrics(m.metrics);
        setProjects(Array.isArray(p) ? p : (p as any).data || []);
        setTasks(Array.isArray(t) ? t : (t as any).data || []);
        setInvoices(Array.isArray(i) ? i : (i as any).data || []);
        setClients(Array.isArray(c) ? c : (c as any).data || []);
      } catch (e) { console.error(e); }
      finally { setLoading(false); }
    };
    load();
  }, []);

  const handleExport = () => {
    const rows = projects.map(p => ({
      Code: p.project_code, Name: p.project_name, Client: p.client?.company_name,
      Type: p.project_type, Status: p.status, Urgency: p.urgency, Deadline: p.hard_deadline ?? "",
    }));
    downloadCSV(`dashboard-cases-${new Date().toISOString().slice(0,10)}.csv`, rows);
  };

  const handleCreateCase = async () => {
    if (!caseForm.project_name.trim() || !caseForm.client_id) {
      setSaveError("Case name and client are required."); return;
    }
    setSaving(true); setSaveError("");
    try {
      const created = await api.createProject(caseForm);
      setProjects(prev => [created, ...prev]);
      setShowNewCase(false);
      setCaseForm({ project_name: "", project_type: "Patent Filing (Utility)", client_id: "", urgency: "Normal", hard_deadline: "" });
    } catch (e: any) {
      setSaveError(e.message || "Failed to create case.");
    } finally { setSaving(false); }
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
        params.set("status", "Open");
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
        description={`${metrics.active_matters} cases need attention · ${metrics.clients} clients · WIP ${formatCurrency(metrics.wip_balance)}`}
        actions={
          <>
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            <Button onClick={() => setShowNewCase(true)}><Plus className="h-4 w-4 mr-2" />New Case</Button>
          </>
        }
      />

      {/* New Case modal */}
      {showNewCase && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">New Case</h2>
              <button onClick={() => setShowNewCase(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <div className="space-y-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Case Name *</label>
                <input value={caseForm.project_name} onChange={e => setCaseForm(p => ({ ...p, project_name: e.target.value }))}
                  placeholder="e.g. Compact Lithium Cell Patent"
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Client *</label>
                  <select value={caseForm.client_id} onChange={e => setCaseForm(p => ({ ...p, client_id: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select client</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.company_name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Type</label>
                  <select value={caseForm.project_type} onChange={e => setCaseForm(p => ({ ...p, project_type: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    {PROJECT_TYPES.map(t => <option key={t}>{t}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Urgency</label>
                  <select value={caseForm.urgency} onChange={e => setCaseForm(p => ({ ...p, urgency: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    {["Low","Normal","High","Critical"].map(u => <option key={u}>{u}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Hard Deadline</label>
                  <input type="date" value={caseForm.hard_deadline} onChange={e => setCaseForm(p => ({ ...p, hard_deadline: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleCreateCase} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Create Case
              </Button>
              <Button variant="outline" onClick={() => setShowNewCase(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {drillKey && (
          <DashboardDrillModal config={DRILL_CONFIGS[drillKey]} onClose={() => setDrillKey(null)} />
        )}

        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Active Cases" value={metrics.active_matters.toString()} delta="+2 this month" trend="up" icon={Briefcase} accent="primary" onClick={() => setDrillKey("active_cases")} />
          <StatCard label="Active Clients" value={metrics.clients.toString()} delta="+1 this month" trend="up" icon={Users} accent="gold" onClick={() => setDrillKey("active_clients")} />
          <StatCard label="WIP (unbilled)" value={formatCurrency(metrics.wip_balance)} delta="-3.1% vs last week" trend="down" icon={Clock} accent="info" onClick={() => setDrillKey("wip")} />
          <StatCard label="MTD Revenue" value={formatCurrency(metrics.received_payments)} delta="+12.6% YoY" trend="up" icon={Wallet} accent="success" onClick={() => setDrillKey("revenue")} />
        </div>

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
                    const activeStage = p.stages?.find((s: any) => s.status === "In Progress")?.stage_name || "Intake";
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
                <TrendingUp className="h-4 w-4 text-gold" /> Realization rate
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="font-display text-4xl font-semibold">{metrics.realization_rate}%</div>
              <p className="text-xs text-muted-foreground mt-2">Billed vs. worked, 30-day rolling</p>
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
