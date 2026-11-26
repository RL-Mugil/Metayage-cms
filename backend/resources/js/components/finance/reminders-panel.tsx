import { useEffect, useState } from "react"
import { AlertTriangle, Loader2, PauseCircle, Send, Settings2, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"
import { fmtDate } from "@/lib/date-utils"

interface Recipient { name?: string | null; email: string }
interface Reminder {
  id: number; band: number; status: string; escalated: boolean; amount: number; subject: string; body: string; recipients: Recipient[]; error: string | null
  sent_at: string | null; sent_by: string | null; created_at: string | null; can_send: boolean
  client: { id: number; code: string | null; name: string | null; paused_until: string | null }
  invoices: { id: number; uin: string; due_date: string | null; currency: string; balance: number; paid: boolean; promised_on: string | null }[]
}
interface Settings { bands: number[]; escalate_after: number; reply_to: string | null; intro: string; closing: string }
interface Data { data: Reminder[]; counts: Record<string, number>; settings: Settings; can: { settings: boolean } }

const inr = (v: number) => `₹${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`
const bandLabel = (b: number) => (b < 0 ? `${-b} days before due` : b === 0 ? "Due today" : `${b}+ days overdue`)

/**
 * Pending Payments → Reminders: drafts made each morning by ageing band. The client's Client
 * Manager or Accounts reviews, edits and sends them (with the statement attached); nothing goes
 * to a client unseen.
 */
export function RemindersPanel() {
  const [status, setStatus] = useState<"draft" | "sent" | "skipped" | "failed">("draft")
  const [data, setData] = useState<Data | null>(null)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [busy, setBusy] = useState(false)
  const [edit, setEdit] = useState<Reminder | null>(null)
  const [pause, setPause] = useState<Reminder | null>(null)
  const [settingsOpen, setSettingsOpen] = useState(false)

  const load = () => api.request<Data>(`/collections/reminders?status=${status}`).then((d) => { setData(d); setPicked(new Set()) })
    .catch((e: any) => toast.error(e?.message ?? "Could not load reminders."))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [status])

  const send = async (ids: number[]) => {
    if (!window.confirm(`Send ${ids.length} reminder email(s) to the clients now? Each has the statement of account attached.`)) return
    setBusy(true)
    try {
      const r = await api.request<{ sent: number; skipped: number; failed: number }>("/collections/reminders/send", { method: "POST", body: JSON.stringify({ ids }) })
      toast.success(`Sent ${r.sent}${r.skipped ? `, skipped ${r.skipped} (paid or no email)` : ""}${r.failed ? `, ${r.failed} failed` : ""}.`)
      await load()
    } catch (e: any) { toast.error(e?.message ?? "Failed.") } finally { setBusy(false) }
  }
  const skip = async (r: Reminder) => {
    try { await api.request(`/collections/reminders/${r.id}/skip`, { method: "POST", body: "{}" }); toast.success("Skipped"); load() } catch (e: any) { toast.error(e?.message ?? "Failed.") }
  }
  const sendable = (data?.data ?? []).filter((r) => r.can_send && r.status === "draft" && r.recipients.length > 0)

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-2.5">
      <div className="flex shrink-0 flex-wrap items-center gap-2 text-xs">
        <div className="inline-flex rounded-md border border-border p-0.5">
          {(["draft", "sent", "skipped", "failed"] as const).map((s) => (
            <button key={s} onClick={() => setStatus(s)} className={`rounded px-2.5 py-1 capitalize ${status === s ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}>
              {s === "draft" ? "To review" : s}{data?.counts[s] ? ` (${data.counts[s]})` : ""}
            </button>
          ))}
        </div>
        <span className="text-muted-foreground">Drafted every morning at {data ? data.settings.bands.map(bandLabel).join(" · ") : "…"}</span>
        <div className="ml-auto flex gap-2">
          {data?.can.settings && <Button size="sm" variant="outline" className="h-8" onClick={() => setSettingsOpen(true)}><Settings2 className="mr-1 h-3.5 w-3.5" />Settings</Button>}
          {status === "draft" && sendable.length > 0 && (
            <Button size="sm" className="h-8 bg-gold text-black hover:bg-gold/90" disabled={busy || picked.size === 0} onClick={() => send([...picked])}>
              {busy ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : <Send className="mr-1 h-3.5 w-3.5" />}Send selected ({picked.size})
            </Button>
          )}
        </div>
      </div>

      <div className="min-h-0 flex-1 space-y-2 overflow-auto pr-1">
        {!data ? <Loader2 className="mx-auto mt-10 h-5 w-5 animate-spin text-gold" /> : data.data.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">{status === "draft" ? "No reminders waiting. New drafts appear each morning." : "Nothing here."}</p>
        ) : data.data.map((r) => (
          <div key={r.id} className={`rounded-xl border p-3 text-xs ${r.escalated ? "border-red-500/40" : "border-border"}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <label className="flex min-w-0 items-start gap-2">
                {status === "draft" && r.can_send && <input type="checkbox" className="mt-1" checked={picked.has(r.id)} disabled={r.recipients.length === 0}
                  onChange={(e) => setPicked((p) => { const n = new Set(p); e.target.checked ? n.add(r.id) : n.delete(r.id); return n })} />}
                <span className="min-w-0">
                  <span className="font-semibold">{r.client.code} · {r.client.name}</span>
                  <span className={`ml-2 rounded px-1.5 py-0.5 text-[10px] ${r.band < 0 ? "bg-blue-500/10 text-blue-700" : r.escalated ? "bg-red-500/15 text-red-700" : "bg-amber-500/15 text-amber-700"}`}>{bandLabel(r.band)}</span>
                  {r.escalated && <span className="ml-1 inline-flex items-center gap-0.5 text-[10px] text-red-600"><AlertTriangle className="h-3 w-3" />escalated</span>}
                  {r.client.paused_until && <span className="ml-1 text-[10px] text-muted-foreground">paused until {fmtDate(r.client.paused_until)}</span>}
                  <span className="block text-muted-foreground">{r.subject}</span>
                  <span className="block text-[11px]">To: {r.recipients.length ? r.recipients.map((x) => x.email).join(", ") : <span className="text-red-600">no billing email on file</span>}</span>
                </span>
              </label>
              <div className="text-right">
                <div className="font-mono text-sm font-semibold">{inr(r.amount)}</div>
                {r.status === "sent" && <div className="text-[10px] text-emerald-600">sent {r.sent_at ? fmtDate(r.sent_at) : ""} by {r.sent_by}</div>}
                {r.error && <div className="max-w-[260px] text-[10px] text-red-600">{r.error}</div>}
              </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {r.invoices.map((i) => (
                <span key={i.id} className={`rounded border px-1.5 py-0.5 font-mono text-[10px] ${i.paid ? "border-emerald-300 text-emerald-700 line-through" : "border-border"}`}>
                  {i.uin} · {i.currency} {i.balance.toLocaleString("en-IN")} · due {fmtDate(i.due_date)}{i.promised_on ? ` · promised ${fmtDate(i.promised_on)}` : ""}
                </span>
              ))}
            </div>
            {status === "draft" && r.can_send && (
              <div className="mt-2 flex gap-1.5">
                <Button size="sm" variant="outline" className="h-7" onClick={() => setEdit(r)}>Review / edit</Button>
                <Button size="sm" className="h-7 bg-gold text-black hover:bg-gold/90" disabled={busy || r.recipients.length === 0} onClick={() => send([r.id])}><Send className="mr-1 h-3 w-3" />Send</Button>
                <Button size="sm" variant="ghost" className="h-7" onClick={() => setPause(r)}><PauseCircle className="mr-1 h-3 w-3" />Pause client</Button>
                <Button size="sm" variant="ghost" className="h-7 text-muted-foreground" onClick={() => skip(r)}><X className="mr-1 h-3 w-3" />Skip</Button>
              </div>
            )}
          </div>
        ))}
      </div>
      {edit && <EditDialog reminder={edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); load() }} />}
      {pause && <PauseDialog reminder={pause} onClose={() => setPause(null)} onDone={() => { setPause(null); load() }} />}
      {settingsOpen && data && <SettingsDialog settings={data.settings} onClose={() => setSettingsOpen(false)} onDone={() => { setSettingsOpen(false); load() }} />}
    </div>
  )
}

