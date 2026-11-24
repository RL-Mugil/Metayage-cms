import { Head, Link, router, usePage } from "@inertiajs/react";
import { lazy, Suspense, useEffect, useState, useMemo } from "react";
import { GlossaryText, HealthDot } from "@/components/glossary-text";
import { OfficeMap } from "@/components/portfolio/office-map";
import { canUseWebGL } from "@/components/portfolio/globe-support";
import { TiltCard } from "@/components/modern/tilt-card";
import { Ring3D } from "@/components/modern/ring-3d";
import { HBar3D } from "@/components/modern/bar-3d";
import { SegmentTabs } from "@/components/modern/segment-tabs";
import { staggerParent } from "@/components/modern/glass-card";
import { AnimatePresence, motion } from "framer-motion";
import { useFitRows } from "@/components/modern/use-fit-rows";

const OfficeGlobe = lazy(() => import("@/components/portfolio/office-globe"));
import { SavedViewsBar } from "@/components/saved-views-bar";
import { ClientHub } from "@/components/client/client-hub";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { PortfolioInsights } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import {
  Loader2, RefreshCw, ChevronDown, MapPin, FileText, Calendar,
  AlertCircle, CreditCard, X, Search, ChevronUp, ExternalLink, ArrowUpDown,
  Hourglass, Activity, Inbox, Globe2,
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

const PORTFOLIO_TABS = ["overview", "renewals", "insights", "map"] as const;
type PortfolioTab = typeof PORTFOLIO_TABS[number];

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
  const [insights, setInsights] = useState<PortfolioInsights | null>(null);
  const [hubOpen, setHubOpen] = useState(false);
  const [tab, setTabState] = useState<PortfolioTab>(() => {
    const wanted = typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("tab") : null;
    return (PORTFOLIO_TABS as readonly string[]).includes(wanted ?? "") ? (wanted as PortfolioTab) : "overview";
  });
  const setTab = (next: PortfolioTab) => {
    setTabState(next);
    const url = new URL(window.location.href);
    if (next === "overview") url.searchParams.delete("tab"); else url.searchParams.set("tab", next);
    window.history.replaceState(window.history.state, "", url);
  };
  // Rows per page follow the card height on fit-to-screen layouts (header row + pager reserved).
  const [actionsFit, actionsPer] = useFitRows(46, PAGE_SIZE, 78);
  const [renewalsFit, renewalsPer] = useFitRows(62, RENEWAL_PAGE);
  const [paymentsFit, paymentsPer] = useFitRows(70, PAGE_SIZE);
  useEffect(() => { setPage(1); }, [actionsPer]);
  useEffect(() => { setRenewalPage(1); }, [renewalsPer]);
  useEffect(() => { setPaymentPage(1); }, [paymentsPer]);
  const [mapView, setMapView] = useState<"globe" | "tiles">("globe");
  const [globeOk] = useState(() => typeof window !== "undefined" && canUseWebGL()
    && !window.matchMedia("(prefers-reduced-motion: reduce)").matches && window.innerWidth >= 640);
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
    api.getPortfolioInsights({ client_id: clientId ?? undefined, role_filter: effectiveRf(rf) && effectiveRf(rf) !== "all" ? effectiveRf(rf) : undefined })
      .then(setInsights).catch(() => setInsights(null));
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
  const mapOther = useMemo(() => (data ? Object.fromEntries(Object.keys({ ...(data.refused_by_office ?? {}), ...(data.closed_by_office ?? {}) })
    .map((k) => [k, Number(data.refused_by_office?.[k] ?? 0) + Number(data.closed_by_office?.[k] ?? 0)])) : {}), [data]);
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

  const totalPages = Math.max(1, Math.ceil(filtered.length / actionsPer));
  const paged = filtered.slice((page - 1) * actionsPer, page * actionsPer);
  const overdueRenewals = ((data?.upcoming_renewals ?? []) as any[]).filter((r: any) => typeof r.days === "number" && r.days < 0).length;

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
      {/* One screen per tab (like the Dashboard): compact header, then the tab fills the window. */}
      <div className="surface-mesh flex min-h-[calc(100dvh-3.5rem)] flex-col fit:h-[calc(100dvh-3.5rem)] fit:overflow-hidden">
        <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-4 pb-2 sm:px-6">
          <div className="mr-auto min-w-0">
            <h1 className="truncate font-display text-xl font-semibold tracking-tight sm:text-2xl">Patent Portfolio</h1>
            <p className="truncate text-xs text-muted-foreground">
              {data ? `${data.total_unique_cases} cases · ${activeTotal} active · ${grantedTotal} granted` : "Loading…"}
            </p>
          </div>
          {/* Client selector — internal users only; clients are locked to their own data */}
          {!isClientUser && (
          <div className="relative w-56">
            <button
              onClick={() => setShowClientDrop(!showClientDrop)}
              className="glass flex items-center gap-2 w-full rounded-full px-3 py-1.5 text-sm text-left hover:border-gold/50 transition-colors"
            >
              <FileText className="h-4 w-4 text-muted-foreground flex-shrink-0" />
              <span className="flex-1 truncate font-medium">{selectedClientName}</span>
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

          <SegmentTabs value={tab} onChange={setTab} options={[
            { value: "overview", label: "Overview" },
            { value: "renewals", label: "Renewals & Payments", badge: overdueRenewals },
            { value: "insights", label: "Insights", badge: insights?.stuck.length ?? 0 },
            { value: "map", label: "Map" },
          ]} />
          <div className="flex items-center gap-2">
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
            {!isClientUser && selectedClientId && ["super_admin", "partner", "director", "manager", "finance"].includes(role) && (
              <button onClick={() => setHubOpen(true)} title="Client hub"
                className="glass flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium text-gold hover:bg-gold/10 transition-colors">
                <Inbox className="h-3.5 w-3.5" /> Client hub
              </button>
            )}
            <button onClick={() => load(selectedClientId, roleFilter)} title="Refresh" aria-label="Refresh"
              className="glass flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground hover:text-foreground transition-colors">
              <RefreshCw className="h-3.5 w-3.5" />
            </button>
          </div>
        </header>

        <div className="flex shrink-0 flex-wrap items-center gap-2 px-4 pb-2 empty:hidden sm:px-6">
          <SavedViewsBar page="patent_portfolio"
            hasFilters={!!(activeOffice || activeStatus || activeStage || actionSearch || selectedClientId)}
            current={{ client_id: selectedClientId, office: activeOffice, status: activeStatus, stage: activeStage, search: actionSearch, sort: sortCol, dir: sortDir }}
            onApply={(f) => {
              const client = (f.client_id as number | null) ?? null;
              if (!isClientUser && client !== selectedClientId) { setSelectedClientId(client); load(client, roleFilter); }
              setActiveOffice((f.office as string | null) ?? null);
              setActiveStatus((f.status as string | null) ?? null);
              setActiveStage((f.stage as string | null) ?? null);
              setActionSearch((f.search as string) ?? "");
              if (f.sort) setSortCol(f.sort as typeof sortCol);
              if (f.dir) setSortDir(f.dir as typeof sortDir);
              setPage(1);
            }} />

          {activeOffice && (
            <span className="flex items-center gap-1 rounded-full bg-gold/15 text-gold border border-gold/30 px-2 py-0.5 text-xs font-medium">
              <MapPin className="h-3 w-3" /> {ol(activeOffice)}
              <button onClick={() => setActiveOffice(null)} aria-label="Clear office filter"><X className="h-3 w-3 ml-0.5" /></button>
            </span>
          )}
        </div>

        <main className="min-h-0 flex-1 px-4 pb-4 sm:px-6 fit:overflow-hidden">
        {loading || !data ? (
          <div className="flex items-center justify-center py-24">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <>
          <AnimatePresence mode="wait">
          <motion.div key={tab} className="h-full" initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.18 }}>
          {tab === "overview" && (
            <div className="flex flex-col gap-3 fit:grid fit:h-full fit:grid-rows-[auto_minmax(0,1fr)]">
            <motion.div variants={staggerParent} initial="hidden" animate="show" className="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4">
              {([
                ["Granted", grantedData, grantedTotal, "radial-gradient(closest-side, rgb(34 197 94 / 0.35), transparent)", "Patents in force"],
                ["Active", activeData, activeTotal, "radial-gradient(closest-side, rgb(245 158 11 / 0.35), transparent)", "Open · In Progress · On Hold"],
                ["Refused / Abandoned", refusedData, refusedTotal, "radial-gradient(closest-side, rgb(244 63 94 / 0.3), transparent)", "Ended without grant"],
                ["Closed / Completed", closedData, closedTotal, "radial-gradient(closest-side, rgb(100 116 139 / 0.3), transparent)", "Work finished"],
              ] as [string, any[], number, string, string][]).map(([label, ring, total, glow, hint]) => (
                <TiltCard key={label} glow={glow}>
                  <div className="px-3 py-2.5">
                    <div className="mb-1 flex items-center justify-between gap-2">
                      <span className="font-display text-sm font-semibold">{label}</span>
                      <span className="truncate text-[10px] text-muted-foreground">{hint}</span>
                    </div>
                    <Ring3D data={ring} total={total} label="cases" size={92} thickness={13} activeCode={activeOffice} onSelect={(c) => toggleOffice(c ?? activeOffice ?? "")} />
                  </div>
                </TiltCard>
              ))}
            </motion.div>

            {/* Row 3: Pending by Stage + Action Required — same height side by side */}
            <div className="grid min-h-0 gap-3 lg:grid-cols-12 items-stretch">
              {/* Pending by Stage — clickable bars filter Action Required */}
              <Card className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-0 flex flex-col lg:col-span-4 lg:order-2">
                <SectionHead title="Pending Patents by Stage" search={stageSearch}
                  onSearch={(v) => setStageSearch(v)} onViewAll={() => setViewAll("stages")}
                  extra={activeStage ? (
                    <button onClick={() => setActiveStage(null)} className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                      <X className="h-3 w-3" /> Clear
                    </button>
                  ) : null} />
                <CardContent className="pt-2 pb-3 flex-1 min-h-0 flex flex-col">
                  {stageRows.length === 0 ? (
                    <p className="text-xs text-muted-foreground py-6 text-center flex-1">{stageSearch ? "No matching stages." : "No pending patents."}</p>
                  ) : (
                    <div className="thin-scrollbar space-y-1 flex-1 min-h-0 overflow-y-auto pr-1">
                      {stageRows.map((s: any) => (
                        <HBar3D key={s.name} label={s.name} value={s.count} max={maxStage}
                          active={activeStage === s.name} onClick={() => toggleStage(s.name)} />
                      ))}
                      {activeStage && <p className="pt-1 text-[10px] text-muted-foreground">Filtering Action Required by "{activeStage}" · click again to clear</p>}
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Action Required — filterable, sortable, searchable; a row opens its action */}
              <Card id="pf-actions" className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-[360px] fit:min-h-0 flex flex-col lg:col-span-8 lg:order-1">
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
                <CardContent ref={actionsFit} className="p-0 flex-1 min-h-0 flex flex-col">
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
                                  className="inline-flex max-w-full items-center gap-1.5 font-mono font-medium text-foreground hover:text-gold hover:underline transition-colors">
                                  <HealthDot health={insights?.health?.[row.id]} />
                                  <span className="truncate">{row.docket_number}</span>
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
                                <div className="truncate text-foreground"><GlossaryText text={row.current_stage ?? "—"} /></div>
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
                      <span>{(page - 1) * actionsPer + 1}–{Math.min(page * actionsPer, filtered.length)} of {filtered.length}</span>
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

            </div>
          )}
          {tab === "renewals" && (
            <div className="grid gap-3 lg:grid-cols-2 items-stretch fit:h-full fit:min-h-0">
              {/* Upcoming Renewals */}
              <Card id="pf-renewals" className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-0 flex flex-col">
                <SectionHead icon={<Calendar className="h-4 w-4 text-gold" />} title="Upcoming Renewals"
                  search={renewalSearch} onSearch={(v) => { setRenewalSearch(v); setRenewalPage(1); }} onViewAll={() => { setRenewalMonth(null); setViewAll("renewals"); }}
                  extra={
                    <div className="flex rounded-md border border-border p-0.5 text-[10px]">
                      {(["list", "calendar"] as const).map((v) => (
                        <button key={v} onClick={() => setRenewalView(v)} className={`rounded px-1.5 py-0.5 capitalize ${renewalView === v ? "bg-muted font-medium" : "text-muted-foreground"}`}>{v}</button>
                      ))}
                    </div>
                  } />
                <CardContent className="p-0 flex-1 min-h-0 flex flex-col">
                  {renewalView === "calendar" ? (
                    <RenewalCalendar rows={renewalRows} onPick={(month) => { setRenewalMonth(month); setViewAll("renewals"); }} />
                  ) : (<>
                  <div ref={renewalsFit} className="flex-1 min-h-0 overflow-hidden">
                    {renewalRows.length === 0 ? (
                      <p className="text-xs text-muted-foreground px-4 py-6">{renewalSearch ? "No matching renewals." : "No upcoming renewals."}</p>
                    ) : pageOf(renewalRows, renewalPage, renewalsPer).map((r: any, i: number) => (
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
                  <SectionPager page={renewalPage} total={renewalRows.length} onPage={setRenewalPage} size={renewalsPer} />
                  </>)}
                </CardContent>
              </Card>

              {/* Pending Payments */}
              <Card id="pf-payments" className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-0 flex flex-col">
                <SectionHead icon={<CreditCard className="h-4 w-4 text-gold" />} title="Pending Payments"
                  search={paymentSearch} onSearch={(v) => { setPaymentSearch(v); setPaymentPage(1); }}
                  onViewAll={() => goTo(`/pending-payments${selectedClientCode ? `?search=${encodeURIComponent(selectedClientCode)}` : ""}`)} />
                <CardContent className="p-0 flex-1 min-h-0 flex flex-col">
                  <div ref={paymentsFit} className="flex-1 min-h-0 overflow-hidden">
                    {paymentRows.length === 0 ? (
                      <p className="text-xs text-muted-foreground px-4 py-6">{paymentSearch ? "No matching payments." : "No pending payments."}</p>
                    ) : pageOf(paymentRows, paymentPage, paymentsPer).map((inv: any, i: number) => {
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
                  <SectionPager page={paymentPage} total={paymentRows.length} onPage={setPaymentPage} size={paymentsPer} />
                </CardContent>
              </Card>
            </div>

          )}
          {tab === "insights" && (<>
            {!insights ? <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div> : (
              <div className="grid gap-3 lg:grid-cols-2 items-stretch fit:h-full fit:min-h-0">
                <Card className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-0 flex flex-col">
                  <CardHeader className="pb-2">
                    <CardTitle className="font-display text-sm flex items-center gap-2">
                      <Hourglass className="h-4 w-4 text-amber-500" /> Stuck in a stage
                      <span className="text-[10px] font-normal text-muted-foreground">past the stage's target duration</span>
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="thin-scrollbar p-0 flex-1 min-h-0 max-h-[420px] fit:max-h-none overflow-y-auto">
                    {insights.stuck.length === 0 ? <p className="px-4 py-6 text-xs text-muted-foreground">Nothing is running late.</p> : insights.stuck.map((r) => (
                      <button key={r.id} onClick={() => goTo(`/projects/${r.id}?tab=lifecycle`)}
                        className="flex w-full items-center justify-between gap-3 border-b border-border px-4 py-2.5 text-left last:border-0 hover:bg-muted/20">
                        <div className="min-w-0">
                          <div className="flex items-center gap-1.5 font-mono text-xs font-medium"><HealthDot health={insights.health?.[r.id]} />{r.docket_number ?? "—"}</div>
                          <div className="truncate text-[11px] text-muted-foreground"><GlossaryText text={r.stage} /> · {r.client ?? ""}</div>
                        </div>
                        <span className="shrink-0 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600">{r.days}d / {r.target}d</span>
                      </button>
                    ))}
                  </CardContent>
                </Card>

                <Card className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl h-full min-h-0 flex flex-col">
                  <CardHeader className="pb-2">
                    <CardTitle className="font-display text-sm flex items-center gap-2"><Activity className="h-4 w-4 text-gold" /> What changed this week</CardTitle>
                  </CardHeader>
                  <CardContent className="thin-scrollbar p-0 flex-1 min-h-0 max-h-[420px] fit:max-h-none overflow-y-auto">
                    {insights.changes.length === 0 ? <p className="px-4 py-6 text-xs text-muted-foreground">No changes in the last 7 days.</p> : insights.changes.map((c, i) => (
                      <button key={i} onClick={() => c.project_id && goTo(`/projects/${c.project_id}${c.kind === "deadline" ? "?tab=deadlines" : c.kind === "stage" ? "?tab=lifecycle" : ""}`)}
                        className="flex w-full items-start gap-3 border-b border-border px-4 py-2.5 text-left last:border-0 hover:bg-muted/20">
                        <span className={`mt-1 h-2 w-2 shrink-0 rounded-full ${c.kind === "payment" ? "bg-emerald-500" : c.kind === "invoice" ? "bg-blue-500" : c.kind === "deadline" ? "bg-amber-500" : "bg-gold"}`} />
                        <div className="min-w-0 flex-1">
                          <div className="text-xs"><GlossaryText text={c.text} /></div>
                          <div className="text-[10px] text-muted-foreground"><span className="font-mono">{c.docket_number ?? ""}</span> · {new Date(c.at).toLocaleDateString("en-IN", { day: "numeric", month: "short" })}</div>
                        </div>
                      </button>
                    ))}
                  </CardContent>
                </Card>
              </div>
            )}

          </>)}
          {tab === "map" && (
            <Card className="rounded-2xl border-[var(--glass-border)] bg-[var(--glass-bg)] shadow-3d backdrop-blur-xl flex flex-col fit:h-full fit:min-h-0">
              <CardHeader className="pb-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <CardTitle className="font-display text-sm flex items-center gap-2"><Globe2 className="h-4 w-4 text-gold" /> Portfolio map</CardTitle>
                  <SegmentTabs size="sm" value={mapView} onChange={setMapView} options={[{ value: "globe", label: "Globe" }, { value: "tiles", label: "Tiles" }]} />
                </div>
              </CardHeader>
              <CardContent className="flex-1 min-h-0 flex flex-col">
                {mapView === "globe" && globeOk ? (
                  <Suspense fallback={<div className="flex h-[380px] fit:h-full items-center justify-center"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>}>
                    <OfficeGlobe active={data.active_by_office ?? {}} granted={data.granted_by_office ?? {}} other={mapOther}
                      label={ol} selected={activeOffice} onSelect={(c) => { toggleOffice(c); setTab("overview"); }} />
                  </Suspense>
                ) : (
                  <OfficeMap active={data.active_by_office ?? {}} granted={data.granted_by_office ?? {}} other={mapOther}
                    label={ol} selected={activeOffice} onSelect={(c) => { toggleOffice(c); setTab("overview"); }} />
                )}
                {mapView === "globe" && !globeOk && <p className="mt-2 text-[11px] text-muted-foreground">3D view is not available in this browser, showing tiles.</p>}
              </CardContent>
            </Card>

          )}
          </motion.div>
          </AnimatePresence>

            {hubOpen && selectedClientId && (
              <Dialog open onOpenChange={(o) => !o && setHubOpen(false)}>
                <DialogContent className="max-h-[90vh] max-w-5xl overflow-y-auto">
                  <DialogHeader><DialogTitle>Client hub</DialogTitle></DialogHeader>
                  <ClientHub clientId={selectedClientId} />
                </DialogContent>
              </Dialog>
            )}

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
        </main>
      </div>

      {detailId !== null && (
        <ProjectDetailPanel projectId={detailId} onClose={() => setDetailId(null)} />
      )}
    </AppLayout>
  );
}
