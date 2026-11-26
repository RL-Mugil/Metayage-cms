import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { AlertTriangle, BadgeCheck, CheckCircle2, CloudOff, Download, FileSpreadsheet, FileText, HandCoins, Loader2, MessageSquare, RefreshCw, Search, ShieldCheck, TrendingUp, Upload, X } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { AnimatedNumber } from "@/components/modern/animated-number";
import { TableCard } from "@/components/modern/table-card";
import { useFitRows } from "@/components/modern/use-fit-rows";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";
import { ReportDownloadDialog } from "@/components/reports/report-download-dialog";
import { RemindersPanel } from "@/components/finance/reminders-panel";
import { Bars3D, inrShort } from "@/components/three/chart-3d";

type Doc = "both" | "invoices" | "quotes";
interface AgeBand { key: string; band: string; invoices: number; quotes: number }

/** One unpaid invoice from the portal register (portal renewals and invoices imported from Zoho). */
interface Row {
  id: number;
  type: string;
  status: string;
  source?: string;
  invoice_uin: string;
  docket_number: string | null;
  invoice_date: string | null;
  due_date?: string | null;
  days_overdue?: number;
  invoice_amount: string | number;
  balance?: number;
  currency: string;
  payment_status: string;
  settlement?: string | null;
  waiver_requested_amount?: string | number | null;
  waiver_reason?: string | null;
  waiver_requested_by?: { name: string } | null;
  zoho_writeback?: string | null;
  zoho_writeback_error?: string | null;
  invention_title?: string | null;
  client_name?: string | null;
  status_note?: string | null;
  status_note_at?: string | null;
  promised_on?: string | null;
  promise_broken?: boolean;
  project?: { id: number; docket_number: string; project_name: string } | null;
  client?: { id: number; company_name?: string; legal_name?: string; client_code?: string; contact_name?: string; contact_email?: string; phone?: string } | null;
  proof_document?: { id: number; file_name: string; storage_path: string } | null;
  /** Quotes: part of an advance already received, earmarked for this quote (INR), and what is left to pay. */
  paid_state?: { total: number; received: number; allowance: number; due: number; fully_paid: boolean };
  payment_suggestion?: { zoho_payment_id: string; number: string | null; unused: number } | null;
  advance_linked?: number;
  advance_payments?: string[];
  net_due?: number;
}

const AGES = [
  { key: "", label: "All", color: "#94a3b8" },
  { key: "not_due", label: "Not due", color: "#3b82f6" },
  { key: "1_30", label: "1–30 d", color: "#f59e0b" },
  { key: "31_60", label: "31–60 d", color: "#f97316" },
  { key: "61_90", label: "61–90 d", color: "#ef4444" },
  { key: "90_plus", label: "90+ d", color: "#991b1b" },
] as const;