function EditDialog({ reminder, onClose, onDone }: { reminder: Reminder; onClose: () => void; onDone: () => void }) {
  const [subject, setSubject] = useState(reminder.subject)
  const [body, setBody] = useState(reminder.body)
  const [to, setTo] = useState(reminder.recipients.map((r) => r.email).join(", "))
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try {
      const recipients = to.split(/[,;\s]+/).map((e) => e.trim()).filter(Boolean).map((email) => ({ email, name: reminder.recipients.find((r) => r.email === email)?.name ?? null }))
      await api.request(`/collections/reminders/${reminder.id}`, { method: "PUT", body: JSON.stringify({ subject, body, recipients }) })
      toast.success("Saved"); onDone()
    } catch (e: any) { toast.error(e?.message ?? "Failed.") } finally { setSaving(false) }
  }
  const field = "mt-1 w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm"
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-xl">
        <DialogHeader><DialogTitle>Reminder to {reminder.client.code} · {reminder.client.name}</DialogTitle></DialogHeader>
        <div className="space-y-3 text-xs">
          <label className="block">To (comma-separated)<input value={to} onChange={(e) => setTo(e.target.value)} className={field} /></label>
          <label className="block">Subject<input value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={255} className={field} /></label>
          <label className="block">Message (the invoice list and statement are added automatically)<textarea rows={6} value={body} onChange={(e) => setBody(e.target.value)} maxLength={4000} className={field} /></label>
        </div>
        <div className="flex justify-end gap-2"><Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={saving || !subject.trim() || !body.trim() || !to.trim()} onClick={save}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save"}</Button></div>
      </DialogContent>
    </Dialog>
  )
}

