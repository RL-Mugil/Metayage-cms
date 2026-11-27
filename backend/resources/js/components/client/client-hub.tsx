import { useEffect, useRef, useState } from "react"
import { router } from "@inertiajs/react"
import { toast } from "sonner"
import { CheckCircle2, Copy, Download, FileCheck2, HelpCircle, Inbox, Loader2, MessageSquare, RefreshCw, Smartphone, Upload, Wallet, XCircle } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api, type ClientHubData } from "@/lib/api-client"
import { GlossaryText } from "@/components/glossary-text"

const money = (v: number, currency = "INR") => (currency === "INR" ? `₹${Math.round(v).toLocaleString("en-IN")}` : `${currency} ${Math.round(v).toLocaleString("en-IN")}`)
const fmt = (d?: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—")

type Invoice = ClientHubData["invoices"][number]
type Ask = { kind: "invoice" | "deadline" | "stage" | "renewal" | "quote" | "case"; ref: string; projectId?: number | null }

/**
 * Client hub: one inbox of what is waiting on the client — approvals, quotes, renewal
 * decisions and invoices to pay — each with an "Ask us" button that opens a discussion,
 * plus the monthly report PDF. Staff pass clientId to act for a client.
 */
export function ClientHub({ clientId }: { clientId?: number | null }) {
  const [data, setData] = useState<ClientHubData | null>(null)
  const [error, setError] = useState("")
  const [busy, setBusy] = useState<string | null>(null)
  const [pay, setPay] = useState<Invoice | null>(null)
  const [ask, setAsk] = useState<Ask | null>(null)
  const [note, setNote] = useState<Record<number, string>>({})

  const load = () => api.getClientHub(clientId).then((d) => { setData(d); setError("") }).catch((e: Error) => setError(e.message))
  useEffect(() => { load() }, [clientId])

  if (error) return <p className="py-6 text-center text-sm text-destructive">{error}</p>
  if (!data) return <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>

  const run = async (key: string, fn: () => Promise<unknown>, ok: string) => {
    setBusy(key)
    try { await fn(); toast.success(ok); await load() } catch (e) { toast.error((e as Error).message) } finally { setBusy(null) }
  }
  const waiting = data.counts.approvals + data.counts.quotes + data.counts.renewals + data.counts.invoices
  const AskButton = (a: Ask) => (
    <button onClick={() => setAsk(a)} className="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-gold"><MessageSquare className="h-3 w-3" /> Ask us</button>
  )

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 text-sm">
          <Inbox className="h-4 w-4 text-gold" />
          <span className="font-medium">{waiting === 0 ? "Nothing is waiting on you." : `${waiting} item${waiting === 1 ? "" : "s"} waiting on ${clientId ? data.client.name : "you"}`}</span>
        </div>
        <ScorecardButton clientId={clientId} />
      </div>

      {(data.approvals.length > 0 || data.quotes.length > 0) && (
        <Card className="border-border">
          <CardHeader className="pb-2"><CardTitle className="font-display text-sm flex items-center gap-2"><FileCheck2 className="h-4 w-4 text-gold" /> Approvals</CardTitle></CardHeader>
          <CardContent className="p-0">
            {data.approvals.map((a) => (
              <div key={`a${a.id}`} className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 first:border-0">
                <div className="min-w-0">
                  <p className="text-sm font-medium">{a.title}</p>
                  <p className="text-xs text-muted-foreground">{a.docket_number ? <span className="font-mono">{a.docket_number}</span> : null}{a.description ? ` · ${a.description}` : ""} · sent {fmt(a.submitted)}</p>
                </div>
                <div className="flex items-center gap-2">
                  {AskButton({ kind: "case", ref: a.title, projectId: a.project_id })}
                  {data.can_decide && <>
                    <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => run(`a${a.id}`, () => api.resolveApproval("Client", a.id, "Rejected"), "Sent back")}><XCircle className="mr-1 h-3.5 w-3.5" /> Reject</Button>
                    <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={busy !== null} onClick={() => run(`a${a.id}`, () => api.resolveApproval("Client", a.id, "Approved"), "Approved")}><CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Approve</Button>
                  </>}
                </div>
              </div>
            ))}
            {data.quotes.map((q) => (
              <div key={`q${q.id}`} className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 first:border-0">
                <div>
                  <p className="text-sm font-medium">Quote <span className="font-mono text-gold">{q.number}</span> · {money(q.amount, q.currency)}</p>
                  <p className="text-xs text-muted-foreground">Sent {fmt(q.date)} · approving lets us start the work</p>
                </div>
                <div className="flex items-center gap-2">
                  {AskButton({ kind: "quote", ref: q.number ?? String(q.id), projectId: q.project_id })}
                  {data.can_decide && !clientId && (
                    <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={busy !== null} onClick={() => run(`q${q.id}`, () => api.approveRenewalQuote(q.id), "Quote approved")}>Approve quote</Button>
                  )}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {data.renewals.length > 0 && (
        <Card className="border-border">
          <CardHeader className="pb-2">
            <CardTitle className="font-display text-sm flex items-center gap-2"><RefreshCw className="h-4 w-4 text-gold" /> Renewals in the next 120 days</CardTitle>
            <p className="text-xs text-muted-foreground">Tell us whether to pay each fee. Unpaid renewals make the patent lapse.</p>
          </CardHeader>
          <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {data.renewals.map((r) => (
              <div key={r.renewal_id} className={`rounded-md border p-3 ${r.days < 0 ? "border-red-500/40 bg-red-500/5" : "border-border"}`}>
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-mono text-xs font-semibold text-gold">{r.docket_number ?? r.application_number}</p>
                    <p className="truncate text-xs text-muted-foreground" title={r.title ?? ""}>{r.title ?? r.project_name ?? ""}</p>
                  </div>
                  <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold ${r.days < 0 ? "bg-red-500/15 text-red-600" : r.days <= 30 ? "bg-amber-500/15 text-amber-600" : "bg-muted text-muted-foreground"}`}>
                    {r.days < 0 ? `${-r.days}d overdue` : `in ${r.days}d`}
                  </span>
                </div>
                <div className="mt-2 grid grid-cols-3 gap-1 text-center text-[11px]">
                  <div className="rounded bg-muted/50 p-1"><p className="text-muted-foreground">Fee</p><p className="font-medium"><GlossaryText text={r.label} /></p></div>
                  <div className="rounded bg-muted/50 p-1"><p className="text-muted-foreground">Estimate</p><p className="font-medium">{r.estimate ? money(r.estimate.amount, r.estimate.currency) : "On request"}</p></div>
                  <div className="rounded bg-muted/50 p-1"><p className="text-muted-foreground">Years left</p><p className="font-medium">{r.years_left ?? "—"}</p></div>
                </div>
                <p className="mt-1 text-[10px] text-muted-foreground">Due {fmt(r.due_date)}{r.grace_ends_on ? ` · grace until ${fmt(r.grace_ends_on)}` : ""}</p>
                {r.decision ? (
                  <p className={`mt-2 text-xs font-medium ${r.decision === "renew" ? "text-emerald-600" : r.decision === "lapse" ? "text-red-600" : "text-amber-600"}`}>
                    {r.decision === "renew" ? "You chose to renew" : r.decision === "lapse" ? "You chose to let it lapse" : "You asked us for advice"} · {fmt(r.decided_at)}
                    {(data.can_renew ?? data.can_decide) && <button className="ml-2 text-[10px] text-muted-foreground underline" onClick={() => setData({ ...data, renewals: data.renewals.map((x) => x.renewal_id === r.renewal_id ? { ...x, decision: null } : x) })}>change</button>}
                  </p>
                ) : (data.can_renew ?? data.can_decide) ? (
                  <div className="mt-2 space-y-1.5">
                    <input value={note[r.renewal_id] ?? ""} onChange={(e) => setNote({ ...note, [r.renewal_id]: e.target.value })} placeholder="Note (optional)"
                      className="h-7 w-full rounded border border-border bg-background px-2 text-xs" />
                    <div className="grid grid-cols-3 gap-1">
                      {(["renew", "lapse", "ask"] as const).map((d) => (
                        <Button key={d} size="sm" variant={d === "renew" ? "default" : "outline"} disabled={busy !== null}
                          className={d === "renew" ? "h-7 bg-emerald-600 text-white hover:bg-emerald-700 text-[11px]" : "h-7 text-[11px]"}
                          onClick={() => run(`r${r.renewal_id}`, () => api.decideRenewal(r.renewal_id, d, note[r.renewal_id], clientId), d === "renew" ? "We will renew it" : d === "lapse" ? "Recorded — we will not pay this fee" : "Question sent")}>
                          {d === "renew" ? "Renew" : d === "lapse" ? "Let lapse" : "Ask us"}
                        </Button>
                      ))}
                    </div>
                  </div>
                ) : <p className="mt-2 text-[11px] text-muted-foreground">Your client admin decides on renewals.</p>}
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {data.invoices.length > 0 && (
        <Card className="border-border">
          <CardHeader className="pb-2"><CardTitle className="font-display text-sm flex items-center gap-2"><Wallet className="h-4 w-4 text-gold" /> Invoices to pay</CardTitle></CardHeader>
          <CardContent className="p-0">
            {data.invoices.map((i) => (
              <div key={i.id} className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 first:border-0">
                <div>
                  <p className="text-sm"><span className="font-mono text-gold">{i.number}</span> · <span className="font-medium">{money(i.outstanding, i.currency)}</span></p>
                  <p className={`text-xs ${i.overdue ? "text-red-600" : "text-muted-foreground"}`}>{i.due_date ? `${i.overdue ? "Was due" : "Due"} ${fmt(i.due_date)}` : "No due date"}{i.payment_status === "Proof Submitted" ? " · proof sent, we are checking it" : ""}</p>
                </div>
                <div className="flex items-center gap-2">
                  {AskButton({ kind: "invoice", ref: i.number ?? String(i.id), projectId: i.project_id })}
                  {!clientId && i.payment_status !== "Proof Submitted" && <Button size="sm" className="bg-gold text-black hover:bg-gold/90" onClick={() => setPay(i)}>Pay</Button>}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {pay && <PayDialog invoice={pay} details={data.payment_details} onClose={() => setPay(null)} onDone={() => { setPay(null); load() }} />}
      {ask && <AskDialog ask={ask} clientId={clientId} onClose={() => setAsk(null)} />}
    </div>
  )
}

function ScorecardButton({ clientId }: { clientId?: number | null }) {
  const [busy, setBusy] = useState(false)
  const months = Array.from({ length: 6 }, (_, i) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - i); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}` })
  const [month, setMonth] = useState(months[0])
  const download = async () => {
    setBusy(true)
    try { await api.downloadClientScorecard(month, clientId) } catch (e) { toast.error((e as Error).message) } finally { setBusy(false) }
  }
  return (
    <div className="flex items-center gap-1.5">
      <select value={month} onChange={(e) => setMonth(e.target.value)} className="h-8 rounded-md border border-border bg-background px-2 text-xs">
        {months.map((m) => <option key={m} value={m}>{new Date(`${m}-01T00:00:00`).toLocaleDateString("en-IN", { month: "short", year: "numeric" })}</option>)}
      </select>
      <Button size="sm" variant="outline" onClick={download} disabled={busy}>{busy ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : <Download className="mr-1 h-3.5 w-3.5" />} Monthly report</Button>
    </div>
  )
}

function PayDialog({ invoice, details, onClose, onDone }: { invoice: Invoice; details: ClientHubData["payment_details"]; onClose: () => void; onDone: () => void }) {
  const file = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const reference = invoice.number ?? String(invoice.id)
  const upi = details.upi_id ? `upi://pay?pa=${encodeURIComponent(details.upi_id)}&pn=${encodeURIComponent(details.account_name ?? "")}&am=${invoice.currency === "INR" ? invoice.outstanding.toFixed(2) : ""}&cu=INR&tn=${encodeURIComponent(reference)}` : null
  const copy = (text: string) => { navigator.clipboard?.writeText(text).then(() => toast.success("Copied")).catch(() => {}) }
  const rows: [string, string | null][] = [["Account name", details.account_name], ["Bank", details.bank_name], ["Account number", details.account_number], ["IFSC", details.ifsc], ["SWIFT", details.swift], ["Branch", details.branch], ["UPI ID", details.upi_id]]

  const upload = async (f: File) => {
    setUploading(true)
    try {
      const doc = await api.uploadDocument(f, "Payment Proof") as { id?: number; document?: { id: number } }
      const id = doc.id ?? doc.document?.id
      if (!id) throw new Error("Upload did not return a document.")
      await api.submitRenewalProof(invoice.id, id)
      toast.success("Thanks — we will confirm the payment shortly.")
      onDone()
    } catch (e) { toast.error((e as Error).message) } finally { setUploading(false) }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader><DialogTitle>Pay {reference} · {money(invoice.outstanding, invoice.currency)}</DialogTitle></DialogHeader>
        <div className="space-y-4 text-sm">
          <div className="rounded-md border border-border">
            {rows.filter(([, v]) => v).map(([label, value]) => (
              <div key={label} className="flex items-center justify-between border-t border-border px-3 py-1.5 first:border-0">
                <span className="text-xs text-muted-foreground">{label}</span>
                <button onClick={() => copy(value!)} className="flex items-center gap-1 font-mono text-xs hover:text-gold">{value} <Copy className="h-3 w-3" /></button>
              </div>
            ))}
            {rows.every(([, v]) => !v) && <p className="px-3 py-3 text-xs text-muted-foreground">Bank details have not been set up yet — please ask us.</p>}
          </div>
          <div className="flex items-center justify-between rounded-md bg-muted/40 px-3 py-2">
            <span className="text-xs">Payment reference</span>
            <button onClick={() => copy(reference)} className="flex items-center gap-1 font-mono text-xs font-semibold text-gold">{reference} <Copy className="h-3 w-3" /></button>
          </div>
          {upi && <a href={upi} className="flex items-center justify-center gap-2 rounded-md border border-gold/40 py-2 text-xs font-medium text-gold hover:bg-gold/10"><Smartphone className="h-4 w-4" /> Pay with a UPI app (on your phone)</a>}
          {details.note && <p className="text-xs text-muted-foreground">{details.note}</p>}
          <div className="border-t border-border pt-3">
            <p className="mb-2 text-xs text-muted-foreground">After paying, upload the receipt or bank screenshot so we can confirm it.</p>
            <input ref={file} type="file" accept=".pdf,.png,.jpg,.jpeg" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
            <Button className="w-full bg-gold text-black hover:bg-gold/90" disabled={uploading} onClick={() => file.current?.click()}>
              {uploading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />} Upload payment proof
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function AskDialog({ ask, clientId, onClose }: { ask: Ask; clientId?: number | null; onClose: () => void }) {
  const [message, setMessage] = useState("")
  const [busy, setBusy] = useState(false)
  const send = async () => {
    setBusy(true)
    try {
      const { thread_id } = await api.askAboutItem({ kind: ask.kind, ref: ask.ref, project_id: ask.projectId ?? undefined, message }, clientId)
      toast.success("Sent — we will reply in Discussions.", { action: { label: "Open", onClick: () => router.visit(`/discussions?thread=${thread_id}`) } })
      onClose()
    } catch (e) { toast.error((e as Error).message) } finally { setBusy(false) }
  }
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader><DialogTitle className="flex items-center gap-2"><HelpCircle className="h-4 w-4 text-gold" /> Ask about {ask.kind} {ask.ref}</DialogTitle></DialogHeader>
        <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={4} autoFocus placeholder="Your question…"
          className="w-full rounded-md border border-border bg-background p-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={busy || !message.trim()} onClick={send}>{busy && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Send</Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
