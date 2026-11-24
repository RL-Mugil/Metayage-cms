import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Loader2, Upload, CheckCircle2, FileText, MessageSquare, Download, Search } from "lucide-react";
import { FitPage } from "@/components/modern/fit-page";
import { AnimatedNumber } from "@/components/modern/animated-number";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

/** One unpaid invoice from the portal register (portal renewals and invoices imported from Zoho). */
interface PendingPaymentRow {
  id: number;
  type: string;
  status: string;
  source?: "portal" | "zoho";
  invoice_uin: string;
  docket_number: string | null;
  invoice_date: string | null;
  due_date?: string | null;
  days_overdue?: number;
  invoice_amount: string | number;
  balance?: number;
  currency: string;
  payment_status: string;
  invention_title?: string | null;
  client_name?: string | null;
  status_note?: string | null;
  status_note_at?: string | null;
  project?: { id: number; docket_number: string; project_name: string } | null;
  client?: { id: number; company_name?: string; legal_name?: string; client_code?: string } | null;
  proof_document?: { id: number; file_name: string; storage_path: string } | null;
}

function paymentStatusVariant(status: string): "default" | "secondary" | "outline" | "destructive" {
  if (status === "Confirmed") return "default";
  if (status === "Proof Submitted") return "secondary";
  return "outline";
}

