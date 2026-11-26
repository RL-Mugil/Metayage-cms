import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { usePage } from "@inertiajs/react";
import { X, Loader2, ShieldCheck, FileDown } from "lucide-react";
import { api } from "@/lib/api-client";
import { ProjectDetailPanel } from "@/components/project-detail-panel";
import { ClientInventors } from "@/components/inventors/client-inventors";
import { INVENTOR_LOGIN_ROLES } from "@/components/inventors/inventor-picker";
import { ClientPortalUsers, PORTAL_MANAGE_ROLES } from "@/components/clients/client-portal-users";

const ENTITY_VERIFY_ROLES = ["super_admin", "partner", "manager", "galvanizer"];
// Staff who manage inventors (InventorPolicy) and who may give them a portal login.
const INVENTOR_ROLES = ["super_admin", "partner", "director", "manager", "associate", "paralegal", "galvanizer"];

function verifyBadgeClass(status: string | undefined) {
  if (status === "Verified") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Mismatch" || status === "Failed") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

const inr = (v: number) => `₹${Math.abs(Number(v)).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;
/** A minus ledger balance is credit the client holds with us (Zoho "unused credit"). */
const balanceText = (v: number) => (Number(v) < -0.009 ? `${inr(v)} Cr` : inr(v));

interface LedgerLine {
  id: number | string; transaction_date: string | null; created_at?: string | null; document_type: string;
  document_reference: string | null; debit: number | string; credit: number | string; tds: number | string;
  balance: number | string | null; notes?: string | null; quote?: boolean;
}
interface OpenQuote { id: number; date: string | null; number: string; status: string; amount: number; title: string | null; advance_linked?: number; advance_payments?: string[]; net_due?: number }
interface LedgerSummary { billed: number; received: number; tds: number; credit_notes: number; waived: number; advance: number; balance: number; quoted?: number; exchange?: number }

const TYPE_BADGE: Record<string, string> = {
  TDS: "bg-amber-100 text-amber-800 border-amber-200",
  "Balance credit": "bg-green-100 text-green-700 border-green-200",
  Advance: "bg-green-100 text-green-700 border-green-200",
  "Credit note": "bg-sky-100 text-sky-700 border-sky-200",
  Quote: "bg-muted text-muted-foreground border-border",
};

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

interface Props {
  clientId: number;
  onClose: () => void;
}

type Tab = "cases" | "invoices" | "ledger" | "inventors" | "users";

export function ClientDetailPanel({ clientId, onClose }: Props) {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role ?? "";
  const canVerifyEntity = ENTITY_VERIFY_ROLES.includes(role);
  const canManageInventors = INVENTOR_ROLES.includes(role);
  // Users: the client's portal logins (same list as the Client Portal page).
  const tabs: Tab[] = canManageInventors ? ["cases", "invoices", "ledger", "inventors", "users"] : ["cases", "invoices", "ledger", "users"];
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<Tab>("cases");
  const [detailProjectId, setDetailProjectId] = useState<number | null>(null);
  const [entityVerifying, setEntityVerifying] = useState(false);
  const [entityVerifyMessage, setEntityVerifyMessage] = useState("");
  const [withQuotes, setWithQuotes] = useState(false);
  const [downloading, setDownloading] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState("");
  const isClientUser = String(role).startsWith("client");

  async function downloadStatement(format: "pdf" | "xlsx") {
    // Without quotes: the plain ledger. With quotes: proformas listed apart (internal copy for staff).
    const view = !withQuotes ? "plain" : isClientUser ? "client" : "internal";
    setDownloading(format);
    setDownloadError("");
    try {
      await api.downloadClientStatement(clientId, format, view);
    } catch (e: any) {
      setDownloadError(e.message || "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  function load() {
    let cancelled = false;
    setLoading(true);
    setData(null);
    api.request(`/clients/${clientId}/detail`).then((res: any) => {
      if (!cancelled) { setData(res); setLoading(false); }
    }).catch(() => {
      if (!cancelled) setLoading(false);
    });
    return () => { cancelled = true; };
  }

  useEffect(() => { const cleanup = load(); return cleanup; }, [clientId]);

  async function verifyEntity() {
    setEntityVerifying(true);
    setEntityVerifyMessage("");
    try {
      const result = await api.verifyClientEntity(clientId);
      const parts = [result.gstin?.message, result.cin?.message].filter(Boolean);
      setEntityVerifyMessage(parts.join(" "));
      load();
    } catch (e: any) {
      setEntityVerifyMessage(e.message || "Failed to verify.");
    } finally {
      setEntityVerifying(false);
    }
  }

  const client = data?.client;

  return createPortal(
    <>
      <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
      <div className="fixed right-0 top-0 h-full w-full max-w-3xl bg-background border-l border-border z-50 flex flex-col shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <p className="text-xs text-muted-foreground font-mono">
              {client?.client_code ?? "Loading…"}
            </p>
            <h2 className="text-base font-semibold truncate max-w-[500px]">
              {client?.legal_name ?? client?.company_name ?? ""}
            </h2>
            {client && (
              <p className="text-xs text-muted-foreground mt-0.5 flex items-center gap-1.5 flex-wrap">
                {client.nationality}
                {client.gst_type ? ` · ${client.gst_type}` : ""}
                {client.gstin && (
                  <>
                    {` · ${client.gstin}`}
                    <span className={`text-[10px] px-1.5 py-0.5 rounded border ${verifyBadgeClass(client.gstin_verification_status)}`}>
                      {client.gstin_verification_status ?? "Unverified"}
                    </span>
                  </>
                )}
                {client.cin_number && (
                  <span className={`text-[10px] px-1.5 py-0.5 rounded border ${verifyBadgeClass(client.cin_verification_status)}`}>
                    CIN: {client.cin_verification_status ?? "Unverified"}
                  </span>
                )}
                {canVerifyEntity && (client.gstin || client.cin_number) && (
                  <button onClick={verifyEntity} disabled={entityVerifying}
                    className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded border border-border hover:bg-muted/50 disabled:opacity-50">
                    <ShieldCheck className="h-3 w-3" />{entityVerifying ? "Verifying…" : "Verify via EntityLocker"}
                  </button>
                )}
              </p>
            )}
            {entityVerifyMessage && <p className="text-[11px] text-muted-foreground mt-1">{entityVerifyMessage}</p>}
          </div>
          <button onClick={onClose} className="p-1.5 rounded hover:bg-muted/50">
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* KPI strip */}
        {data && (
          <div className="flex gap-3 px-6 py-3 border-b border-border bg-muted/20 flex-shrink-0">
            {[
              { label: "Cases", value: data.projects.length },
              { label: "Active", value: (data.projects as any[]).filter((p: any) => p.status === "In Progress").length },
              { label: "Granted", value: (data.projects as any[]).filter((p: any) => p.status === "Granted" || p.status === "Completed").length },
              { label: "Invoiced", value: `₹${Number(data.invoice_summary.total_invoiced).toLocaleString("en-IN", { maximumFractionDigits: 0 })}` },
              data.ledger_summary && Number(data.ledger_summary.balance) < -0.009
                ? { label: "Credit with us", value: inr(data.ledger_summary.balance), tone: "text-green-600" }
                : { label: "Balance", value: inr(data.ledger_summary ? data.ledger_summary.balance : data.invoice_summary.total_pending) },
            ].map((kpi: { label: string; value: string | number; tone?: string }) => (
              <div key={kpi.label} className="flex-1 text-center">
                <p className="text-[10px] text-muted-foreground">{kpi.label}</p>
                <p className={`text-sm font-semibold font-mono ${kpi.tone ?? ""}`}>{kpi.value}</p>
              </div>
            ))}
          </div>
        )}

        {/* Tabs */}
        <div className="flex border-b border-border flex-shrink-0 px-6">
          {tabs.map((t) => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-2.5 text-xs font-medium capitalize border-b-2 transition-colors ${
                tab === t
                  ? "border-primary text-primary"
                  : "border-transparent text-muted-foreground hover:text-foreground"
              }`}>
              {t === "cases" ? "Cases" : t === "invoices" ? "Invoices" : t === "ledger" ? "Ledger" : t === "inventors" ? "Inventors" : "Users"}
            </button>
          ))}
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto">
          {loading ? (
            <div className="flex items-center justify-center h-40">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : !data ? (
            <div className="flex items-center justify-center h-40 text-muted-foreground text-sm">
              Failed to load details.
            </div>
          ) : (
            <>
              {/* Cases */}
              {tab === "cases" && (
                <div className="p-6">
                  {data.projects.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-10">No cases for this client.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Docket</th>
                          <th className="pb-2 font-medium">Title</th>
                          <th className="pb-2 font-medium">Status</th>
                          <th className="pb-2 font-medium">Current Stage</th>
                          <th className="pb-2 font-medium">Filed</th>
                          <th className="pb-2 font-medium">Deadline</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.projects as any[]).map((p: any) => (
                          <tr key={p.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3">
                              <button
                                onClick={() => setDetailProjectId(p.id)}
                                className="font-mono font-semibold text-gold hover:underline text-left"
                              >
                                {p.docket_number}
                              </button>
                            </td>
                            <td className="py-2.5 pr-3 max-w-[180px] truncate font-medium" title={p.project_name}>
                              {p.project_name}
                            </td>
                            <td className="py-2.5 pr-3">
                              <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                p.status === "Completed" ? "bg-green-100 text-green-700" :
                                p.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                                p.status === "On Hold" ? "bg-amber-100 text-amber-700" :
                                "bg-muted text-muted-foreground"
                              }`}>{p.status}</span>
                            </td>
                            <td className="py-2.5 pr-3 text-muted-foreground">{p.current_stage ?? "—"}</td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(p.filing_date)}</td>
                            <td className="py-2.5 font-mono text-muted-foreground">{fmtDate(p.hard_deadline)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {/* Invoices */}
              {tab === "invoices" && (
                <div className="p-6 space-y-4">
                  <div className="grid grid-cols-3 gap-3">
                    {[
                      { label: "Total Invoiced", value: data.invoice_summary.total_invoiced },
                      { label: "Received", value: data.invoice_summary.total_received },
                      { label: "Pending", value: data.invoice_summary.total_pending },
                    ].map((c) => (
                      <div key={c.label} className="rounded-lg border border-border bg-muted/30 p-3">
                        <p className="text-[10px] text-muted-foreground">{c.label}</p>
                        <p className="text-sm font-semibold font-mono mt-1">
                          ₹{Number(c.value).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                        </p>
                      </div>
                    ))}
                  </div>
                  {data.invoices.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-8">No invoices for this client.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Invoice #</th>
                          <th className="pb-2 font-medium">Status</th>
                          <th className="pb-2 font-medium">Date</th>
                          <th className="pb-2 font-medium text-right">Total</th>
                          <th className="pb-2 font-medium text-right">Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.invoices as any[]).map((inv: any) => (
                          <tr key={inv.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3 font-mono text-gold font-semibold">{inv.invoice_code}</td>
                            <td className="py-2.5 pr-3">
                              <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                inv.status === "Paid" ? "bg-green-100 text-green-700" :
                                inv.status === "Overdue" ? "bg-red-100 text-red-700" :
                                inv.status === "Sent" || inv.status === "Viewed" ? "bg-blue-100 text-blue-700" :
                                inv.status === "Partially Paid" ? "bg-amber-100 text-amber-700" :
                                "bg-muted text-muted-foreground"
                              }`}>{inv.status}</span>
                            </td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(inv.created_at)}</td>
                            <td className="py-2.5 pr-3 text-right font-mono">
                              {inv.currency === "INR" ? "₹" : `${inv.currency} `}
                              {Number(inv.total_amount).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                            </td>
                            <td className="py-2.5 text-right font-mono">
                              {Number(inv.balance_due) > 0
                                ? <span className="text-muted-foreground">₹{Number(inv.balance_due).toLocaleString("en-IN", { maximumFractionDigits: 0 })}</span>
                                : <span className="text-green-600">Cleared</span>}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {tab === "users" && (
                <div className="p-6">
                  <ClientPortalUsers clientId={clientId} canManage={PORTAL_MANAGE_ROLES.includes(role)} />
                </div>
              )}

              {tab === "inventors" && canManageInventors && (
                <div className="p-6">
                  <ClientInventors clientId={clientId} canGrantPortal={INVENTOR_LOGIN_ROLES.includes(role)} />
                </div>
              )}

              {tab === "ledger" && (() => {
                const summary: LedgerSummary | undefined = data.ledger_summary;
                const quotes: OpenQuote[] = data.quotes_open ?? [];
                // Proformas are never ledger lines and never in the balance: "with quotes" lists them apart, below.
                const lines: LedgerLine[] = (data.ledger as LedgerLine[]).filter((l) => l.document_type !== "Quote");
                const proformaDue = quotes.reduce((a, q) => a + Number(q.net_due ?? q.amount ?? 0), 0);
                return (
                  <div className="p-6 space-y-3">
                    <div className="flex items-center justify-between gap-2 flex-wrap">
                      <div className="inline-flex rounded-md border border-border p-0.5 text-[11px]">
                        {[["Ledger without quotes", false], ["Ledger with quotes", true]].map(([label, value]) => (
                          <button key={String(label)} onClick={() => setWithQuotes(value as boolean)}
                            className={`px-2.5 py-1 rounded ${withQuotes === value ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}>
                            {label as string}{value && quotes.length > 0 ? ` (${quotes.length})` : ""}
                          </button>
                        ))}
                      </div>
                      <div className="flex items-center gap-1.5">
                        <span className="text-[10px] text-muted-foreground">{withQuotes ? (isClientUser ? "Statement with proformas" : "Internal statement, with proformas") : "Statement without proformas"}</span>
                        {(["pdf", "xlsx"] as const).map((f) => (
                          <button key={f} onClick={() => downloadStatement(f)} disabled={downloading !== null}
                            className="inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border border-border hover:bg-muted/50 disabled:opacity-50">
                            {downloading === f ? <Loader2 className="h-3 w-3 animate-spin" /> : <FileDown className="h-3 w-3" />}{f === "pdf" ? "PDF" : "Excel"}
                          </button>
                        ))}
                      </div>
                    </div>
                    {downloadError && <p className="text-[11px] text-red-600">{downloadError}</p>}

                    {summary && (
                      <div className="grid grid-cols-3 sm:grid-cols-6 gap-2 text-center">
                        {[
                          ["Invoiced", summary.billed, ""],
                          ["Received", summary.received, "text-green-600"],
                          ["TDS withheld", summary.tds, "text-amber-700"],
                          ...(withQuotes && quotes.length > 0 ? [["Unpaid proformas (not in balance)", proformaDue, "text-blue-600"]] : [["Credit notes", summary.credit_notes, ""]]),
                          ["Balance credit", summary.advance, "text-green-600"],
                          [Number(summary.balance) < -0.009 ? "Credit with us" : "Balance due", summary.balance, Number(summary.balance) < -0.009 ? "text-green-600" : Number(summary.balance) > 0.009 ? "text-red-600" : ""],
                        ].map(([label, value, tone]) => (
                          <div key={String(label)} className="rounded-md border border-border bg-muted/20 px-2 py-1.5">
                            <p className="text-[9px] uppercase tracking-wide text-muted-foreground">{label as string}</p>
                            <p className={`text-xs font-semibold font-mono ${tone as string}`}>{inr(Number(value))}</p>
                          </div>
                        ))}
                      </div>
                    )}

                    {lines.length === 0 ? (
                      <p className="text-muted-foreground text-sm text-center py-8">No ledger entries for this client.</p>
                    ) : (
                      <table className="w-full text-xs">
                        <thead>
                          <tr className="text-left border-b border-border text-muted-foreground">
                            <th className="pb-2 font-medium">Date</th>
                            <th className="pb-2 font-medium">Type</th>
                            <th className="pb-2 font-medium">Reference</th>
                            <th className="pb-2 font-medium text-right">Debit</th>
                            <th className="pb-2 font-medium text-right">Credit</th>
                            <th className="pb-2 font-medium text-right">TDS</th>
                            <th className="pb-2 font-medium text-right">Balance</th>
                          </tr>
                        </thead>
                        <tbody>
                          {lines.map((l) => (
                            <tr key={l.id} className="border-b border-border/50 hover:bg-muted/20"
                              title={l.notes ?? undefined}>
                              <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(l.transaction_date ?? l.created_at)}</td>
                              <td className="py-2.5 pr-3 text-muted-foreground">
                                {TYPE_BADGE[l.document_type]
                                  ? <span className={`text-[10px] px-1.5 py-0.5 rounded border ${TYPE_BADGE[l.document_type]}`}>{l.document_type}</span>
                                  : l.document_type}
                              </td>
                              <td className="py-2.5 pr-3 font-mono text-gold text-[10px]">{l.document_reference}</td>
                              <td className="py-2.5 pr-3 text-right font-mono">
                                {Number(l.debit) > 0 ? inr(Number(l.debit)) : "—"}
                              </td>
                              <td className="py-2.5 pr-3 text-right font-mono text-green-600">
                                {Number(l.credit) > 0 ? inr(Number(l.credit)) : "—"}
                              </td>
                              <td className="py-2.5 pr-3 text-right font-mono text-amber-700">
                                {Number(l.tds) > 0 ? inr(Number(l.tds)) : "—"}
                              </td>
                              <td className={`py-2.5 text-right font-mono font-semibold ${l.balance !== null && Number(l.balance) < -0.009 ? "text-green-600" : ""}`}>
                                {balanceText(Number(l.balance))}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                    <p className="text-[10px] text-muted-foreground">
                      TDS is tax the client withheld: it settles the invoice but is not cash received. "Cr" / Balance credit is money received and not yet applied to an invoice.
                    </p>
                    {withQuotes && (
                      <div className="space-y-1.5 pt-2">
                        <p className="text-xs font-semibold">Proformas <span className="font-normal text-muted-foreground">— not yet invoiced, not included in the balance</span></p>
                        {quotes.length === 0 ? <p className="text-[11px] text-muted-foreground">No proformas pending.</p> : (
                          <table className="w-full text-xs">
                            <thead><tr className="text-left border-b border-border text-muted-foreground">
                              <th className="pb-2 font-medium">Date</th><th className="pb-2 font-medium">Proforma</th><th className="pb-2 font-medium">Status</th>
                              <th className="pb-2 font-medium text-right">Amount</th><th className="pb-2 font-medium text-right">Balance credit earmarked</th><th className="pb-2 font-medium text-right">Net due</th>
                            </tr></thead>
                            <tbody className="table-rows-3d">
                              {quotes.map((q) => (
                                <tr key={q.id} className="border-b border-border/50" title={q.title ?? undefined}>
                                  <td className="py-2 pr-3 font-mono text-muted-foreground">{fmtDate(q.date)}</td>
                                  <td className="py-2 pr-3 font-mono text-[10px] text-blue-600">{q.number}</td>
                                  <td className="py-2 pr-3 text-muted-foreground">{q.status}</td>
                                  <td className="py-2 pr-3 text-right font-mono">{inr(Number(q.amount))}</td>
                                  <td className="py-2 pr-3 text-right font-mono text-green-600">{q.advance_linked ? `${inr(Number(q.advance_linked))}${q.advance_payments?.length ? ` (${q.advance_payments.join(", ")})` : ""}` : "—"}</td>
                                  <td className="py-2 text-right font-mono font-semibold">{inr(Number(q.net_due ?? q.amount))}</td>
                                </tr>
                              ))}
                              <tr className="font-semibold"><td /><td className="py-2">Total proformas</td><td /><td className="py-2 pr-3 text-right font-mono">{inr(quotes.reduce((a, q) => a + Number(q.amount), 0))}</td><td /><td className="py-2 text-right font-mono">{inr(proformaDue)}</td></tr>
                            </tbody>
                          </table>
                        )}
                      </div>
                    )}
                  </div>
                );
              })()}
            </>
          )}
        </div>
      </div>

      {/* Project detail nested panel */}
      {detailProjectId !== null && (
        <ProjectDetailPanel projectId={detailProjectId} onClose={() => setDetailProjectId(null)} />
      )}
    </>,
    document.body
  );
}