function PauseDialog({ reminder, onClose, onDone }: { reminder: Reminder; onClose: () => void; onDone: () => void }) {
  const [until, setUntil] = useState(reminder.client.paused_until ?? "")
  const [note, setNote] = useState("")
  const save = async () => {
    try {
      await api.request(`/collections/clients/${reminder.client.id}`, { method: "PUT", body: JSON.stringify({ paused_until: until || null, note: note || null }) })
      toast.success(until ? `Reminders paused until ${fmtDate(until)}` : "Reminders resumed"); onDone()
    } catch (e: any) { toast.error(e?.message ?? "Failed.") }
  }
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-sm">
        <DialogHeader><DialogTitle>Pause reminders · {reminder.client.code}</DialogTitle></DialogHeader>
        <label className="block text-xs">Pause until (leave empty to resume)<input type="date" value={until} onChange={(e) => setUntil(e.target.value)} className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2" /></label>
        <label className="block text-xs">Why (optional)<input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2" /></label>
        <div className="flex justify-end gap-2"><Button variant="outline" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" onClick={save}>Save</Button></div>
      </DialogContent>
    </Dialog>
  )
}

function SettingsDialog({ settings, onClose, onDone }: { settings: Settings; onClose: () => void; onDone: () => void }) {
  const [bands, setBands] = useState(settings.bands.join(", "))
  const [escalate, setEscalate] = useState(String(settings.escalate_after))
  const [replyTo, setReplyTo] = useState(settings.reply_to ?? "")
  const [intro, setIntro] = useState(settings.intro)
  const [closing, setClosing] = useState(settings.closing)
  const save = async () => {
    try {
      await api.request("/collections/settings", { method: "PUT", body: JSON.stringify({
        bands: bands.split(/[,\s]+/).filter(Boolean).map(Number), escalate_after: Number(escalate), reply_to: replyTo || null, intro, closing }) })
      toast.success("Settings saved"); onDone()
    } catch (e: any) { toast.error(e?.message ?? "Failed.") }
  }
  const field = "mt-1 w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm"
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader><DialogTitle>Reminder settings</DialogTitle></DialogHeader>
        <div className="space-y-3 text-xs">
          <label className="block">Bands: days relative to the due date (negative = before due), e.g. -3, 7, 30, 60<input value={bands} onChange={(e) => setBands(e.target.value)} className={field} /></label>
          <label className="block">Escalate to Accounts after (days overdue)<input type="number" min="1" value={escalate} onChange={(e) => setEscalate(e.target.value)} className={field} /></label>
          <label className="block">Reply-to address (client replies go here)<input type="email" value={replyTo} onChange={(e) => setReplyTo(e.target.value)} className={field} /></label>
          <label className="block">Opening paragraph<textarea rows={3} value={intro} onChange={(e) => setIntro(e.target.value)} maxLength={1000} className={field} /></label>
          <label className="block">Closing line<textarea rows={2} value={closing} onChange={(e) => setClosing(e.target.value)} maxLength={1000} className={field} /></label>
        </div>
        <div className="flex justify-end gap-2"><Button variant="outline" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" className="bg-gold text-black hover:bg-gold/90" onClick={save}>Save</Button></div>
      </DialogContent>
    </Dialog>
  )
}