function formatMoney(amount: string | number, currency: string) {
  return `${currency} ${Number(amount).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
}

const PER_PAGE = 25;

export default function PendingPayments() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isClient = ["client", "client_admin", "client_finance"].includes(role);
  const isStaff = !isClient;

  const [rows, setRows] = useState<PendingPaymentRow[]>([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [pendingTotals, setPendingTotals] = useState<Record<string, number>>({});
  const [overdueCount, setOverdueCount] = useState(0);
  // ?search=<UIN or docket> (links from Patent Portfolio) arrives already filtered.
  const initialSearch = typeof window === "undefined" ? "" : new URLSearchParams(window.location.search).get("search") ?? "";
  const [search, setSearch] = useState(initialSearch);
  const [query, setQuery] = useState(initialSearch.trim());
  const [overdueOnly, setOverdueOnly] = useState(() => typeof window !== "undefined" && new URLSearchParams(window.location.search).get("overdue") === "1");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [uploadingId, setUploadingId] = useState<number | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);
  const [approvingId, setApprovingId] = useState<number | null>(null);
  const [noteDraft, setNoteDraft] = useState<Record<number, string>>({});
  const [savingNoteId, setSavingNoteId] = useState<number | null>(null);
  const fileInputRefs = useRef<Record<number, HTMLInputElement | null>>({});

  // Search waits for a pause in typing, then starts again from page 1.
  useEffect(() => {
    const timer = window.setTimeout(() => { setQuery(search.trim()); setPage(1); }, 300);
    return () => window.clearTimeout(timer);
  }, [search]);

  const load = () => {
    setLoading(true);
    const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) });
    if (query) params.set("search", query);
    if (overdueOnly) params.set("overdue", "1");
    api.getPendingPayments(params)
      .then((res: any) => {
        setRows(res?.data ?? []);
        setMeta({ current_page: res?.current_page ?? 1, last_page: res?.last_page ?? 1, total: res?.total ?? 0 });
        setPendingTotals(res?.pending_totals ?? {});
        setOverdueCount(res?.overdue_count ?? 0);
        setError("");
      })
      .catch((e: any) => setError(e?.message || "Failed to load pending payments."))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [page, query, overdueOnly]);

  async function handleProofUpload(row: PendingPaymentRow, file: File) {
    setUploadingId(row.id);
    try {
      const doc: any = await api.uploadDocument(file, "Payment Proof", row.client?.id ?? null, null);
      await api.submitRenewalProof(row.id, doc.id);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to upload payment proof.");
    } finally {
      setUploadingId(null);
    }
  }

  async function handleConfirm(row: PendingPaymentRow) {
    setConfirmingId(row.id);
    try {
      await api.confirmRenewalReceipt(row.id);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to confirm receipt.");
    } finally {
      setConfirmingId(null);
    }
  }

  async function handleApproveQuote(row: PendingPaymentRow) {
    setApprovingId(row.id);
    try {
      await api.approveRenewalQuote(row.id);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to approve quote.");
    } finally {
      setApprovingId(null);
    }
  }

  async function handleSaveNote(row: PendingPaymentRow) {
    const note = (noteDraft[row.id] ?? "").trim();
    if (!note) return;
    setSavingNoteId(row.id);
    try {
      await api.postRenewalStatusNote(row.id, note);
      setNoteDraft((p) => ({ ...p, [row.id]: "" }));
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to save note.");
    } finally {
      setSavingNoteId(null);
    }
  }

  const totalLabel = Object.entries(pendingTotals).map(([currency, amount]) => formatMoney(amount, currency)).join(" + ") || "INR 0";
  const from = meta.total === 0 ? 0 : (meta.current_page - 1) * PER_PAGE + 1;
  const to = Math.min(meta.current_page * PER_PAGE, meta.total);

  return (
    <AppLayout>
      <Head title="Pending Payments" />
      <FitPage eyebrow="Billing" title="Pending Payments"
        summary={isClient
          ? "Invoices awaiting payment. Pay by bank transfer, then upload the payment proof here."
          : "Every unpaid invoice: renewals raised in the portal and invoices from Zoho Books."}>
      <div className="flex min-h-0 flex-1 flex-col gap-3">
        {error && (
          <div className="shrink-0 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>
        )}

        <div className="flex shrink-0 flex-wrap items-stretch gap-2">
          <div className="glass flex min-w-[180px] flex-1 items-center gap-2.5 rounded-xl px-3 py-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full bg-amber-500 shadow-[0_0_8px_#f59e0b]" />
            <span className="min-w-0"><span className="block truncate font-display text-lg font-semibold leading-tight text-amber-600">{totalLabel}</span><span className="block text-[10px] uppercase tracking-wider text-muted-foreground">Total pending</span></span>
          </div>
          <div className="glass flex min-w-[140px] flex-1 items-center gap-2.5 rounded-xl px-3 py-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-500 shadow-[0_0_8px_#3b82f6]" />
            <span><AnimatedNumber value={meta.total} className="block font-display text-lg font-semibold leading-tight" /><span className="block text-[10px] uppercase tracking-wider text-muted-foreground">{query || overdueOnly ? "Matching invoices" : "Unpaid invoices"}</span></span>
          </div>
          <button type="button" onClick={() => { setOverdueOnly((v) => !v); setPage(1); }} title={overdueOnly ? "Show all" : "Show only overdue"}
            className={`glass flex min-w-[140px] flex-1 items-center gap-2.5 rounded-xl px-3 py-2 text-left transition-transform hover:-translate-y-0.5 ${overdueOnly ? "ring-2 ring-red-500/60" : ""}`}>
            <span className="h-2.5 w-2.5 shrink-0 rounded-full bg-red-500 shadow-[0_0_8px_#ef4444]" />
            <span><AnimatedNumber value={overdueCount} className="block font-display text-lg font-semibold leading-tight text-red-600" /><span className="block text-[10px] uppercase tracking-wider text-muted-foreground">{overdueOnly ? "Overdue · click for all" : "Overdue · click to filter"}</span></span>
          </button>
        </div>

        <div className="relative max-w-md shrink-0">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search UIN, client, case or title…"
            className="h-9 w-full rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
        ) : rows.length === 0 ? (
          <Card className="border-border">
            <CardContent className="py-16 text-center text-sm text-muted-foreground">
              {query || overdueOnly ? "No pending payments match this filter." : "No pending payments right now."}
            </CardContent>
          </Card>
        ) : (
          <div className="thin-scrollbar space-y-3 pr-1 fit:min-h-0 fit:flex-1 fit:overflow-y-auto">
            {rows.map((row) => {
              const clientName = row.client?.company_name ?? row.client?.legal_name ?? row.client_name;
              const fromZoho = row.source === "zoho";
              const balance = Number(row.balance ?? row.invoice_amount);
              return (
                <Card key={row.id} className="transition-all hover:-translate-y-0.5 hover:shadow-3d-lg">
                  <CardContent className="py-4 space-y-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-mono text-xs font-semibold text-gold">{row.invoice_uin}</span>
                          {row.type === "quote" ? (
                            <Badge variant="outline">Estimate{row.status === "Accepted" ? " · Approved" : ""}</Badge>
                          ) : (
                            <Badge variant={paymentStatusVariant(row.payment_status)}>{row.payment_status}</Badge>
                          )}
                          {!!row.days_overdue && row.days_overdue > 0 && <Badge variant="destructive">{row.days_overdue} days overdue</Badge>}
                          {fromZoho && isStaff && <Badge variant="outline" className="text-[10px]">Zoho Books</Badge>}
                        </div>
                        <div className="mt-1 text-sm font-medium">{row.project?.project_name ?? row.invention_title ?? row.docket_number ?? "—"}</div>
                        {isStaff && clientName && (
                          <div className="text-xs text-muted-foreground">{row.client?.client_code ? `${row.client.client_code} · ` : ""}{clientName}</div>
                        )}
                        <div className="mt-1 text-xs text-muted-foreground font-mono">
                          {row.project?.docket_number ?? row.docket_number ?? "Case not linked"} · Invoice {fmtDate(row.invoice_date)}{row.due_date ? ` · Due ${fmtDate(row.due_date)}` : ""}
                        </div>
                        {row.type === "quote" && (
                          <div className="mt-1 text-xs text-amber-600">Estimate only — subject to change. Not a final invoice.</div>
                        )}
                      </div>
                      <div className="text-right">
                        <div className="font-display text-xl font-semibold">{formatMoney(balance, row.currency)}</div>
                        {balance < Number(row.invoice_amount) && (
                          <div className="text-xs text-muted-foreground">of {formatMoney(row.invoice_amount, row.currency)}</div>
                        )}
                      </div>
                    </div>

                    {row.status_note && (
                      <div className="flex items-start gap-2 rounded-md bg-muted/40 px-3 py-2 text-xs">
                        <MessageSquare className="h-3.5 w-3.5 mt-0.5 flex-shrink-0 text-muted-foreground" />
                        <div>
                          <span className="text-foreground">{row.status_note}</span>
                          {row.status_note_at && <span className="ml-2 text-muted-foreground">· {fmtDate(row.status_note_at)}</span>}
                        </div>
                      </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2 pt-1">
                      {row.proof_document && (
                        <Button size="sm" variant="outline" className="h-8 text-xs"
                          onClick={() => api.downloadDocument(row.proof_document!.storage_path, row.proof_document!.file_name)}>
                          <Download className="h-3 w-3 mr-1" />{row.proof_document.file_name}
                        </Button>
                      )}

                      {isClient && row.type === "quote" && row.status !== "Accepted" && row.status !== "Cancelled" && (
                        <Button size="sm" className="h-8 text-xs bg-gold hover:bg-gold/90 text-black"
                          disabled={approvingId === row.id}
                          onClick={() => handleApproveQuote(row)}>
                          {approvingId === row.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <CheckCircle2 className="h-3 w-3 mr-1" />}
                          Approve Estimate
                        </Button>
                      )}

                      {isClient && row.type !== "quote" && row.payment_status !== "Confirmed" && (
                        <>
                          <input
                            type="file"
                            className="hidden"
                            ref={(el) => { fileInputRefs.current[row.id] = el; }}
                            onChange={(e) => { const f = e.target.files?.[0]; if (f) handleProofUpload(row, f); e.target.value = ""; }}
                          />
                          <Button size="sm" variant="outline" className="h-8 text-xs"
                            disabled={uploadingId === row.id}
                            onClick={() => fileInputRefs.current[row.id]?.click()}>
                            {uploadingId === row.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Upload className="h-3 w-3 mr-1" />}
                            {row.payment_status === "Proof Submitted" ? "Re-upload Proof" : "Upload Payment Proof"}
                          </Button>
                        </>
                      )}

                      {isStaff && row.type !== "quote" && row.payment_status === "Proof Submitted" && !fromZoho && (
                        <Button size="sm" className="h-8 text-xs bg-gold hover:bg-gold/90 text-black"
                          disabled={confirmingId === row.id}
                          onClick={() => handleConfirm(row)}>
                          {confirmingId === row.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <CheckCircle2 className="h-3 w-3 mr-1" />}
                          Confirm Receipt
                        </Button>
                      )}
                      {isStaff && fromZoho && row.payment_status === "Proof Submitted" && (
                        <span className="text-xs text-muted-foreground">Proof received: record the payment in Zoho Books and it updates here.</span>
                      )}

                      {row.payment_status !== "Confirmed" && (
                        <div className="flex items-center gap-1.5 flex-1 min-w-[220px]">
                          <input
                            value={noteDraft[row.id] ?? ""}
                            onChange={(e) => setNoteDraft((p) => ({ ...p, [row.id]: e.target.value }))}
                            placeholder="Status update (e.g. paying by 13 Aug)…"
                            className="h-8 flex-1 rounded-md border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                          />
                          <Button size="sm" variant="ghost" className="h-8 text-xs"
                            disabled={savingNoteId === row.id || !(noteDraft[row.id] ?? "").trim()}
                            onClick={() => handleSaveNote(row)}>
                            {savingNoteId === row.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <FileText className="h-3 w-3" />}
                          </Button>
                        </div>
                      )}
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}

        {meta.total > 0 && (
          <div className="glass flex shrink-0 items-center justify-between rounded-xl px-4 py-2 text-sm text-muted-foreground">
            <span>Showing {from}–{to} of {meta.total.toLocaleString("en-IN")}</span>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" disabled={page <= 1 || loading} onClick={() => setPage((p) => p - 1)}>Previous</Button>
              <Button size="sm" variant="outline" disabled={page >= meta.last_page || loading} onClick={() => setPage((p) => p + 1)}>Next</Button>
            </div>
          </div>
        )}
      </div>
      </FitPage>
    </AppLayout>
  );
}
