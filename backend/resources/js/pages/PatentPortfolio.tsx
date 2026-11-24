import { Head, Link, router, usePage } from "@inertiajs/react";
import { useEffect, useState, useMemo } from "react";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
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
import { DonutChart, StatusBar, officeLabel as ol, officeColor as oc } from "@/components/portfolio-charts";
import { SectionDialog } from "@/components/portfolio/section-dialog";

/** Each section (renewals, payments, stages, action required) shows 5 rows per page. */
const PAGE_SIZE = 5;

/** Previous / next for a section's list; hidden when everything fits on one page. */
function SectionPager({ page, total, onPage, size = PAGE_SIZE }: { page: number; total: number; onPage: (page: number) => void; size?: number }) {
  const pages = Math.max(1, Math.ceil(total / size));
  if (pages <= 1) return null;
  return (
    <div className="flex items-center justify-between border-t border-border px-4 py-2 text-xs text-muted-foreground">
      <span>{(page - 1) * size + 1}–{Math.min(page * size, total)} of {total}</span>
      <div className="flex gap-1">
        <button disabled={page <= 1} onClick={() => onPage(page - 1)}
          className="rounded border border-border px-2 py-1 hover:bg-muted disabled:opacity-40">‹</button>
        <span className="px-1 py-1">{page}/{pages}</span>
        <button disabled={page >= pages} onClick={() => onPage(page + 1)}
          className="rounded border border-border px-2 py-1 hover:bg-muted disabled:opacity-40">›</button>
      </div>
    </div>
  );
}

const pageOf = <T,>(rows: T[], page: number, size = PAGE_SIZE): T[] => rows.slice((page - 1) * size, page * size);

/** Rows per page that fill each card: renewal rows are shorter than payment rows, stage bars shorter still. */
const RENEWAL_PAGE = 6;

const monthLabel = (key: string) => new Date(`${key}-01T00:00:00`).toLocaleDateString("en-IN", { month: "short", year: "numeric" });
const compactInr = (v: number) => (v >= 100000 ? `₹${(v / 100000).toFixed(1)}L` : `₹${Math.round(v / 1000)}k`);

/** Overdue + the next 12 months as tiles: fees due and their estimated cost. Click a tile to list it. */
function RenewalCalendar({ rows, onPick }: { rows: any[]; onPick: (month: string) => void }) {
  const start = new Date(); start.setDate(1);
  const months = Array.from({ length: 12 }, (_, i) => {
    const d = new Date(start.getFullYear(), start.getMonth() + i, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
  });
  const tally = (list: any[]) => ({
    count: list.length,
    amount: list.reduce((sum, r) => sum + (r.estimate?.currency === "INR" ? Number(r.estimate.amount) : 0), 0),
  });
  const overdue = tally(rows.filter((r) => r.days < 0));
  const tiles = months.map((key) => ({ key, ...tally(rows.filter((r) => r.days >= 0 && String(r.due_date ?? "").startsWith(key))) }));
  const max = Math.max(1, ...tiles.map((t) => t.count));
  return (
    <div className="grid flex-1 grid-cols-3 gap-1.5 p-3 sm:grid-cols-4">
      <button onClick={() => overdue.count && onPick("overdue")} disabled={!overdue.count}
        className={`rounded-md border p-2 text-left ${overdue.count ? "border-red-500/40 bg-red-500/10 hover:bg-red-500/20" : "border-border opacity-50"}`}>
        <p className="text-[10px] font-semibold text-red-500">Overdue</p>
        <p className="text-lg font-semibold leading-tight">{overdue.count}</p>
        {overdue.amount > 0 && <p className="text-[9px] text-muted-foreground">≈ {compactInr(overdue.amount)}</p>}
      </button>
      {tiles.map((t) => (
        <button key={t.key} onClick={() => t.count && onPick(t.key)} disabled={!t.count}
          className="rounded-md border border-border p-2 text-left transition-colors hover:border-gold/50 disabled:cursor-default"
          style={{ backgroundColor: t.count ? `hsl(45 93% 47% / ${0.06 + (t.count / max) * 0.3})` : undefined }}>
          <p className="text-[10px] text-muted-foreground">{monthLabel(t.key)}</p>
          <p className="text-lg font-semibold leading-tight">{t.count}</p>
          {t.amount > 0 && <p className="text-[9px] text-muted-foreground">≈ {compactInr(t.amount)}</p>}
        </button>
      ))}
    </div>
  );
}
const STAGE_PAGE = 8;

const fmtShort = (value?: string | null) =>
  value ? new Date(value).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "2-digit" }) : "—";

