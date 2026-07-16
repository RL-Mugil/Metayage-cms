import { Head, Link, usePage } from "@inertiajs/react";
import { useEffect, useState, useMemo } from "react";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import { PieChart, Pie, Cell, Tooltip as ReTooltip } from "recharts";
import {
  Loader2, RefreshCw, ChevronDown, MapPin, FileText, Calendar,
  AlertCircle, CreditCard, X, Search, ChevronUp, ExternalLink, ArrowUpDown,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { ProjectDetailPanel } from "@/components/project-detail-panel";

const OFFICE_LABELS: Record<string, string> = {
  IN: "India", US: "USA", JP: "Japan", EP: "EP", KR: "Korea",
  WO: "PCT/WIPO", AU: "Australia", CN: "China", DE: "Germany",
};
const OFFICE_COLORS: Record<string, string> = {
  IN: "#1e3a5f", US: "#4a9fd4", JP: "#7ec8e3", EP: "#e05c3a", KR: "#6b7280",
  WO: "#8b5cf6", AU: "#f59e0b", CN: "#ef4444", DE: "#10b981",
};
const FALLBACK_COLORS = ["#1e3a5f", "#4a9fd4", "#7ec8e3", "#e05c3a", "#6b7280", "#8b5cf6"];

function ol(code: string) { return OFFICE_LABELS[code] ?? code; }
function oc(code: string, idx = 0) { return OFFICE_COLORS[code] ?? FALLBACK_COLORS[idx % FALLBACK_COLORS.length]; }

const PAGE_SIZE = 10;

// ── Interactive Donut ────────────────────────────────────────────────────────
function DonutChart({
  data, total, label, activeCode, onSelect,
}: {
  data: { code: string; name: string; value: number; color: string }[];
  total: number;
  label: string;
  activeCode: string | null;
  onSelect: (code: string | null) => void;
}) {
  const display = data.map(d => ({
    ...d,
    opacity: activeCode && activeCode !== d.code ? 0.35 : 1,
  }));

  return (
    <div className="flex flex-col items-center gap-2">
      <div className="font-medium text-sm text-foreground">{label}</div>
      <div className="relative cursor-pointer">
        <PieChart width={190} height={190}>
          <Pie
            data={display}
            cx={95} cy={95}
            innerRadius={54} outerRadius={82}
            dataKey="value"
            strokeWidth={activeCode ? 2 : 1}
            stroke="transparent"
            onClick={(entry: any) => onSelect(activeCode === entry.code ? null : entry.code)}
          >
            {display.map((d, i) => (
              <Cell
                key={i}
                fill={d.color}
                opacity={d.opacity}
                stroke={activeCode === d.code ? "#fff" : "transparent"}
                strokeWidth={activeCode === d.code ? 2 : 0}
                style={{ cursor: "pointer", transition: "opacity 0.2s" }}
              />
            ))}
          </Pie>
          <ReTooltip
            formatter={(v: number, _: string, props: any) => {
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : "0";
              return [`${v} (${pct}%)`, props.payload?.name ?? ""];
            }}
            contentStyle={{ background: "var(--background)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
          />
        </PieChart>
        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
          <span className="font-display text-3xl font-bold text-foreground">
            {activeCode ? (data.find(d => d.code === activeCode)?.value ?? 0) : total}
          </span>
          {activeCode && <span className="text-[10px] text-muted-foreground mt-0.5">{ol(activeCode)}</span>}
        </div>
      </div>
      <div className="flex flex-wrap justify-center gap-x-3 gap-y-1">
        {data.map(d => (
          <button
            key={d.code}
            onClick={() => onSelect(activeCode === d.code ? null : d.code)}
            className={`flex items-center gap-1 text-xs transition-opacity ${activeCode && activeCode !== d.code ? "opacity-40" : "opacity-100"}`}
          >
            <span className="inline-block h-2 w-2 rounded-full" style={{ background: d.color }} />
            <span className={activeCode === d.code ? "font-semibold text-foreground" : "text-muted-foreground"}>
              {d.name}
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}

// ── Interactive Bar ──────────────────────────────────────────────────────────
function StatusBar({
  name, count, max, active, onClick,
}: {
  name: string; count: number; max: number; active: boolean; onClick: () => void;
}) {
  const pct = Math.max(6, (count / max) * 100);
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-3 w-full group rounded-md px-1 py-0.5 transition-colors ${active ? "bg-gold/10" : "hover:bg-muted/30"}`}
    >
      <span className={`text-xs w-40 shrink-0 text-right truncate ${active ? "text-foreground font-medium" : "text-muted-foreground"}`}>
        {name}
      </span>
      <div className="flex-1 h-6 bg-muted rounded-sm overflow-hidden">
        <div
          className="h-full rounded-sm flex items-center px-2 transition-all duration-300"
          style={{ width: `${pct}%`, background: active ? "#e8a020" : "#4a9fd4" }}
        >
          <span className="text-[10px] font-semibold text-white">{count}</span>
        </div>
      </div>
    </button>
  );
}

// ── Sort header ──────────────────────────────────────────────────────────────
function SortTh({
  col, active, dir, onClick, children,
}: {
  col: string; active: boolean; dir: "asc" | "desc"; onClick: () => void; children: React.ReactNode;
}) {
  return (
    <th
      className="px-3 py-2.5 text-left cursor-pointer select-none hover:text-foreground transition-colors"
      onClick={onClick}
    >
      <span className="flex items-center gap-1">
        {children}
        {active
          ? dir === "asc" ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />
          : <ArrowUpDown className="h-3 w-3 opacity-30" />}
      </span>
    </th>
  );
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function PatentPortfolio() {
  const { props: pageProps } = usePage() as any;
  const role = pageProps.auth?.user?.role;
  // Clients and Patent Analysts get a scoped view with no client selector.
  const isClientUser = ["client", "client_admin", "associate"].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();
  const [data, setData]         = useState<any>(null);
  const [loading, setLoading]   = useState(true);
  const [selectedClientId, setSelectedClientId] = useState<number | null>(null);
  const [clientSearch, setClientSearch]   = useState("");
  const [showClientDrop, setShowClientDrop] = useState(false);

  // Filters
  const [activeOffice, setActiveOffice]   = useState<string | null>(null);
  const [activeStatus, setActiveStatus]   = useState<string | null>(null);
  const [actionSearch, setActionSearch]   = useState("");
  const [sortCol, setSortCol]     = useState<"docket_number" | "filing_date" | "status">("docket_number");
  const [sortDir, setSortDir]     = useState<"asc" | "desc">("asc");
  const [page, setPage]           = useState(1);

  const [detailId, setDetailId] = useState<number | null>(null);

  // Pass role_filter for roles that can be assigned as PCM/SCM/PR.
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const effectiveRf = (rf?: string) => isAnalyst ? (rf ?? roleFilter) : undefined;

  const load = (clientId?: number | null, rf?: string) => {
    setLoading(true);
    setActiveOffice(null);
    setActiveStatus(null);
    setPage(1);
    api.getPatentPortfolioStats(clientId ?? null, effectiveRf(rf))
      .then(setData).catch(() => {}).finally(() => setLoading(false));
  };

  useEffect(() => { load(selectedClientId, roleFilter); }, [roleFilter]);
  useEffect(() => {
    load(null);
    const onVisible = () => { if (document.visibilityState === "visible") load(selectedClientId, roleFilter); };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("focus", onVisible);
    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("focus", onVisible);
    };
  }, []);

  function selectClient(c: any) {
    setSelectedClientId(c?.id ?? null);
    setClientSearch(c ? (c.company_name ?? c.legal_name ?? "") : "");
    setShowClientDrop(false);
    load(c?.id ?? null, roleFilter);
  }

  function toggleSort(col: typeof sortCol) {
    if (sortCol === col) setSortDir(d => d === "asc" ? "desc" : "asc");
    else { setSortCol(col); setSortDir("asc"); }
    setPage(1);
  }

  function toggleOffice(code: string | null) {
    setActiveOffice(prev => prev === code ? null : code);
    setPage(1);
  }

  function toggleStatus(name: string) {
    setActiveStatus(prev => prev === name ? null : name);
    setPage(1);
  }

  // Derived chart data
  const grantedData = useMemo(() => data
    ? Object.entries(data.granted_by_office as Record<string, number>).map(([code, count], i) => ({
        code, name: ol(code), value: Number(count), color: oc(code, i),
      })) : [], [data]);
  const grantedTotal = grantedData.reduce((s, d) => s + d.value, 0);

  const pendingData = useMemo(() => data
    ? Object.entries(data.pending_by_office as Record<string, number>).map(([code, count], i) => ({
        code, name: ol(code), value: Number(count), color: oc(code, i),
      })) : [], [data]);
  const pendingTotal = pendingData.reduce((s, d) => s + d.value, 0);

  const stageData = useMemo(() => data
    ? (data.pending_by_stage as any[]).map((s: any) => ({ name: s.stage_name, count: Number(s.count) }))
    : [], [data]);
  const maxStage = stageData.reduce((m: number, d: any) => Math.max(m, d.count), 0);

  // Filtered + sorted Action Required
  const actionRows: any[] = data?.action_required ?? [];
  const filtered = useMemo(() => {
    let rows = actionRows;
    if (activeOffice)  rows = rows.filter((r: any) => r.patent_office_code === activeOffice);
    if (activeStatus)  rows = rows.filter((r: any) => r.status === activeStatus);
    if (actionSearch)  {
      const q = actionSearch.toLowerCase();
      rows = rows.filter((r: any) =>
        (r.docket_number ?? "").toLowerCase().includes(q) ||
        (r.status ?? "").toLowerCase().includes(q) ||
        (r.current_stage ?? "").toLowerCase().includes(q) ||
        (r.pending_action ?? "").toLowerCase().includes(q)
      );
    }
    rows = [...rows].sort((a: any, b: any) => {
      let av = a[sortCol] ?? ""; let bv = b[sortCol] ?? "";
      if (typeof av === "string") av = av.toLowerCase();
      if (typeof bv === "string") bv = bv.toLowerCase();
      return sortDir === "asc" ? (av < bv ? -1 : av > bv ? 1 : 0) : (av > bv ? -1 : av < bv ? 1 : 0);
    });
    return rows;
  }, [actionRows, activeOffice, activeStatus, actionSearch, sortCol, sortDir]);

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const paged = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const filteredClients = data
    ? (data.clients as any[]).filter((c: any) => {
        const q = clientSearch.toLowerCase();
        return !q || (c.company_name ?? "").toLowerCase().includes(q) || (c.client_code ?? "").toLowerCase().includes(q);
      }).slice(0, 20)
    : [];

  const selectedClientName = selectedClientId
    ? (data?.clients as any[])?.find((c: any) => c.id === selectedClientId)?.company_name ?? "All Clients"
    : "All Clients";

  const hasFilters = activeOffice || activeStatus || actionSearch;

  return (
    <AppLayout>
      <Head title="Patent Portfolio" />
      <PageHeader
        eyebrow="Analytics"
        title="Patent Portfolio"
        description="Click chart segments or bars to filter the Action Required table."
        actions={
          <>
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
            <button
              onClick={() => load(selectedClientId, roleFilter)}
              className="flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
              <RefreshCw className="h-3.5 w-3.5" /> Refresh
            </button>
          </>
        }
      />

      <div className="px-8 py-6 space-y-5">
        {/* Client selector — internal users only; clients are locked to their own data */}
        {!isClientUser && (
        <div className="relative max-w-xs">
          <button
            onClick={() => setShowClientDrop(!showClientDrop)}
            className="flex items-center gap-2 w-full rounded-lg border border-border bg-card px-4 py-2.5 text-sm text-left hover:border-gold/50 transition-colors"
          >
            <FileText className="h-4 w-4 text-muted-foreground flex-shrink-0" />
            <span className="flex-1 font-medium">{selectedClientName}</span>
            <ChevronDown className="h-4 w-4 text-muted-foreground" />
          </button>
          {showClientDrop && (
            <div className="absolute z-50 mt-1 w-full rounded-lg border border-border bg-background shadow-xl">
              <div className="p-2 border-b border-border">
                <input autoFocus type="text" placeholder="Search client…" value={clientSearch}
                  onChange={e => setClientSearch(e.target.value)}
                  className="w-full h-8 rounded-md border border-border bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div className="max-h-56 overflow-y-auto">
                <button onClick={() => selectClient(null)} className="w-full text-left px-3 py-2 text-sm hover:bg-muted/40 text-muted-foreground">All Clients</button>
                {filteredClients.map((c: any) => (
                  <button key={c.id} onClick={() => selectClient(c)}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-center justify-between gap-2">
                    <span className="font-medium">{c.company_name ?? c.legal_name}</span>
                    <span className="text-xs text-muted-foreground font-mono">{c.client_code}</span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
        )}

        {loading ? (
          <div className="flex items-center justify-center py-24">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <>
            {/* Row 1: Donuts + Renewals + Payments */}
            <div className="grid gap-4 lg:grid-cols-4">
              <Card className="lg:col-span-2 border-border">
                <CardContent className="pt-5 pb-4 flex flex-col sm:flex-row items-center justify-around gap-4">
                  <DonutChart data={grantedData} total={grantedTotal} label="Granted Patents"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                  <div className="w-px h-40 bg-border hidden sm:block" />
                  <DonutChart data={pendingData} total={pendingTotal} label="Pending Patents"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                </CardContent>
                {activeOffice && (
                  <div className="px-4 pb-3 flex items-center gap-2">
                    <span className="text-xs text-muted-foreground">Filtering by:</span>
                    <span className="flex items-center gap-1 rounded-full bg-gold/15 text-gold border border-gold/30 px-2 py-0.5 text-xs font-medium">
                      <MapPin className="h-3 w-3" /> {ol(activeOffice)}
                      <button onClick={() => setActiveOffice(null)}><X className="h-3 w-3 ml-0.5" /></button>
                    </span>
                  </div>
                )}
              </Card>

              {/* Upcoming Renewals */}
              <Card className="border-border">
                <CardHeader className="pb-2">
                  <CardTitle className="font-display text-sm flex items-center gap-2">
                    <Calendar className="h-4 w-4 text-gold" /> Upcoming Renewals
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  {(data.upcoming_renewals as any[]).length === 0 ? (
                    <p className="text-xs text-muted-foreground px-4 py-6">No upcoming renewals.</p>
                  ) : (data.upcoming_renewals as any[]).map((r: any, i: number) => (
                    <button key={i} onClick={() => r.id && setDetailId(r.id)}
                      className="block w-full text-left px-4 py-3 border-b border-border last:border-0 hover:bg-muted/20 transition-colors group">
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-1.5 text-xs font-mono font-medium text-foreground group-hover:text-gold transition-colors">
                          <FileText className="h-3 w-3 text-muted-foreground" />
                          {r.docket_number ?? r.project_name ?? "—"}
                        </div>
                        <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                          <MapPin className="h-3 w-3" />{ol(r.patent_office_code ?? "")}
                        </div>
                      </div>
                      <div className="flex items-center justify-between mt-1.5">
                        <span className="text-[10px] text-muted-foreground">Due Date</span>
                        <span className="text-xs font-medium text-foreground">
                          {r.hard_deadline ? new Date(r.hard_deadline).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "2-digit" }) : "—"}
                        </span>
                      </div>
                    </button>
                  ))}
                </CardContent>
              </Card>

              {/* Pending Payments */}
              <Card className="border-border">
                <CardHeader className="pb-2">
                  <CardTitle className="font-display text-sm flex items-center gap-2">
                    <CreditCard className="h-4 w-4 text-gold" /> Pending Payments
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  {(data.pending_invoices as any[]).length === 0 ? (
                    <p className="text-xs text-muted-foreground px-4 py-6">No pending payments.</p>
                  ) : (data.pending_invoices as any[]).map((inv: any, i: number) => (
                    <div key={i} className="px-4 py-3 border-b border-border last:border-0 hover:bg-muted/20 transition-colors">
                      <div className="flex items-center justify-between gap-2 text-[10px] text-muted-foreground mb-1">
                        <div className="flex items-center gap-1">
                          <FileText className="h-3 w-3" />
                          <span>Docket <span className="font-mono text-foreground font-medium">{inv.docket_number}</span></span>
                        </div>
                        <span>{inv.created_at ? new Date(inv.created_at).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "2-digit" }) : "—"}</span>
                      </div>
                      <div className="flex items-center justify-between rounded-md bg-gold/10 px-2.5 py-1.5">
                        <span className="text-[10px] text-muted-foreground">Amount Due</span>
                        <span className="text-xs font-semibold text-gold">
                          {inv.currency ?? "INR"} {Number(inv.balance_due ?? inv.total_amount ?? 0).toLocaleString("en-IN")}
                        </span>
                      </div>
                    </div>
                  ))}
                  {(data.pending_invoices as any[]).length > 0 && (
                    <div className="px-4 py-2 text-right">
                      <a href="/financial" className="text-xs text-gold hover:underline">View All →</a>
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>

            {/* Row 2: Status bars + Action Required */}
            <div className="grid gap-4 lg:grid-cols-2">
              {/* Pending by Status — clickable bars */}
              <Card className="border-border">
                <CardHeader className="pb-1">
                  <div className="flex items-center justify-between">
                    <CardTitle className="font-display text-sm">Pending Patents by Status</CardTitle>
                    {activeStatus && (
                      <button onClick={() => setActiveStatus(null)}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                        <X className="h-3 w-3" /> Clear
                      </button>
                    )}
                  </div>
                  {activeStatus && (
                    <p className="text-[10px] text-muted-foreground">Click again to clear filter</p>
                  )}
                </CardHeader>
                <CardContent className="pt-2">
                  {stageData.length === 0 ? (
                    <p className="text-xs text-muted-foreground py-6 text-center">No pending patents.</p>
                  ) : (
                    <div className="space-y-1.5">
                      {stageData.map((s: any) => (
                        <StatusBar key={s.name} name={s.name} count={s.count} max={maxStage}
                          active={activeStatus === s.name} onClick={() => toggleStatus(s.name)} />
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Action Required — filterable, sortable, searchable */}
              <Card className="border-border flex flex-col">
                <CardHeader className="pb-2 flex-shrink-0">
                  <div className="flex items-center justify-between gap-2">
                    <CardTitle className="font-display text-sm flex items-center gap-2">
                      <AlertCircle className="h-4 w-4 text-amber-500" />
                      Action Required
                      {filtered.length !== actionRows.length && (
                        <span className="text-[10px] text-gold font-normal">{filtered.length} of {actionRows.length}</span>
                      )}
                    </CardTitle>
                    {hasFilters && (
                      <button onClick={() => { setActiveOffice(null); setActiveStatus(null); setActionSearch(""); setPage(1); }}
                        className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1">
                        <X className="h-3 w-3" /> Clear all
                      </button>
                    )}
                  </div>
                  {/* Active filter chips */}
                  {hasFilters && (
                    <div className="flex flex-wrap gap-1.5 mt-1">
                      {activeOffice && (
                        <span className="flex items-center gap-1 rounded-full bg-gold/15 text-gold border border-gold/30 px-2 py-0.5 text-[10px] font-medium">
                          <MapPin className="h-2.5 w-2.5" />{ol(activeOffice)}
                          <button onClick={() => setActiveOffice(null)}><X className="h-2.5 w-2.5" /></button>
                        </span>
                      )}
                      {activeStatus && (
                        <span className="flex items-center gap-1 rounded-full bg-blue-500/15 text-blue-400 border border-blue-500/30 px-2 py-0.5 text-[10px] font-medium">
                          {activeStatus}
                          <button onClick={() => setActiveStatus(null)}><X className="h-2.5 w-2.5" /></button>
                        </span>
                      )}
                    </div>
                  )}
                  {/* Search */}
                  <div className="relative mt-1">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground" />
                    <input type="text" placeholder="Search docket or status…" value={actionSearch}
                      onChange={e => { setActionSearch(e.target.value); setPage(1); }}
                      className="w-full h-7 pl-7 pr-3 rounded-md border border-border bg-background text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                </CardHeader>
                <CardContent className="p-0 flex-1 overflow-auto">
                  {filtered.length === 0 ? (
                    <p className="text-xs text-muted-foreground px-4 py-8 text-center">No matching actions.</p>
                  ) : (
                    <>
                      <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-muted/60 backdrop-blur text-[10px] uppercase tracking-wider text-muted-foreground">
                          <tr>
                            <SortTh col="docket_number" active={sortCol === "docket_number"} dir={sortDir} onClick={() => toggleSort("docket_number")}>Docket No.</SortTh>
                            <SortTh col="filing_date" active={sortCol === "filing_date"} dir={sortDir} onClick={() => toggleSort("filing_date")}>Filing Date</SortTh>
                            <SortTh col="status" active={sortCol === "status"} dir={sortDir} onClick={() => toggleSort("status")}>Status</SortTh>
                            <th className="px-3 py-2.5 text-left">Current Stage</th>
                            <th className="px-3 py-2.5 text-left">Pending Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          {paged.map((row: any, i: number) => (
                            <tr key={i} className="border-t border-border hover:bg-muted/20 transition-colors group">
                              <td className="px-3 py-2.5">
                                <button onClick={() => row.id && setDetailId(row.id)}
                                  className="font-mono font-medium text-foreground group-hover:text-gold transition-colors flex items-center gap-1">
                                  {row.docket_number}
                                  {row.id && <ExternalLink className="h-2.5 w-2.5 opacity-0 group-hover:opacity-100 transition-opacity" />}
                                </button>
                              </td>
                              <td className="px-3 py-2.5 text-muted-foreground">
                                {row.filing_date ? new Date(row.filing_date).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "2-digit" }) : "—"}
                              </td>
                              <td className="px-3 py-2.5">
                                <button
                                  onClick={() => toggleStatus(row.status)}
                                  className={`rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors ${
                                    activeStatus === row.status
                                      ? "bg-gold/20 text-gold border border-gold/40"
                                      : row.status === "In Progress" ? "bg-blue-500/15 text-blue-400"
                                      : row.status === "On Hold" ? "bg-amber-500/15 text-amber-400"
                                      : "bg-muted text-muted-foreground hover:bg-muted/80"
                                  }`}
                                >
                                  {row.status}
                                </button>
                              </td>
                              <td className="px-3 py-2.5 text-xs text-muted-foreground max-w-[180px] truncate" title={row.current_stage ?? ""}>
                                {row.current_stage ?? "—"}
                              </td>
                              <td className="px-3 py-2.5 text-muted-foreground">{row.pending_action}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                      {totalPages > 1 && (
                        <div className="flex items-center justify-between px-3 py-2 border-t border-border text-xs text-muted-foreground">
                          <span>{filtered.length} results · page {page}/{totalPages}</span>
                          <div className="flex gap-1">
                            <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                              className="px-2 py-1 rounded border border-border hover:bg-muted disabled:opacity-40">‹</button>
                            <button disabled={page === totalPages} onClick={() => setPage(p => p + 1)}
                              className="px-2 py-1 rounded border border-border hover:bg-muted disabled:opacity-40">›</button>
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </CardContent>
              </Card>
            </div>
          </>
        )}
      </div>
      {detailId !== null && (
        <ProjectDetailPanel projectId={detailId} onClose={() => setDetailId(null)} />
      )}
    </AppLayout>
  );
}
