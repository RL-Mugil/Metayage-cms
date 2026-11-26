import { useEffect, useState } from "react"
import { CheckCircle2, Loader2, Lock, LockOpen, RefreshCw, ShieldCheck, TriangleAlert, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"
import { fmtDate } from "@/lib/date-utils"

interface Month { month: string; label: string; locked: boolean; locked_by: string | null; locked_at: string | null; open_flags: number }
interface Flag { id: number; month: string; what: string; reference: string | null; at: string | null; client: string | null }
interface Approval {
  id: number; kind: string; quote_id: number; status: string; note: string | null; quote: string; amount: number; client: string | null
  requested_by: string | null; requested_at: string | null; decided_by: string | null; decided_at: string | null
}
interface LedgerCheck { checked: number; at: string; differ: { client_id: number; code: string; name: string; portal: number; zoho: number; difference: number }[] }
interface Controls {
  months: Month[]; flags: Flag[]; approvals: Approval[]; ledger_check: LedgerCheck | null
  exchange: { from: string; gain: number; loss: number; lines: { date: string; reference: string; client: string | null; amount: number; notes: string | null }[] }
  renewal_quotes: { enabled: boolean; days: number }
  can: { unlock: boolean }
}

const inr = (v: number) => `₹${Math.abs(Number(v)).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`

/**
 * Financial Suite → Close & controls (Accounts): close months, review changes Zoho made in a
 * closed month, approve quotes before they go to Zoho, the nightly ledger-vs-Zoho check, and
 * exchange gain/loss on foreign-currency invoices.
 */
export function FinanceControls() {
  const [data, setData] = useState<Controls | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = () => api.request<Controls>("/finance/controls").then(setData).catch((e: any) => toast.error(e?.message ?? "Could not load controls."))
  useEffect(() => { load() }, [])

  const run = async (key: string, fn: () => Promise<unknown>, ok: string) => {
    setBusy(key)
    try { await fn(); toast.success(ok); await load() } catch (e: any) { toast.error(e?.message ?? "Failed.") } finally { setBusy(null) }
  }
  const lock = (m: Month) => run(`m-${m.month}`, () => api.request(`/finance/controls/lock/${m.month}`, { method: "POST", body: JSON.stringify({}) }), `${m.label} closed`)
  const unlock = (m: Month) => {
    const reason = window.prompt(`Reopen ${m.label}? Give a reason (kept in the audit log).`)
    if (!reason?.trim()) return
    run(`m-${m.month}`, () => api.request(`/finance/controls/unlock/${m.month}`, { method: "POST", body: JSON.stringify({ reason }) }), `${m.label} reopened`)
  }
  const review = (body: object) => run("review", () => api.request("/finance/controls/review", { method: "POST", body: JSON.stringify(body) }), "Marked reviewed")
  const decide = (a: Approval, approve: boolean) => {
    const note = approve ? "" : (window.prompt("Why is this quote not approved? (sent to the requester)") ?? "")
    if (!approve && !note.trim()) return
    run(`a-${a.id}`, () => api.request(`/finance/quote-approvals/${a.id}/decide`, { method: "POST", body: JSON.stringify({ approve, note }) }), approve ? "Approved: send it from the quote's Zoho button" : "Rejected")
  }

  if (!data) return <div className="flex h-40 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
  const pending = data.approvals.filter((a) => a.status === "pending")

  return (
    <div className="grid gap-4 xl:grid-cols-2">
      {/* Ledger vs Zoho */}
      <section className="rounded-xl border border-border p-4">
        <div className="mb-2 flex items-center justify-between">
          <h3 className="flex items-center gap-1.5 text-sm font-semibold"><ShieldCheck className="h-4 w-4 text-gold" />Client ledgers vs Zoho Books</h3>
          <span className="text-[11px] text-muted-foreground">{data.ledger_check ? `checked ${fmtDate(data.ledger_check.at)}` : "not run yet"} · nightly 04:00 and after each import</span>
        </div>
        {!data.ledger_check ? <p className="text-xs text-muted-foreground">The first check runs tonight.</p> : data.ledger_check.differ.length === 0 ? (
          <p className="flex items-center gap-1.5 text-sm text-emerald-600"><CheckCircle2 className="h-4 w-4" />All {data.ledger_check.checked} linked clients match Zoho.</p>
        ) : (
          <>
            <p className="mb-2 text-sm"><span className="font-semibold text-amber-600">{data.ledger_check.differ.length}</span> of {data.ledger_check.checked} clients differ from Zoho.</p>
            <table className="w-full text-xs">
              <thead className="text-[10px] uppercase text-muted-foreground"><tr><th className="py-1 text-left">Client</th><th className="text-right">Portal</th><th className="text-right">Zoho</th><th className="text-right">Difference</th></tr></thead>
              <tbody>{data.ledger_check.differ.map((d) => (
                <tr key={d.client_id} className="border-t border-border/60"><td className="py-1"><span className="font-mono">{d.code}</span> {d.name}</td>
                  <td className="text-right font-mono">{inr(d.portal)}{d.portal < 0 ? " Cr" : ""}</td><td className="text-right font-mono">{inr(d.zoho)}{d.zoho < 0 ? " Cr" : ""}</td>
                  <td className="text-right font-mono text-amber-600">{d.difference < 0 ? "−" : ""}{inr(d.difference)}</td></tr>
              ))}</tbody>
            </table>
          </>
        )}
      </section>

      {/* Quote approvals */}
      <section className="rounded-xl border border-border p-4">
        <h3 className="mb-2 text-sm font-semibold">Quotes waiting for approval before Zoho {pending.length > 0 && <span className="ml-1 rounded-full bg-amber-500/15 px-1.5 text-amber-700">{pending.length}</span>}</h3>
        {data.approvals.length === 0 ? <p className="text-xs text-muted-foreground">No requests. Quotes sent by Accounts are approved as they are sent.</p> : (
          <ul className="max-h-72 space-y-1.5 overflow-auto text-xs">
            {data.approvals.map((a) => (
              <li key={a.id} className="flex items-start justify-between gap-2 rounded-md border border-border/60 px-2.5 py-1.5">
                <div className="min-w-0">
                  <p><span className="font-mono font-semibold">{a.quote}</span> · {inr(a.amount)} · {a.client ?? "—"}</p>
                  <p className="text-[10px] text-muted-foreground">Asked by {a.requested_by ?? "—"} {a.requested_at ? fmtDate(a.requested_at) : ""}{a.note ? ` · ${a.note}` : ""}</p>
                  {a.status !== "pending" && <p className={`text-[10px] ${a.status === "approved" ? "text-emerald-600" : "text-red-600"}`}>{a.status} by {a.decided_by ?? "—"}</p>}
                </div>
                {a.status === "pending" && (
                  <div className="flex shrink-0 gap-1">
                    <Button size="sm" className="h-7 bg-gold text-black hover:bg-gold/90" disabled={busy !== null} onClick={() => decide(a, true)}>Approve</Button>
                    <Button size="sm" variant="outline" className="h-7" disabled={busy !== null} onClick={() => decide(a, false)}><X className="h-3.5 w-3.5" /></Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Month close */}
      <section className="rounded-xl border border-border p-4">
        <h3 className="mb-1 text-sm font-semibold">Month close</h3>
        <p className="mb-2 text-[11px] text-muted-foreground">A closed month refuses portal edits dated in it (mark paid, waivers, invoice edits, costs). Zoho imports still apply and are listed for review.</p>
        <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-4">
          {data.months.map((m) => (
            <div key={m.month} className={`rounded-md border px-2 py-1.5 text-xs ${m.locked ? "border-emerald-500/40 bg-emerald-500/5" : "border-border"}`}>
              <div className="flex items-center justify-between">
                <span className="font-medium">{m.label}</span>
                {m.locked ? <Lock className="h-3 w-3 text-emerald-600" /> : <LockOpen className="h-3 w-3 text-muted-foreground" />}
              </div>
              {m.open_flags > 0 && <p className="text-[10px] text-amber-600">{m.open_flags} to review</p>}
              {m.locked ? (
                data.can.unlock && <button className="text-[10px] text-muted-foreground hover:text-foreground hover:underline" disabled={busy !== null} onClick={() => unlock(m)}>Reopen</button>
              ) : (
                m.month < new Date().toISOString().slice(0, 7) && <button className="text-[10px] text-gold hover:underline" disabled={busy !== null} onClick={() => lock(m)}>Close month</button>
              )}
            </div>
          ))}
        </div>
      </section>

      {/* Changes in closed months */}
      <section className="rounded-xl border border-border p-4">
        <div className="mb-2 flex items-center justify-between">
          <h3 className="flex items-center gap-1.5 text-sm font-semibold"><TriangleAlert className="h-4 w-4 text-amber-500" />Changes in closed months</h3>
          {data.flags.length > 0 && <Button size="sm" variant="outline" className="h-7" disabled={busy !== null} onClick={() => review({ ids: data.flags.map((f) => f.id) })}>Mark all reviewed</Button>}
        </div>
        {data.flags.length === 0 ? <p className="text-xs text-muted-foreground">Nothing changed in a closed month.</p> : (
          <ul className="max-h-72 space-y-1 overflow-auto text-xs">
            {data.flags.map((f) => (
              <li key={f.id} className="flex items-center justify-between gap-2 border-b border-border/50 py-1">
                <span className="min-w-0"><span className="font-mono">{f.month}</span> · {f.what}{f.reference ? ` · ${f.reference}` : ""}{f.client ? <span className="text-muted-foreground"> · {f.client}</span> : null}</span>
                <button className="shrink-0 text-[10px] text-muted-foreground hover:text-foreground" onClick={() => review({ ids: [f.id] })}>Reviewed</button>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Renewal quotes, quote first */}
      <section className="rounded-xl border border-border p-4 xl:col-span-2">
        <h3 className="mb-1 text-sm font-semibold">Renewal quotes</h3>
        <p className="mb-2 text-[11px] text-muted-foreground">When on, the daily scan drafts a renewal quote before each annuity. Accounts reviews it and sends it to Zoho; the client then approves it in the portal and Accounts creates the invoice in Zoho.</p>
        <div className="flex flex-wrap items-center gap-3 text-xs">
          <label className="flex items-center gap-1.5"><input type="checkbox" checked={data.renewal_quotes.enabled} disabled={busy !== null}
            onChange={(e) => run("rq", () => api.request("/finance/controls/renewal-quotes", { method: "PUT", body: JSON.stringify({ enabled: e.target.checked, days: data.renewal_quotes.days }) }), e.target.checked ? "Renewal quotes on" : "Renewal quotes off")} />
            Draft renewal quotes automatically</label>
          <label className="flex items-center gap-1.5">Days before the annuity
            <input type="number" min={7} max={365} defaultValue={data.renewal_quotes.days} className="h-7 w-20 rounded-md border border-border bg-background px-2"
              onBlur={(e) => Number(e.target.value) !== data.renewal_quotes.days && run("rq", () => api.request("/finance/controls/renewal-quotes", { method: "PUT", body: JSON.stringify({ enabled: data.renewal_quotes.enabled, days: Number(e.target.value) }) }), "Saved")} /></label>
        </div>
      </section>

      {/* Exchange gain / loss */}
      <section className="rounded-xl border border-border p-4 xl:col-span-2">
        <div className="mb-2 flex items-center justify-between">
          <h3 className="flex items-center gap-1.5 text-sm font-semibold"><RefreshCw className="h-4 w-4 text-gold" />Exchange gain / loss (since {fmtDate(data.exchange.from)})</h3>
          <span className="text-xs">Gain <span className="font-mono text-emerald-600">{inr(data.exchange.gain)}</span> · Loss <span className="font-mono text-red-600">{inr(data.exchange.loss)}</span> · Net <span className="font-mono">{data.exchange.gain - data.exchange.loss < 0 ? "−" : ""}{inr(data.exchange.gain - data.exchange.loss)}</span></span>
        </div>
        {data.exchange.lines.length === 0 ? <p className="text-xs text-muted-foreground">No foreign-currency invoices settled at a different rate this year.</p> : (
          <table className="w-full text-xs">
            <thead className="text-[10px] uppercase text-muted-foreground"><tr><th className="py-1 text-left">Date</th><th className="text-left">Invoice</th><th className="text-left">Client</th><th className="text-right">Gain / loss</th><th className="text-left pl-3">Note</th></tr></thead>
            <tbody>{data.exchange.lines.map((l, i) => (
              <tr key={i} className="border-t border-border/60"><td className="py-1 font-mono">{fmtDate(l.date)}</td><td className="font-mono">{l.reference.replace("FX · ", "")}</td><td className="font-mono">{l.client ?? "—"}</td>
                <td className={`text-right font-mono ${l.amount < 0 ? "text-red-600" : "text-emerald-600"}`}>{l.amount < 0 ? "−" : "+"}{inr(l.amount)}</td><td className="pl-3 text-muted-foreground">{l.notes}</td></tr>
            ))}</tbody>
          </table>
        )}
      </section>
    </div>
  )
}
