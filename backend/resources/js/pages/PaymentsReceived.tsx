import { Head, Link } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Link2, Loader2, Search, X } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { TableCard } from "@/components/modern/table-card";
import { useFitRows } from "@/components/modern/use-fit-rows";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

/** One customer payment: cash received, the invoices it settled, TDS withheld and any unused (advance) part. */
interface Payment {
  id: string;
  number: string | null;
  date: string;
  source: "zoho" | "portal";
  client: { id: number; code: string | null; name: string | null } | null;
  customer_name: string | null;
  mode: string | null;
  reference: string | null;
  amount: number;
  applied: { invoice_id: number | null; invoice: string; amount: number; tds: number }[];
  applied_total: number;
  tds: number;
  unused: number;
  links: { id: number; quote_id: number; quote: string | null; amount: number; note: string | null; invoiced: boolean; applied: boolean }[];
  unlinked: number;
  suggestions?: { id: number; number: string; due: number }[];
}
interface Quote { id: number; number: string; date: string | null; status: string; amount: number; advance_linked: number; net_due: number; title: string | null }

const inr = (v: number) => `₹${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;

export default function PaymentsReceived() {
  const [fitRef, perPage] = useFitRows(52, 25, 0);
  const [rows, setRows] = useState<Payment[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [totals, setTotals] = useState({ received: 0, tds: 0, unused: 0, unlinked: 0 });
  const [clients, setClients] = useState<{ id: number; code: string | null; name: string | null }[]>([]);
  const [canLink, setCanLink] = useState(false);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [clientId, setClientId] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [unusedOnly, setUnusedOnly] = useState(false);
  const [loading, setLoading] = useState(true);
  const [linkRow, setLinkRow] = useState<Payment | null>(null);

  useEffect(() => { const t = window.setTimeout(() => { setQuery(search.trim()); setPage(1); }, 300); return () => window.clearTimeout(t); }, [search]);
  useEffect(() => { setPage(1); }, [clientId, from, to, unusedOnly, perPage]);

  const load = () => {
    setLoading(true);
    const p = new URLSearchParams({ page: String(page), per_page: String(Math.max(5, perPage)) });
    if (query) p.set("search", query);
    if (clientId) p.set("client_id", clientId);
    if (from) p.set("from", from);
    if (to) p.set("to", to);
    if (unusedOnly) p.set("unused", "1");
    api.getPaymentsReceived(p)
      .then((res: any) => {
        setRows(res?.data ?? []);
        setMeta({ current_page: res?.current_page ?? 1, last_page: res?.last_page ?? 1, total: res?.total ?? 0 });
        setTotals(res?.totals ?? { received: 0, tds: 0, unused: 0, unlinked: 0 });
        setClients(res?.clients ?? []);
        setCanLink(!!res?.can?.link);
      })
      .catch((e: any) => toast.error(e?.message || "Failed to load payments."))
      .finally(() => setLoading(false));
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [page, query, clientId, from, to, unusedOnly, perPage]);

  const invoiceFromPayment = async (r: Payment, q: { id: number; number: string; due: number }) => {
    if (!window.confirm(`${r.number} pays quote ${q.number} (${inr(q.due)} due). Create the tax invoice in Zoho and apply this payment to it?`)) return;
    try { const res = await api.invoiceQuoteFromPayment(q.id, r.id); toast.success(res.message); load(); } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  const applyInZoho = async (id: number, amount: number, quote: string | null) => {
    if (!window.confirm(`Apply ${inr(amount)} of this payment to the Zoho invoice made from ${quote ?? "the quote"}? This writes to Zoho Books.`)) return;
    try { await api.request(`/advance-links/${id}/apply`, { method: "POST", body: "{}" }); toast.success("Applied in Zoho"); load(); } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  const unlink = async (id: number) => {
    try { await api.unlinkAdvance(id); toast.success("Advance unlinked from the quote"); load(); } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  return (
    <AppLayout>
      <Head title="Payments Received" />
      <FitPage eyebrow="Billing" title="Payments Received"
        summary="Every payment received, the invoices it settled, the TDS the client withheld, and any amount not yet applied to an invoice (advance)."
        actions={<Link href="/pending-payments" className="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">Pending Payments →</Link>}>
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          <div className="flex shrink-0 flex-wrap gap-2">
            {[
              { label: "Cash received", value: inr(totals.received), color: "#16a34a" },
              { label: "TDS withheld (not cash)", value: inr(totals.tds), color: "#d97706" },
              { label: "Advance (unused)", value: inr(totals.unused), color: "#0ea5e9", onClick: () => setUnusedOnly((v) => !v), active: unusedOnly },
              { label: "Advance not earmarked", value: inr(totals.unlinked), color: "#64748b" },
              { label: "Payments", value: meta.total.toLocaleString("en-IN"), color: "#3b82f6" },
            ].map((c) => (
              <button key={c.label} type="button" onClick={c.onClick} disabled={!c.onClick}
                className={`glass flex min-w-[120px] flex-1 items-center gap-2 rounded-xl px-2.5 py-1 text-left ${c.onClick ? "cursor-pointer hover:-translate-y-0.5" : "cursor-default"} ${c.active ? "ring-2 ring-sky-500/60" : ""} transition-transform`}>
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: c.color, boxShadow: `0 0 8px ${c.color}` }} />
                <span className="min-w-0"><span className="block truncate font-display text-sm font-semibold leading-tight xl:text-base">{c.value}</span>
                  <span className="block truncate text-[9px] uppercase tracking-wider text-muted-foreground">{c.label}</span></span>
              </button>
            ))}
          </div>

          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <div className="relative min-w-[200px] flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search payment no., reference, client or invoice…"
                className="h-8 w-full rounded-md border border-border bg-background/70 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold/25" />
            </div>
            <select value={clientId} onChange={(e) => setClientId(e.target.value)} className="h-8 max-w-[220px] rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All clients</option>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.code ? `${c.code} · ` : ""}{c.name}</option>)}
            </select>
            <div className="flex items-center gap-1 text-[11px]">
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="From" />
              <span className="text-muted-foreground">–</span>
              <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="To" />
              {(from || to) && <button onClick={() => { setFrom(""); setTo(""); }} className="text-muted-foreground hover:text-foreground" aria-label="Clear dates"><X className="h-3.5 w-3.5" /></button>}
            </div>
            <label className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <input type="checkbox" checked={unusedOnly} onChange={(e) => setUnusedOnly(e.target.checked)} /> With advance only
            </label>
          </div>

          <TableCard ref={fitRef} footer={meta.total > 0 && (
            <div className="flex items-center justify-between px-4 py-1.5 text-xs text-muted-foreground">
              <span>{meta.total.toLocaleString("en-IN")} payments</span>
              <div className="flex items-center gap-1">
                <Button size="sm" variant="outline" className="h-7" disabled={page <= 1 || loading} onClick={() => setPage((p) => p - 1)}>Prev</Button>
                <span className="px-1">{meta.current_page}/{meta.last_page}</span>
                <Button size="sm" variant="outline" className="h-7" disabled={page >= meta.last_page || loading} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}>
            <table className="w-full min-w-[900px] text-xs">
              <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 text-left">Date</th>
                  <th className="px-3 py-2 text-left">Payment</th>
                  <th className="px-3 py-2 text-left">Client</th>
                  <th className="px-3 py-2 text-right">Received</th>
                  <th className="px-3 py-2 text-left">Settled against</th>
                  <th className="px-3 py-2 text-right">TDS</th>
                  <th className="px-3 py-2 text-left">Advance (unused)</th>
                </tr>
              </thead>
              <tbody className="table-rows-3d">
                {loading && rows.length === 0 && <tr><td colSpan={7} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                {!loading && rows.length === 0 && <tr><td colSpan={7} className="py-12 text-center text-sm text-muted-foreground">No payments match this view.</td></tr>}
                {rows.map((r) => (
                  <tr key={r.id} className="border-t border-border/60 align-top">
                    <td className="whitespace-nowrap px-3 py-2 font-mono text-[11px]">{fmtDate(r.date)}</td>
                    <td className="px-3 py-2">
                      <div className="font-mono font-semibold text-gold">{r.number ?? "—"}</div>
                      <div className="text-[10px] text-muted-foreground">{[r.mode, r.reference].filter(Boolean).join(" · ") || (r.source === "portal" ? "Not yet in Zoho" : "")}</div>
                    </td>
                    <td className="max-w-[200px] px-3 py-2">
                      <div className="truncate font-medium">{r.client?.name ?? r.customer_name ?? "—"}</div>
                      <div className="truncate text-[10px] text-muted-foreground">{r.client?.code ?? (r.client ? "" : "Not linked to a portal client")}</div>
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-green-600">{inr(r.amount)}</td>
                    <td className="px-3 py-2">
                      {r.applied.length === 0 ? <span className="text-[10px] text-muted-foreground">Not applied to any invoice</span> : (
                        <div className="space-y-0.5">
                          {r.applied.map((a, i) => (
                            <div key={i} className="flex gap-2 text-[11px]">
                              <span className="font-mono">{a.invoice}</span>
                              <span className="text-muted-foreground">{inr(a.amount)}</span>
                              {a.tds > 0 && <span className="text-amber-700">+ TDS {inr(a.tds)}</span>}
                            </div>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2 text-right text-amber-700">{r.tds > 0 ? inr(r.tds) : "—"}</td>
                    <td className="px-3 py-2">
                      {r.unused > 0 ? (
                        <div className="space-y-1">
                          <div className="font-semibold text-sky-600">{inr(r.unused)}</div>
                          {r.links.map((l) => (
                            <div key={l.id} className="flex items-center gap-1 text-[10px]">
                              <span className="rounded border border-green-200 bg-green-50 px-1.5 py-0.5 text-green-700">
                                {inr(l.amount)} → {l.quote ?? "quote"}{l.applied ? " · applied in Zoho" : l.invoiced ? " · invoiced" : ""}
                              </span>
                              {canLink && l.invoiced && !l.applied && <button onClick={() => applyInZoho(l.id, l.amount, l.quote)} className="text-gold hover:underline">Apply in Zoho</button>}
                              {canLink && !l.applied && <button onClick={() => unlink(l.id)} className="text-muted-foreground hover:text-red-600" title="Unlink"><X className="h-3 w-3" /></button>}
                            </div>
                          ))}
                          {canLink && (r.suggestions ?? []).map((q) => (
                            <button key={q.id} onClick={() => invoiceFromPayment(r, q)} className="block text-left text-[10px] font-medium text-emerald-700 hover:underline">
                              Pays {q.number} ({inr(q.due)}) → Create invoice &amp; apply
                            </button>
                          ))}
                          {canLink && r.unlinked > 0 && r.client && (
                            <button onClick={() => setLinkRow(r)} className="inline-flex items-center gap-1 text-[10px] text-gold hover:underline">
                              <Link2 className="h-3 w-3" /> Earmark for a quote
                            </button>
                          )}
                        </div>
                      ) : <span className="text-muted-foreground">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableCard>
        </div>
      </FitPage>
      {linkRow && <LinkDialog row={linkRow} onClose={() => setLinkRow(null)} onDone={() => { setLinkRow(null); load(); }} />}
    </AppLayout>
  );
}

/** Earmark part of an unused payment for one of the client's open proforma quotes. */
function LinkDialog({ row, onClose, onDone }: { row: Payment; onClose: () => void; onDone: () => void }) {
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [quoteId, setQuoteId] = useState<number | null>(null);
  const [amount, setAmount] = useState(String(row.unlinked));
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.getAdvanceQuotes(row.client!.id).then((q) => { setQuotes(q); if (q.length === 1) setQuoteId(q[0].id); }).catch(() => setQuotes([]));
  }, [row.client]);

  const picked = quotes?.find((q) => q.id === quoteId);
  useEffect(() => { if (picked) setAmount(String(Math.min(row.unlinked, picked.net_due))); }, [picked, row.unlinked]);

  const save = async () => {
    if (!quoteId) return;
    setSaving(true);
    try {
      await api.linkAdvanceToQuote(row.id, { quote_id: quoteId, amount: Number(amount), note: note.trim() || undefined });
      toast.success("Advance earmarked for the quote");
      onDone();
    } catch (e: any) {
      toast.error(e?.message ?? "Failed.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader><DialogTitle>Earmark {row.number}'s advance for a quote</DialogTitle></DialogHeader>
        <p className="text-xs text-muted-foreground">
          {inr(row.unlinked)} of this payment is not applied to any invoice. Zoho can't apply money to a proforma quote, so this is noted in the portal:
          the quote shows the advance and its net due. Apply the payment in Zoho once the quote is invoiced.
        </p>
        {quotes === null ? <Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /> : quotes.length === 0 ? (
          <p className="text-sm text-muted-foreground">{row.client?.name} has no open (sent, not invoiced) quotes.</p>
        ) : (
          <div className="space-y-3">
            <div className="max-h-56 space-y-1 overflow-y-auto">
              {quotes.map((q) => (
                <label key={q.id} className={`flex cursor-pointer items-start gap-2 rounded-md border px-2.5 py-1.5 text-xs ${quoteId === q.id ? "border-gold bg-gold/5" : "border-border"}`}>
                  <input type="radio" name="quote" checked={quoteId === q.id} onChange={() => setQuoteId(q.id)} className="mt-0.5" />
                  <span className="min-w-0 flex-1">
                    <span className="font-mono font-semibold">{q.number}</span> · {fmtDate(q.date)} · {q.status}
                    <span className="block truncate text-[10px] text-muted-foreground">{q.title}</span>
                  </span>
                  <span className="whitespace-nowrap text-right">
                    {inr(q.amount)}
                    {q.advance_linked > 0 && <span className="block text-[10px] text-green-600">net {inr(q.net_due)}</span>}
                  </span>
                </label>
              ))}
            </div>
            <div className="flex gap-2">
              <label className="flex-1 text-xs">Amount (₹)
                <input type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)}
                  className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2 text-sm" />
              </label>
              <label className="flex-[2] text-xs">Note (optional)
                <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255}
                  className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2 text-sm" />
              </label>
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
              <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={!quoteId || saving || Number(amount) <= 0} onClick={save}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Earmark"}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