/** Section header: title on the left; search and "View all" in the top-right corner. */
function SectionHead({ icon, title, badge, search, onSearch, onViewAll, extra }: {
  icon?: React.ReactNode
  title: string
  badge?: string
  search: string
  onSearch: (value: string) => void
  onViewAll: () => void
  extra?: React.ReactNode
}) {
  return (
    <CardHeader className="pb-2 flex-shrink-0">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <CardTitle className="font-display text-sm flex items-center gap-2">
          {icon}{title}
          {badge && <span className="text-[10px] text-gold font-normal">{badge}</span>}
        </CardTitle>
        <div className="flex items-center gap-2">
          {extra}
          <div className="relative">
            <Search className="pointer-events-none absolute left-2 top-1/2 h-3 w-3 -translate-y-1/2 text-muted-foreground" />
            <input value={search} onChange={(e) => onSearch(e.target.value)} placeholder="Search…"
              className="h-7 w-32 rounded-md border border-border bg-background pl-6 pr-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold sm:w-40" />
          </div>
          <button onClick={onViewAll} className="whitespace-nowrap rounded-md border border-border px-2 py-1 text-xs text-gold hover:bg-gold/10">View all</button>
        </div>
      </div>
    </CardHeader>
  );
}

/** Lower-cased text of the given fields, for the section search boxes. */
const matches = (row: any, q: string, fields: string[]) =>
  !q || fields.some((f) => String(f.split(".").reduce((v: any, k) => v?.[k], row) ?? "").toLowerCase().includes(q));

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
  const [activeStage, setActiveStage]     = useState<string | null>(null);
  const [actionSearch, setActionSearch]   = useState("");
  const [sortCol, setSortCol]     = useState<"docket_number" | "filing_date" | "status">("docket_number");
  const [sortDir, setSortDir]     = useState<"asc" | "desc">("asc");
  const [page, setPage]           = useState(1);
  const [renewalPage, setRenewalPage] = useState(1);
  const [paymentPage, setPaymentPage] = useState(1);
  const [stagePage, setStagePage]     = useState(1);
  const [renewalSearch, setRenewalSearch] = useState("");
  const [renewalView, setRenewalView] = useState<"list" | "calendar">("list");
  /** "YYYY-MM", "overdue", or null (all) — the View all dialog's month filter from the calendar. */
  const [renewalMonth, setRenewalMonth] = useState<string | null>(null);
  const [paymentSearch, setPaymentSearch] = useState("");
  const [stageSearch, setStageSearch]     = useState("");
  const [viewAll, setViewAll] = useState<"renewals" | "stages" | "actions" | null>(null);

  const [detailId, setDetailId] = useState<number | null>(null);

  // Pass role_filter for roles that can be assigned as PCM/SCM/PR.
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const effectiveRf = (rf?: string) => isAnalyst ? (rf ?? roleFilter) : undefined;

  const load = (clientId?: number | null, rf?: string) => {
    setLoading(true);
    setActiveOffice(null);
    setActiveStatus(null);
    setActiveStage(null);
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

  function toggleStage(name: string) {
    setActiveStage(prev => prev === name ? null : name);
    setPage(1);
  }

  // Derived chart data — four mutually-exclusive donuts by status bucket (one entry per
  // unique case, from the backend's case-level dedup), so their totals always sum to
  // total_unique_cases: Granted, Active (Open/In Progress/On Hold), Refused/Abandoned,
  // Closed/Completed.
  const officeSeries = (key: string) => (data
    ? Object.entries((data[key] ?? {}) as Record<string, number>).map(([code, count], i) => ({
        code, name: ol(code), value: Number(count), color: oc(code, i),
      })) : []);

  const grantedData = useMemo(() => officeSeries("granted_by_office"), [data]);
  const grantedTotal = grantedData.reduce((s, d) => s + d.value, 0);

  const activeData = useMemo(() => officeSeries("active_by_office"), [data]);
  const activeTotal = activeData.reduce((s, d) => s + d.value, 0);

  const refusedData = useMemo(() => officeSeries("refused_by_office"), [data]);
  const refusedTotal = refusedData.reduce((s, d) => s + d.value, 0);

  const closedData = useMemo(() => officeSeries("closed_by_office"), [data]);
  const closedTotal = closedData.reduce((s, d) => s + d.value, 0);

  const chartsTotal = grantedTotal + activeTotal + refusedTotal + closedTotal;

  // A new client / role filter starts every section from its first page.
  useEffect(() => { setRenewalPage(1); setPaymentPage(1); setStagePage(1); }, [data]);

  const stageData = useMemo(() => data
    ? (data.pending_by_stage as any[]).map((s: any) => ({ name: s.stage_name, count: Number(s.count) }))
    : [], [data]);
  const maxStage = stageData.reduce((m: number, d: any) => Math.max(m, d.count), 0);

  // Each section's own search (paging restarts at 1 when it changes).
  const renewalRows: any[] = useMemo(() => ((data?.upcoming_renewals ?? []) as any[])
    .filter((r) => matches(r, renewalSearch.trim().toLowerCase(), ["docket_number", "project_name", "patent_office_code", "label", "client.company_name", "client.legal_name", "application_number"])),
  [data, renewalSearch]);
  const paymentRows: any[] = useMemo(() => ((data?.pending_invoices ?? []) as any[])
    .filter((r) => matches(r, paymentSearch.trim().toLowerCase(), ["invoice_code", "docket_number", "status", "currency"])),
  [data, paymentSearch]);
  const stageRows: any[] = useMemo(() => stageData
    .filter((s: any) => matches(s, stageSearch.trim().toLowerCase(), ["name"])),
  [stageData, stageSearch]);

  /** Go to a page in the app (the case on a tab, or Pending Payments). */
  const goTo = (url: string) => router.visit(url);
  /** An Action Required row opens where its action is done. */
  const goToAction = (row: any) => {
    if (row.action_target?.url) goTo(row.action_target.url);
    else if (row.id) setDetailId(row.id);
  };

  // Filtered + sorted Action Required
  const actionRows: any[] = data?.action_required ?? [];
  const filtered = useMemo(() => {
    let rows = actionRows;
    if (activeOffice)  rows = rows.filter((r: any) => r.patent_office_code === activeOffice);
    if (activeStatus)  rows = rows.filter((r: any) => r.status === activeStatus);
    if (activeStage)   rows = rows.filter((r: any) => r.current_stage === activeStage);
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
  }, [actionRows, activeOffice, activeStatus, activeStage, actionSearch, sortCol, sortDir]);

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

  const hasFilters = activeOffice || activeStatus || activeStage || actionSearch;
  // The client picked above, so "View all" on Pending Payments opens that client's list.
  const selectedClientCode: string | null = selectedClientId
    ? ((data?.clients as any[])?.find((c: any) => c.id === selectedClientId)?.client_code ?? null)
    : null;

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
            {/* Row 1: Status donuts — Granted / Active / Refused-Abandoned / Closed-Completed,
                one card each, aligned left-to-right in a single line. Each case (deduped by
                client+invention+office) lands in exactly one chart, so the four totals always
                sum to Total Cases. */}
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">
                Total Cases: <span className="font-semibold text-foreground">{data.total_unique_cases}</span>
                <span className="mx-1.5 text-border">·</span>
                Chart sum: <span className={`font-semibold ${chartsTotal === data.total_unique_cases ? "text-foreground" : "text-destructive"}`}>{chartsTotal}</span>
              </span>
              {activeOffice && (
                <span className="flex items-center gap-1 rounded-full bg-gold/15 text-gold border border-gold/30 px-2 py-0.5 text-xs font-medium">
                  <MapPin className="h-3 w-3" /> {ol(activeOffice)}
                  <button onClick={() => setActiveOffice(null)}><X className="h-3 w-3 ml-0.5" /></button>
                </span>
              )}
            </div>
            <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
              <Card className="border-border">
                <CardContent className="pt-5 pb-4 flex items-center justify-center">
                  <DonutChart data={grantedData} total={grantedTotal} label="Granted"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="pt-5 pb-4 flex items-center justify-center">
                  <DonutChart data={activeData} total={activeTotal} label="Active"
                    sublabel="Open · In Progress · On Hold"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="pt-5 pb-4 flex items-center justify-center">
                  <DonutChart data={refusedData} total={refusedTotal} label="Refused / Abandoned"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                </CardContent>
              </Card>
              <Card className="border-border">
                <CardContent className="pt-5 pb-4 flex items-center justify-center">
                  <DonutChart data={closedData} total={closedTotal} label="Closed / Completed"
                    activeCode={activeOffice} onSelect={toggleOffice} />
                </CardContent>
              </Card>
            </div>

            {/* Row 2: Renewals + Payments — same height side by side */}
            <div className="grid gap-4 lg:grid-cols-2 items-stretch">
              {/* Upcoming Renewals */}
              <Card className="border-border h-full flex flex-col">
                <SectionHead icon={<Calendar className="h-4 w-4 text-gold" />} title="Upcoming Renewals"
                  search={renewalSearch} onSearch={(v) => { setRenewalSearch(v); setRenewalPage(1); }} onViewAll={() => { setRenewalMonth(null); setViewAll("renewals"); }}
                  extra={
                    <div className="flex rounded-md border border-border p-0.5 text-[10px]">
                      {(["list", "calendar"] as const).map((v) => (
                        <button key={v} onClick={() => setRenewalView(v)} className={`rounded px-1.5 py-0.5 capitalize ${renewalView === v ? "bg-muted font-medium" : "text-muted-foreground"}`}>{v}</button>
                      ))}
                    </div>
                  } />
                <CardContent className="p-0 flex-1 flex flex-col">
                  {renewalView === "calendar" ? (
                    <RenewalCalendar rows={renewalRows} onPick={(month) => { setRenewalMonth(month); setViewAll("renewals"); }} />
                  ) : (<>
                  <div className="flex-1">
                    {renewalRows.length === 0 ? (
                      <p className="text-xs text-muted-foreground px-4 py-6">{renewalSearch ? "No matching renewals." : "No upcoming renewals."}</p>
                    ) : pageOf(renewalRows, renewalPage, RENEWAL_PAGE).map((r: any, i: number) => (
                      <button key={i} onClick={() => r.id && goTo(`/projects/${r.id}?tab=deadlines`)}
                        className="block w-full text-left px-4 py-3 border-b border-border last:border-0 hover:bg-muted/20 transition-colors group">
                        <div className="flex items-center justify-between gap-2">
                          <div className="flex items-center gap-1.5 text-xs font-mono font-medium text-foreground group-hover:text-gold transition-colors">
                            <FileText className="h-3 w-3 text-muted-foreground" />
                            {r.docket_number ?? r.project_name ?? "—"}
                            <ExternalLink className="h-2.5 w-2.5 opacity-0 group-hover:opacity-100 transition-opacity" />
                          </div>
                          <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                            <MapPin className="h-3 w-3" />{ol(r.patent_office_code ?? "")}
                          </div>
                        </div>
                        <div className="flex items-center justify-between gap-2 mt-1.5">
                          <span className="text-[10px] text-muted-foreground truncate">{r.label ? `${r.label} · ` : ""}{r.client?.legal_name ?? r.client?.company_name ?? r.project_name ?? ""}</span>
                          <span className="flex items-center gap-1.5 text-xs font-medium text-foreground whitespace-nowrap">
                            {typeof r.days === "number" && (r.days < 0
                              ? <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-red-500">{-r.days}d overdue</span>
                              : r.days <= 60 ? <span className="rounded bg-amber-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-amber-600">in {r.days}d</span> : null)}
                            Due {fmtShort(r.due_date ?? r.hard_deadline)}
                          </span>
                        </div>
                      </button>
                    ))}
                  </div>
                  <SectionPager page={renewalPage} total={renewalRows.length} onPage={setRenewalPage} size={RENEWAL_PAGE} />
                  </>)}
                </CardContent>
              </Card>

              {/* Pending Payments */}
              <Card className="border-border h-full flex flex-col">
                <SectionHead icon={<CreditCard className="h-4 w-4 text-gold" />} title="Pending Payments"
                  search={paymentSearch} onSearch={(v) => { setPaymentSearch(v); setPaymentPage(1); }}
                  onViewAll={() => goTo(`/pending-payments${selectedClientCode ? `?search=${encodeURIComponent(selectedClientCode)}` : ""}`)} />
                <CardContent className="p-0 flex-1 flex flex-col">
                  <div className="flex-1">
                    {paymentRows.length === 0 ? (
                      <p className="text-xs text-muted-foreground px-4 py-6">{paymentSearch ? "No matching payments." : "No pending payments."}</p>
                    ) : pageOf(paymentRows, paymentPage).map((inv: any, i: number) => {
                      const overdue = inv.due_date ? Math.floor((Date.now() - new Date(inv.due_date).getTime()) / 86400000) : 0;
                      return (
                        <button key={i} onClick={() => goTo(`/pending-payments?search=${encodeURIComponent(inv.invoice_code ?? inv.docket_number ?? "")}`)}
                          className="block w-full text-left px-4 py-3 border-b border-border last:border-0 hover:bg-muted/20 transition-colors group">
                          <div className="flex items-center justify-between gap-2 text-[10px] text-muted-foreground mb-1">
                            <div className="flex items-center gap-1">
                              <FileText className="h-3 w-3" />
                              <span className="font-mono text-foreground font-medium group-hover:text-gold">{inv.invoice_code ?? inv.docket_number}</span>
                              {overdue > 0 && <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-red-500">{overdue}d overdue</span>}
                            </div>
                            <span>{inv.due_date ? `Due ${fmtShort(inv.due_date)}` : fmtShort(inv.created_at)}</span>
                          </div>
                          <div className="flex items-center justify-between rounded-md bg-gold/10 px-2.5 py-1.5">
                            <span className="text-[10px] text-muted-foreground">Amount Due</span>
                            <span className="text-xs font-semibold text-gold">
                              {inv.currency ?? "INR"} {Number(inv.balance_due ?? inv.total_amount ?? 0).toLocaleString("en-IN")}
                            </span>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                  <SectionPager page={paymentPage} total={paymentRows.length} onPage={setPaymentPage} />
                </CardContent>
              </Card>
            </div>

            {/* Row 3: Pending by Stage + Action Required — same height side by side */}
            <div className="grid gap-4 lg:grid-cols-2 items-stretch">
              {/* Pending by Stage — clickable bars filter Action Required */}
              <Card className="border-border h-full flex flex-col">
                <SectionHead title="Pending Patents by Stage" search={stageSearch}
                  onSearch={(v) => { setStageSearch(v); setStagePage(1); }} onViewAll={() => setViewAll("stages")}
                  extra={activeStage ? (
                    <button onClick={() => setActiveStage(null)} className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                      <X className="h-3 w-3" /> Clear
                    </button>
                  ) : null} />
                <CardContent className="pt-2 flex-1 flex flex-col">
                  {stageRows.length === 0 ? (
                    <p className="text-xs text-muted-foreground py-6 text-center flex-1">{stageSearch ? "No matching stages." : "No pending patents."}</p>
                  ) : (
                    <div className="space-y-2 flex-1">
                      {pageOf(stageRows, stagePage, STAGE_PAGE).map((s: any) => (
                        <StatusBar key={s.name} name={s.name} count={s.count} max={maxStage}
                          active={activeStage === s.name} onClick={() => toggleStage(s.name)} />
                      ))}
                      {activeStage && <p className="pt-1 text-[10px] text-muted-foreground">Filtering Action Required by "{activeStage}" · click again to clear</p>}
                    </div>
                  )}
                  <div className="-mx-6 -mb-6 mt-2"><SectionPager page={stagePage} total={stageRows.length} onPage={setStagePage} size={STAGE_PAGE} /></div>
                </CardContent>
              </Card>

              {/* Action Required — filterable, sortable, searchable; a row opens its action */}
              <Card className="border-border h-full flex flex-col">
                <SectionHead icon={<AlertCircle className="h-4 w-4 text-amber-500" />} title="Action Required"
                  badge={filtered.length !== actionRows.length ? `${filtered.length} of ${actionRows.length}` : undefined}
                  search={actionSearch} onSearch={(v) => { setActionSearch(v); setPage(1); }} onViewAll={() => setViewAll("actions")}
                  extra={hasFilters ? (
                    <button onClick={() => { setActiveOffice(null); setActiveStatus(null); setActiveStage(null); setActionSearch(""); setPage(1); }}
                      className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1">
                      <X className="h-3 w-3" /> Clear all
                    </button>
                  ) : null} />
                {(activeOffice || activeStatus || activeStage) && (
                  <div className="flex flex-wrap gap-1.5 px-6 pb-2">
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
                    {activeStage && (
                      <span className="flex items-center gap-1 rounded-full bg-purple-500/15 text-purple-400 border border-purple-500/30 px-2 py-0.5 text-[10px] font-medium">
                        {activeStage}
                        <button onClick={() => setActiveStage(null)}><X className="h-2.5 w-2.5" /></button>
                      </span>
                    )}
                  </div>
                )}
                <CardContent className="p-0 flex-1 flex flex-col">
                  {filtered.length === 0 ? (
                    <p className="text-xs text-muted-foreground px-4 py-8 text-center flex-1">No matching actions.</p>
                  ) : (
                    <div className="flex-1 overflow-y-auto overflow-x-hidden">
                      <table className="w-full table-fixed text-xs">
                        <colgroup><col className="w-[30%]" /><col className="w-[17%]" /><col className="w-[53%]" /></colgroup>
                        <thead className="sticky top-0 bg-muted/60 backdrop-blur text-[10px] uppercase tracking-wider text-muted-foreground">
                          <tr>
                            <SortTh col="docket_number" active={sortCol === "docket_number"} dir={sortDir} onClick={() => toggleSort("docket_number")}>Docket No.</SortTh>
                            <SortTh col="status" active={sortCol === "status"} dir={sortDir} onClick={() => toggleSort("status")}>Status</SortTh>
                            <th className="px-3 py-2.5 text-left">Stage · next action</th>
                          </tr>
                        </thead>
                        <tbody>
                          {paged.map((row: any, i: number) => (
                            <tr key={i} onClick={() => goToAction(row)} title={row.plain_status ?? undefined} className="border-t border-border hover:bg-muted/20 transition-colors group cursor-pointer">
                              <td className="px-3 py-2.5 truncate">
                                <button onClick={(e) => { e.stopPropagation(); if (row.id) setDetailId(row.id); }} title="Quick look at the case"
                                  className="font-mono font-medium text-foreground hover:text-gold hover:underline transition-colors truncate max-w-full">
                                  {row.docket_number}
                                </button>
                              </td>
                              <td className="px-3 py-2.5">
                                <button
                                  onClick={(e) => { e.stopPropagation(); toggleStatus(row.status); }}
                                  className={`rounded px-1.5 py-0.5 text-[10px] font-medium leading-tight whitespace-nowrap transition-colors ${
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
                              <td className="px-3 py-2">
                                <div className="truncate text-foreground" title={row.current_stage ?? ""}>{row.current_stage ?? "—"}</div>
                                <div className="flex items-center justify-between gap-2">
                                  <span className="truncate text-[10px] text-muted-foreground" title={row.pending_action}>{row.pending_action}</span>
                                  <span className="shrink-0 text-[10px] font-medium text-gold group-hover:underline">{row.action_target?.label ?? "Open"} →</span>
                                </div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {totalPages > 1 && (
                    <div className="flex items-center justify-between px-3 py-2 border-t border-border text-xs text-muted-foreground">
                      <span>{(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, filtered.length)} of {filtered.length}</span>
                      <div className="flex gap-1">
                        <button disabled={page === 1} onClick={() => setPage(p => p - 1)}
                          className="px-2 py-1 rounded border border-border hover:bg-muted disabled:opacity-40">‹</button>
                        <span className="px-1 py-1">{page}/{totalPages}</span>
                        <button disabled={page === totalPages} onClick={() => setPage(p => p + 1)}
                          className="px-2 py-1 rounded border border-border hover:bg-muted disabled:opacity-40">›</button>
                      </div>
                    </div>
                  )}
                </CardContent>
              </Card>
            </div>

            {viewAll === "renewals" && (
              <SectionDialog title={renewalMonth ? `Renewals · ${renewalMonth === "overdue" ? "Overdue" : monthLabel(renewalMonth)}` : "Upcoming Renewals"}
                rows={(data.upcoming_renewals as any[]).filter((r: any) => !renewalMonth || (renewalMonth === "overdue" ? r.days < 0 : r.days >= 0 && String(r.due_date ?? "").startsWith(renewalMonth)))}
                onClose={() => { setViewAll(null); setRenewalMonth(null); }}
                initialSort={{ key: "hard_deadline", dir: "asc" }} empty="No upcoming renewals."
                onRowClick={(r: any) => goTo(`/projects/${r.id}?tab=deadlines`)}
                columns={[
                  { key: "docket_number", label: "Docket", render: (r: any) => <span className="font-mono text-gold">{r.docket_number ?? "—"}</span> },
                  { key: "project_name", label: "Case" },
                  { key: "client", label: "Client", value: (r: any) => r.client?.company_name ?? r.client?.legal_name ?? "" },
                  { key: "label", label: "Fee", value: (r: any) => r.label ?? "" },
                  { key: "patent_office_code", label: "Office", value: (r: any) => r.patent_office_code ?? "" },
                  { key: "hard_deadline", label: "Due", value: (r: any) => r.due_date ?? r.hard_deadline ?? "", render: (r: any) => (
                    <span className={typeof r.days === "number" && r.days < 0 ? "text-red-500 font-medium" : ""}>{fmtShort(r.due_date ?? r.hard_deadline)}{typeof r.days === "number" && r.days < 0 ? ` (${-r.days}d overdue)` : ""}</span>
                  ) },
                ]} />
            )}
            {viewAll === "stages" && (
              <SectionDialog title="Pending Patents by Stage" rows={stageData} onClose={() => setViewAll(null)}
                initialSort={{ key: "count", dir: "desc" }} empty="No pending patents."
                onRowClick={(s: any) => { setActiveStage(s.name); setPage(1); setViewAll(null); }}
                columns={[
                  { key: "name", label: "Stage" },
                  { key: "count", label: "Cases", align: "right", value: (s: any) => Number(s.count) },
                ]} />
            )}
            {viewAll === "actions" && (
              <SectionDialog title="Action Required" rows={filtered} onClose={() => setViewAll(null)}
                initialSort={{ key: "hard_deadline", dir: "asc" }} empty="No actions."
                onRowClick={(row: any) => goToAction(row)}
                columns={[
                  { key: "docket_number", label: "Docket", render: (r: any) => <span className="font-mono text-gold">{r.docket_number}</span> },
                  { key: "project_name", label: "Case" },
                  { key: "status", label: "Status" },
                  { key: "current_stage", label: "Current stage", value: (r: any) => r.current_stage ?? "" },
                  { key: "pending_action", label: "Pending action" },
                  { key: "hard_deadline", label: "Deadline", value: (r: any) => r.hard_deadline ?? "", render: (r: any) => fmtShort(r.hard_deadline) },
                  { key: "action", label: "", value: (r: any) => r.action_target?.label ?? "", render: (r: any) => <span className="text-xs font-medium text-gold">{r.action_target?.label ?? "Open"} →</span> },
                ]} />
            )}
          </>
        )}
      </div>
      {detailId !== null && (
        <ProjectDetailPanel projectId={detailId} onClose={() => setDetailId(null)} />
      )}
    </AppLayout>
  );
}