const ageColor = (days?: number) => !days || days <= 0 ? "#3b82f6" : days <= 30 ? "#f59e0b" : days <= 60 ? "#f97316" : days <= 90 ? "#ef4444" : "#991b1b";
const money = (amount: string | number, currency: string) => `${currency === "INR" ? "₹" : `${currency} `}${Number(amount).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
const MODES = ["Bank transfer", "UPI", "Cheque", "Cash", "Card", "Other"];

export default function PendingPayments() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isClient = ["client", "client_admin", "client_finance"].includes(role);
  const isStaff = !isClient;

  const params0 = typeof window === "undefined" ? new URLSearchParams() : new URLSearchParams(window.location.search);
  const [rows, setRows] = useState<Row[]>([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [extra, setExtra] = useState<{ totals: Record<string, number>; overdue: number; waivers: number; writeback: number; waived: number;
    clients: { id: number; name: string; code: string | null }[]; can: { mark_paid: boolean; request_waiver: boolean; approve_waiver: boolean };
    byDoc: { invoices: Record<string, number>; quotes: Record<string, number> }; counts: { invoices: number; quotes: number }; ageing: AgeBand[] }>(
    { totals: {}, overdue: 0, waivers: 0, writeback: 0, waived: 0, clients: [], can: { mark_paid: false, request_waiver: false, approve_waiver: false },
      byDoc: { invoices: {}, quotes: {} }, counts: { invoices: 0, quotes: 0 }, ageing: [] });
  // Unpaid invoices, unpaid quotes (sent, not yet invoiced) or both.
  const [doc, setDoc] = useState<Doc>(() => (["invoices", "quotes"].includes(params0.get("doc") ?? "") ? params0.get("doc") as Doc : "both"));
  const [showChart, setShowChart] = useState(true);
  const [search, setSearch] = useState(params0.get("search") ?? "");
  const [query, setQuery] = useState((params0.get("search") ?? "").trim());
  const [age, setAge] = useState<string>(params0.get("age") ?? (params0.get("overdue") === "1" ? "1_30" : ""));
  const [clientId, setClientId] = useState("");
  const [waiversOnly, setWaiversOnly] = useState(params0.get("waivers") === "1");
  const [dateField, setDateField] = useState<"invoice" | "due">("invoice");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<number | null>(null);
  const [payRow, setPayRow] = useState<Row | null>(null);
  const [waiveRow, setWaiveRow] = useState<Row | null>(null);
  const [noteRow, setNoteRow] = useState<Row | null>(null);
  const [view, setView] = useState<"invoices" | "reminders">(() => new URLSearchParams(window.location.search).get("tab") === "reminders" ? "reminders" : "invoices");
  const [exportOpen, setExportOpen] = useState(false);
  const fileRefs = useRef<Record<number, HTMLInputElement | null>>({});
  const [fitRef, perPage] = useFitRows(46, 25, 0);

  useEffect(() => { const t = window.setTimeout(() => { setQuery(search.trim()); setPage(1); }, 300); return () => window.clearTimeout(t); }, [search]);

  const load = () => {
    setLoading(true);
    const p = new URLSearchParams({ page: String(page), per_page: String(perPage), doc });
    if (query) p.set("search", query);
    // "Overdue" from links: any age past due.
    if (age) p.set("age", age);
    if (clientId) p.set("client_id", clientId);
    if (waiversOnly) p.set("waivers", "1");
    if (from || to) { p.set("date_field", dateField); if (from) p.set("from", from); if (to) p.set("to", to); }
    api.getPendingPayments(p)
      .then((res: any) => {
        setRows(res?.data ?? []);
        setMeta({ current_page: res?.current_page ?? 1, last_page: res?.last_page ?? 1, total: res?.total ?? 0 });
        setExtra({ totals: res?.pending_totals ?? {}, overdue: res?.overdue_count ?? 0, waivers: res?.waivers_pending ?? 0, writeback: res?.writeback_pending ?? 0,
          waived: res?.waived_this_year ?? 0, clients: res?.clients ?? [], can: res?.can ?? { mark_paid: false, request_waiver: false, approve_waiver: false },
          byDoc: res?.totals_by_doc ?? { invoices: {}, quotes: {} }, counts: res?.doc_counts ?? { invoices: 0, quotes: 0 }, ageing: res?.ageing ?? [] });
      })
      .catch((e: any) => toast.error(e?.message || "Failed to load pending payments."))
      .finally(() => setLoading(false));
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [page, query, age, clientId, waiversOnly, from, to, dateField, perPage, doc]);
  useEffect(() => { setPage(1); }, [age, clientId, waiversOnly, from, to, dateField, perPage, doc]);

  const act = async (id: number, fn: () => Promise<unknown>, ok: string) => {
    setBusy(id);
    try { await fn(); toast.success(ok); load(); } catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setBusy(null); }
  };

  const totalLabel = Object.entries(extra.totals).map(([c, a]) => money(a, c)).join(" + ") || "₹0";
  const sumLabel = (t: Record<string, number>) => Object.entries(t).filter(([, a]) => a > 0).map(([c, a]) => money(a, c)).join(" + ") || "₹0";
  const DOCS_ORDER: Doc[] = ["invoices", "quotes", "both"];
  const DOCS: { key: Doc; label: string; count: number; total: string; color: string }[] = [
    { key: "both", label: "Invoices + quotes", count: extra.counts.invoices + extra.counts.quotes, total: totalLabel, color: "#f59e0b" },
    { key: "invoices", label: "Unpaid invoices", count: extra.counts.invoices, total: sumLabel(extra.byDoc.invoices), color: "#3b82f6" },
    { key: "quotes", label: "Unpaid quotes", count: extra.counts.quotes, total: sumLabel(extra.byDoc.quotes), color: "#8b5cf6" },
  ];
  const fromN = meta.total === 0 ? 0 : (meta.current_page - 1) * perPage + 1;
  const toN = Math.min(meta.current_page * perPage, meta.total);
  const clientName = (r: Row) => r.client?.legal_name ?? r.client?.company_name ?? r.client_name ?? "—";

  return (
    <AppLayout>
      <Head title="Pending Payments" />
      <FitPage eyebrow="Billing" title="Pending Payments"
        summary={isClient ? "Invoices awaiting payment. Pay by bank transfer, then upload the payment proof here." : "Every unpaid invoice and unpaid quote — portal and Zoho Books — colour coded by how late it is."}
        actions={isStaff && (
          <Button size="sm" className="bg-gold text-black hover:bg-gold/90" onClick={() => setExportOpen(true)}>
            <Download className="mr-1.5 h-4 w-4" /> Payment Collection report
          </Button>
        )}>
        {/* Four tabs: unpaid invoices, unpaid quotes, both, and reminders (staff). Counts follow the filters. */}
        <div className="mb-2 flex shrink-0 flex-wrap items-stretch gap-2" style={{ perspective: 900 }}>
          {DOCS_ORDER.map((key) => {
            const d = DOCS.find((x) => x.key === key)!;
            const on = view === "invoices" && doc === key;
            return (
              <button key={key} type="button" onClick={() => { setView("invoices"); setDoc(key); }}
                className={`card-3d glass flex min-w-[170px] flex-1 items-center gap-2.5 rounded-xl px-3 py-1.5 text-left ${on ? "" : "opacity-75 hover:opacity-100"}`}
                style={on ? { boxShadow: `0 0 0 2px ${d.color}, var(--shadow-3d-lg)`, transform: "translateY(-2px) rotateX(4deg)" } : undefined}>
                <span className="h-3 w-3 shrink-0 rounded" style={{ background: d.color, boxShadow: `0 0 10px ${d.color}` }} />
                <span className="min-w-0">
                  <span className="block text-[11px] font-semibold uppercase tracking-wider">{d.label}</span>
                  <span className="block font-display text-sm font-semibold leading-tight">{d.count.toLocaleString("en-IN")} · <span className="text-amber-600">{d.total}</span></span>
                </span>
              </button>
            );
          })}
          {isStaff && (
            <button type="button" onClick={() => setView("reminders")}
              className={`card-3d glass flex min-w-[150px] items-center gap-2.5 rounded-xl px-3 py-1.5 text-left ${view === "reminders" ? "" : "opacity-75 hover:opacity-100"}`}
              style={view === "reminders" ? { boxShadow: "0 0 0 2px #10b981, var(--shadow-3d-lg)", transform: "translateY(-2px) rotateX(4deg)" } : undefined}>
              <span className="h-3 w-3 shrink-0 rounded" style={{ background: "#10b981", boxShadow: "0 0 10px #10b981" }} />
              <span><span className="block text-[11px] font-semibold uppercase tracking-wider">Reminders</span>
                <span className="block text-[11px] text-muted-foreground">Payment reminder emails</span></span>
            </button>
          )}
        </div>
        {isStaff && view === "reminders" ? <RemindersPanel /> : (
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          {isStaff && (
            <button type="button" onClick={() => setShowChart((v) => !v)} className="shrink-0 self-start text-[11px] text-muted-foreground hover:text-foreground">
              {showChart ? "Hide" : "Show"} 3D ageing
            </button>
          )}
          {isStaff && showChart && (
            <div className="shrink-0">
              <Bars3D height={165} format={inrShort} activeLabel={extra.ageing.find((b) => b.key === age)?.band ?? null}
                data={extra.ageing.map((b) => ({ label: b.band, values: { invoices: b.invoices, quotes: b.quotes } }))}
                series={[{ key: "invoices", label: "Unpaid invoices (INR)", color: "#3b82f6" }, { key: "quotes", label: "Unpaid quotes (INR)", color: "#8b5cf6" }]}
                onSelect={(label, key) => {
                  const band = extra.ageing.find((b) => b.band === label);
                  if (band) setAge(band.key === age ? "" : band.key);
                  setDoc(key === "quotes" ? "quotes" : "invoices");
                }} />
            </div>
          )}
          {/* Chips */}
          <div className="flex shrink-0 flex-wrap gap-2">
            {[
              { label: "Total pending", value: <span className="text-amber-600">{totalLabel}</span>, color: "#f59e0b" },
              { label: query || age || clientId || from || to ? "Matching" : doc === "quotes" ? "Unpaid quotes" : doc === "invoices" ? "Unpaid invoices" : "Invoices + quotes", value: <AnimatedNumber value={meta.total} />, color: "#3b82f6" },
              { label: "Overdue", value: <AnimatedNumber value={extra.overdue} className="text-red-600" />, color: "#ef4444" },
              ...(isStaff ? [
                { label: "Waivers to approve", value: <AnimatedNumber value={extra.waivers} />, color: "#8b5cf6", onClick: () => setWaiversOnly((v) => !v), active: waiversOnly },
                { label: "Waived this year", value: money(extra.waived, "INR"), color: "#a855f7" },
                { label: "Not yet in Zoho", value: <AnimatedNumber value={extra.writeback} />, color: "#64748b" },
              ] : []),
            ].map((c) => (
              <button key={c.label} type="button" onClick={(c as any).onClick} disabled={!(c as any).onClick}
                className={`card-3d glass flex min-w-[104px] flex-1 items-center gap-2 rounded-xl px-2.5 py-1 text-left ${(c as any).onClick ? "cursor-pointer" : "cursor-default"} ${(c as any).active ? "ring-2 ring-violet-500/60" : ""}`}>
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: c.color, boxShadow: `0 0 8px ${c.color}` }} />
                <span className="min-w-0"><span className="block truncate font-display text-sm font-semibold leading-tight xl:text-base">{c.value}</span>
                  <span className="block truncate text-[9px] uppercase tracking-wider text-muted-foreground">{c.label}</span></span>
              </button>
            ))}
          </div>

          {/* Toolbar */}
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <div className="relative min-w-[200px] flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search UIN, client, case or title…"
                className="h-8 w-full rounded-md border border-border bg-background/70 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold/25" />
            </div>
            {isStaff && (
              <select value={clientId} onChange={(e) => setClientId(e.target.value)} className="h-8 max-w-[220px] rounded-md border border-border bg-background/70 px-2 text-xs">
                <option value="">All clients</option>
                {extra.clients.map((c) => <option key={c.id} value={c.id}>{c.code ? `${c.code} · ` : ""}{c.name}</option>)}
              </select>
            )}
            <div className="flex flex-wrap gap-1">
              {AGES.map((a) => (
                <button key={a.key} onClick={() => setAge(a.key)}
                  className={`flex h-8 items-center gap-1.5 rounded-full border px-2.5 text-[11px] font-medium transition-colors ${age === a.key ? "border-transparent bg-foreground text-background" : "border-border text-muted-foreground hover:text-foreground"}`}>
                  <span className="h-2 w-2 rounded-full" style={{ background: a.color }} />{a.label}
                </button>
              ))}
            </div>
            {isStaff && (
              <div className="flex items-center gap-1 text-[11px]">
                <select value={dateField} onChange={(e) => setDateField(e.target.value as "invoice" | "due")} className="h-8 rounded-md border border-border bg-background/70 px-1.5">
                  <option value="invoice">Invoice date</option><option value="due">Due date</option>
                </select>
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="From (set only this for one date range start)" />
                <span className="text-muted-foreground">–</span>
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="To" />
                {(from || to) && <button onClick={() => { setFrom(""); setTo(""); }} className="text-muted-foreground hover:text-foreground" aria-label="Clear dates"><X className="h-3.5 w-3.5" /></button>}
              </div>
            )}
          </div>

          {/* List */}
          <TableCard ref={fitRef} footer={meta.total > 0 && (
            <div className="flex items-center justify-between px-4 py-1.5 text-xs text-muted-foreground">
              <span>Showing {fromN}–{toN} of {meta.total.toLocaleString("en-IN")}</span>
              <div className="flex items-center gap-1">
                <Button size="sm" variant="outline" className="h-7" disabled={page <= 1 || loading} onClick={() => setPage((p) => p - 1)}>Prev</Button>
                <span className="px-1">{meta.current_page}/{meta.last_page}</span>
                <Button size="sm" variant="outline" className="h-7" disabled={page >= meta.last_page || loading} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}>
            <table className="w-full min-w-[860px] text-xs">
              <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="w-1 p-0" />
                  <th className="px-3 py-2 text-left">Invoice</th>
                  {isStaff && <th className="px-3 py-2 text-left">Client</th>}
                  <th className="px-3 py-2 text-left">Case</th>
                  <th className="hidden px-3 py-2 text-left xl:table-cell">Invoiced</th>
                  <th className="px-3 py-2 text-left">Due</th>
                  <th className="px-3 py-2 text-right">Late</th>
                  <th className="px-3 py-2 text-right">Balance</th>
                  <th className="px-3 py-2 text-left">Status · follow-up</th>
                  <th className="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="table-rows-3d">
                {loading && rows.length === 0 && <tr><td colSpan={10} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                {!loading && rows.length === 0 && <tr><td colSpan={10} className="py-12 text-center text-sm text-muted-foreground">No pending payments match this view.</td></tr>}
                {rows.map((r) => {
                  const balance = Number(r.balance ?? r.invoice_amount);
                  const col = r.type === "quote" ? "#94a3b8" : ageColor(r.days_overdue);
                  const waiverPending = r.settlement === "waiver_requested";
                  return (
                    <tr key={r.id} className="border-t border-border/60">
                      <td className="w-1 p-0" style={{ background: col }} />
                      <td className="px-3 py-2">
                        <div className="font-mono font-semibold text-gold">{r.invoice_uin}</div>
                        {r.type === "quote" && <div className="mt-0.5 inline-block rounded bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-medium text-violet-600">Quote · {r.status}</div>}
                        {r.source === "zoho" && isStaff && <div className="text-[10px] text-muted-foreground">Zoho Books</div>}
                      </td>
                      {isStaff && (
                        <td className="max-w-[180px] px-3 py-2" title={[r.client?.contact_name, r.client?.contact_email, r.client?.phone].filter(Boolean).join(" · ")}>
                          <div className="truncate font-medium">{clientName(r)}</div>
                          <div className="truncate text-[10px] text-muted-foreground">{r.client?.client_code}{r.client?.contact_email ? ` · ${r.client.contact_email}` : ""}</div>
                        </td>
                      )}
                      <td className="max-w-[140px] px-3 py-2">
                        <div className="truncate font-mono text-[11px]">{r.project?.docket_number ?? r.docket_number ?? "—"}</div>
                        <div className="truncate text-[10px] text-muted-foreground">{r.project?.project_name ?? r.invention_title ?? ""}</div>
                      </td>
                      <td className="hidden whitespace-nowrap px-3 py-2 font-mono text-[11px] text-muted-foreground xl:table-cell">{fmtDate(r.invoice_date)}</td>
                      <td className="whitespace-nowrap px-3 py-2 font-mono text-[11px]">{r.type === "quote" ? <span title="A quote is payable from its date">{fmtDate(r.invoice_date)}</span> : r.due_date ? fmtDate(r.due_date) : "—"}</td>
                      <td className="px-3 py-2 text-right">
                        {r.days_overdue ? <span className="whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" style={{ background: col }}>{r.days_overdue} d</span> : <span className="text-[10px] text-blue-600">not due</span>}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right">
                        <div className="font-semibold">{money(balance, r.currency)}</div>
                        {balance < Number(r.invoice_amount) && <div className="text-[10px] text-muted-foreground">of {money(r.invoice_amount, r.currency)}</div>}
                        {r.type === "quote" && r.paid_state && (r.paid_state.received > 0 || r.paid_state.fully_paid) && (
                          <div className={`text-[10px] ${r.paid_state.fully_paid ? "font-medium text-emerald-600" : "text-amber-600"}`}>
                            {r.paid_state.fully_paid ? "Paid: ready to invoice" : `Paid ${money(r.paid_state.received, "INR")} of ${money(r.paid_state.total, "INR")}`}
                          </div>
                        )}
                        {r.type === "quote" && r.payment_suggestion && extra.can.mark_paid && (
                          <button className="text-[10px] font-medium text-emerald-700 hover:underline" title="The client's payment covers this quote"
                            onClick={() => act(r.id, () => api.invoiceQuoteFromPayment(r.id, r.payment_suggestion!.zoho_payment_id), "Invoice created and payment applied")}>
                            {r.payment_suggestion.number ?? "Payment"} received → Create invoice &amp; apply
                          </button>
                        )}
                        {r.advance_linked ? (
                          <div className="text-[10px] text-green-600" title={`Advance received${r.advance_payments?.length ? ` (${r.advance_payments.join(", ")})` : ""}, earmarked for this quote`}>
                            − advance {money(r.advance_linked, "INR")} · net {money(r.net_due ?? 0, r.currency)}
                          </div>
                        ) : null}
                      </td>
                      <td className="max-w-[240px] px-3 py-2">
                        <div className="flex flex-wrap gap-1">
                          {r.payment_status === "Proof Submitted" && <span className="rounded bg-blue-500/15 px-1.5 py-0.5 text-[10px] font-medium text-blue-600">Proof submitted</span>}
                          {r.payment_status === "Partially Paid" && <span className="rounded bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600">Part paid</span>}
                          {waiverPending && <span className="rounded bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-medium text-violet-600" title={r.waiver_reason ?? ""}>Waiver {money(r.waiver_requested_amount ?? 0, r.currency)} awaiting approval</span>}
                          {isStaff && r.zoho_writeback === "failed" && <span className="flex items-center gap-1 rounded bg-red-500/15 px-1.5 py-0.5 text-[10px] font-medium text-red-600" title={r.zoho_writeback_error ?? ""}><CloudOff className="h-3 w-3" />Not in Zoho</span>}
                          {isStaff && r.zoho_writeback === "pending" && <span className="rounded bg-slate-500/15 px-1.5 py-0.5 text-[10px] text-slate-600">Sending to Zoho…</span>}
                        </div>
                        {r.promised_on && <div className={`mt-0.5 text-[10px] font-medium ${r.promise_broken ? "text-red-600" : "text-blue-600"}`}>{r.promise_broken ? "Promise broken: " : "Promised to pay by "}{fmtDate(r.promised_on)}</div>}
                        {r.status_note && <div className="mt-0.5 truncate text-[10px] text-muted-foreground" title={r.status_note}><MessageSquare className="mr-1 inline h-3 w-3" />{r.status_note}{r.status_note_at ? ` · ${fmtDate(r.status_note_at)}` : ""}</div>}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex items-center justify-end gap-1">
                          {r.proof_document && <IconBtn title={`Proof: ${r.proof_document.file_name}`} onClick={() => api.downloadDocument(r.proof_document!.storage_path, r.proof_document!.file_name)}><FileText className="h-3.5 w-3.5" /></IconBtn>}
                          <IconBtn title="Follow-up note" onClick={() => setNoteRow(r)}><MessageSquare className="h-3.5 w-3.5" /></IconBtn>
                          {isClient && r.type === "quote" && r.status !== "Accepted" && r.status !== "Cancelled" && (
                            <Button size="sm" className="h-7 bg-gold px-2 text-[11px] text-black hover:bg-gold/90" disabled={busy === r.id}
                              onClick={() => act(r.id, () => api.approveRenewalQuote(r.id), "Estimate approved")}><CheckCircle2 className="mr-1 h-3 w-3" />Approve</Button>
                          )}
                          {isClient && r.type !== "quote" && (
                            <>
                              <input type="file" className="hidden" ref={(el) => { fileRefs.current[r.id] = el; }}
                                onChange={async (e) => { const f = e.target.files?.[0]; e.target.value = ""; if (!f) return;
                                  await act(r.id, async () => { const doc: any = await api.uploadDocument(f, "Payment Proof", r.client?.id ?? null, null); await api.submitRenewalProof(r.id, doc.id); }, "Proof uploaded"); }} />
                              <Button size="sm" variant="outline" className="h-7 px-2 text-[11px]" disabled={busy === r.id} onClick={() => fileRefs.current[r.id]?.click()}>
                                {busy === r.id ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : <Upload className="mr-1 h-3 w-3" />}{r.payment_status === "Proof Submitted" ? "Re-upload" : "Upload proof"}
                              </Button>
                            </>
                          )}
                          {isStaff && r.type !== "quote" && !waiverPending && extra.can.mark_paid && (
                            <Button size="sm" className="h-7 bg-emerald-600 px-2 text-[11px] text-white hover:bg-emerald-700" onClick={() => setPayRow(r)}><BadgeCheck className="mr-1 h-3 w-3" />Paid</Button>
                          )}
                          {isStaff && r.type !== "quote" && !waiverPending && extra.can.request_waiver && (
                            <Button size="sm" variant="outline" className="h-7 px-2 text-[11px]" onClick={() => setWaiveRow(r)}><HandCoins className="mr-1 h-3 w-3" />Waive</Button>
                          )}
                          {isStaff && waiverPending && extra.can.approve_waiver && (
                            <>
                              <Button size="sm" className="h-7 bg-violet-600 px-2 text-[11px] text-white hover:bg-violet-700" disabled={busy === r.id}
                                onClick={() => act(r.id, () => api.decideWaiver(r.id, true), "Waiver approved")}><ShieldCheck className="mr-1 h-3 w-3" />Approve</Button>
                              <Button size="sm" variant="outline" className="h-7 px-2 text-[11px]" disabled={busy === r.id}
                                onClick={() => act(r.id, () => api.decideWaiver(r.id, false), "Waiver rejected")}>Reject</Button>
                            </>
                          )}
                          {isStaff && r.zoho_writeback === "failed" && extra.can.mark_paid && (
                            <IconBtn title={`Retry sending to Zoho (${r.zoho_writeback_error ?? ""})`} onClick={() => act(r.id, () => api.retryZohoWriteback(r.id), "Sending to Zoho again")}><RefreshCw className="h-3.5 w-3.5" /></IconBtn>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </TableCard>
        </div>
        )}
      </FitPage>

      {payRow && <MarkPaidDialog row={payRow} onClose={() => setPayRow(null)} onDone={() => { setPayRow(null); load(); }} />}
      {waiveRow && <WaiveDialog row={waiveRow} onClose={() => setWaiveRow(null)} onDone={() => { setWaiveRow(null); load(); }} />}
      {noteRow && <NoteDialog row={noteRow} onClose={() => setNoteRow(null)} onDone={() => { setNoteRow(null); load(); }} />}
      {exportOpen && (
        <ReportDownloadDialog
          report={{ id: "payment-collection", name: "Payment Collection", clientFilter: true, icon: TrendingUp, color: "text-green-500",
            description: "Unpaid invoices and unpaid quotes to follow up, with client contacts, payments, waivers and ageing per client — same report as Reports → Payment Collection" }}
          clients={extra.clients}
          initialClientCode={extra.clients.find((c) => String(c.id) === String(clientId))?.code ?? ""}
          initialFrom={from} initialTo={to}
          onClose={() => setExportOpen(false)} />
      )}
    </AppLayout>
  );
}

function IconBtn({ title, onClick, children }: { title: string; onClick: () => void; children: React.ReactNode }) {
  return <button title={title} aria-label={title} onClick={onClick} className="flex h-7 w-7 items-center justify-center rounded-md border border-border text-muted-foreground hover:border-gold/50 hover:text-foreground">{children}</button>;
}

function MarkPaidDialog({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: () => void }) {
  const balance = Number(row.balance ?? row.invoice_amount);
  const [amount, setAmount] = useState(String(balance));
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [mode, setMode] = useState("Bank transfer");
  const [reference, setReference] = useState("");
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    try {
      await api.markInvoicePaid(row.id, { amount: Number(amount), date, mode, reference: reference || undefined });
      toast.success(row.source === "zoho" ? "Marked paid — recording it in Zoho Books" : "Marked paid");
      onDone();
    } catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent flip3d className="max-w-md">
        <DialogHeader><DialogTitle>Mark {row.invoice_uin} paid</DialogTitle></DialogHeader>
        <div className="grid grid-cols-2 gap-3 text-xs">
          <label className="col-span-2">Amount received ({row.currency}) · balance {money(balance, row.currency)}
            <input type="number" min={0.01} max={balance} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm" /></label>
          <label>Date received<input type="date" max={new Date().toISOString().slice(0, 10)} value={date} onChange={(e) => setDate(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm" /></label>
          <label>Mode<select value={mode} onChange={(e) => setMode(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm">{MODES.map((m) => <option key={m}>{m}</option>)}</select></label>
          <label className="col-span-2">Reference (UTR / cheque no.)<input value={reference} onChange={(e) => setReference(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm" /></label>
        </div>
        {row.source === "zoho" && <p className="rounded-md bg-blue-500/10 px-3 py-2 text-[11px] text-blue-700">This payment will also be recorded in Zoho Books against the invoice.</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={saving || !(Number(amount) > 0) || Number(amount) > balance + 0.005} onClick={save}>
            {saving && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}Mark paid
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function WaiveDialog({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: () => void }) {
  const balance = Number(row.balance ?? row.invoice_amount);
  const [amount, setAmount] = useState(String(balance));
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    try { await api.requestWaiver(row.id, Number(amount), reason.trim()); toast.success("Sent to a partner for approval"); onDone(); }
    catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent flip3d className="max-w-md">
        <DialogHeader><DialogTitle>Waive off {row.invoice_uin}</DialogTitle></DialogHeader>
        <div className="space-y-3 text-xs">
          <label className="block">Amount to waive ({row.currency}) · balance {money(balance, row.currency)}
            <input type="number" min={0.01} max={balance} step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm" /></label>
          <label className="block">Reason (shown to the approver and in reports)
            <textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className="mt-1 w-full rounded-md border border-border bg-background p-2 text-sm" /></label>
          <p className="flex items-start gap-1.5 rounded-md bg-amber-500/10 px-3 py-2 text-[11px] text-amber-700"><AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            A partner or super admin must approve. Once approved, a full waiver is written off in Zoho Books; a part waiver needs a credit note in Zoho.</p>
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-violet-600 text-white hover:bg-violet-700" disabled={saving || !reason.trim() || !(Number(amount) > 0) || Number(amount) > balance + 0.005} onClick={save}>
            {saving && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}Request waiver
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function NoteDialog({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: () => void }) {
  const { props } = usePage() as any;
  const staff = !["client", "client_admin", "client_finance"].includes(props.auth?.user?.role);
  const [note, setNote] = useState("");
  const [promised, setPromised] = useState("");
  const [saving, setSaving] = useState(false);
  const [history, setHistory] = useState<{ id: number; note: string; promised_on: string | null; by: string | null; at: string }[]>([]);
  useEffect(() => { if (staff) api.request<any[]>(`/pending-payments/${row.id}/follow-ups`).then(setHistory).catch(() => setHistory([])); }, [row.id, staff]);
  const save = async () => {
    setSaving(true);
    try {
      await api.request(`/pending-payments/${row.id}/status-note`, { method: "POST", body: JSON.stringify({ status_note: note.trim(), promised_on: promised || null }) });
      toast.success(promised ? `Follow-up saved; reminders pause until ${fmtDate(promised)}` : "Follow-up saved"); onDone();
    }
    catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent flip3d className="max-w-md">
        <DialogHeader><DialogTitle>Follow-up · {row.invoice_uin}</DialogTitle></DialogHeader>
        {row.status_note && <p className="rounded-md bg-muted/50 px-3 py-2 text-xs">Last: {row.status_note}{row.status_note_at ? ` · ${fmtDate(row.status_note_at)}` : ""}</p>}
        <textarea autoFocus rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Called accounts, paying by 13 Dec"
          className="w-full rounded-md border border-border bg-background p-2 text-sm" />
        {staff && (
          <label className="flex items-center justify-between gap-2 text-xs">Client promised to pay by (pauses reminders until then)
            <input type="date" value={promised} min={new Date().toISOString().slice(0, 10)} onChange={(e) => setPromised(e.target.value)} className="h-8 rounded-md border border-border bg-background px-2" />
          </label>
        )}
        {history.length > 0 && (
          <div className="max-h-40 space-y-1 overflow-auto rounded-md border border-border/60 p-2 text-[11px]">
            {history.map((h) => (
              <p key={h.id}><span className="text-muted-foreground">{fmtDate(h.at)} · {h.by ?? "—"}:</span> {h.note}{h.promised_on ? <span className="text-blue-600"> (promised {fmtDate(h.promised_on)})</span> : null}</p>
            ))}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={saving || !note.trim()} onClick={save}>{saving && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}Save</Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
