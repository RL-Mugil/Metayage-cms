import { useEffect, useMemo, useState } from "react"
import { toast } from "sonner"
import { Check, ChevronDown, Download, FileSpreadsheet, FileText, Loader2, PenLine, Search, Sheet } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"

export interface ReportClientOption { id: number; name: string; code: string | null }

export interface ReportDownloadTarget {
  id: string
  name: string
  description: string
  icon: React.ComponentType<{ className?: string }>
  color?: string
  clientFilter: boolean
}

type FileKind = "pdf" | "xlsx" | "csv"

const FILES: { key: FileKind; label: string; hint: string; icon: React.ComponentType<{ className?: string }> }[] = [
  { key: "pdf", label: "PDF", hint: "Letterhead data sheet", icon: FileText },
  { key: "xlsx", label: "Excel", hint: "Tabs, charts, filters", icon: FileSpreadsheet },
  { key: "csv", label: "CSV", hint: "Plain data", icon: Sheet },
]

/**
 * One form for every report: client (where the report supports it), all dates / one date /
 * a range, format and the signature PIN. The file downloads straight away — no preview.
 */
export function ReportDownloadDialog({ report, clients: given, initialClientCode = "", initialFrom = "", initialTo = "", onClose, onDownloaded }: {
  report: ReportDownloadTarget
  clients?: ReportClientOption[]
  initialClientCode?: string
  initialFrom?: string
  initialTo?: string
  onClose: () => void
  onDownloaded?: () => void
}) {
  const [file, setFile] = useState<FileKind>("pdf")
  const [clients, setClients] = useState<ReportClientOption[]>(given ?? [])
  const [client, setClient] = useState(initialClientCode)
  const [clientOpen, setClientOpen] = useState(false)
  const [clientQuery, setClientQuery] = useState("")
  const [dateMode, setDateMode] = useState<"all" | "one" | "range">(initialFrom || initialTo ? "range" : "all")
  const [date, setDate] = useState("")
  const [from, setFrom] = useState(initialFrom)
  const [to, setTo] = useState(initialTo)
  const [pin, setPin] = useState("")
  const [busy, setBusy] = useState<"signed" | "draft" | null>(null)
  const Icon = report.icon

  useEffect(() => {
    if (!report.clientFilter || (given && given.length)) return
    api.getClients(new URLSearchParams({ per_page: "2000" }))
      .then((res: { data?: { id: number; legal_name?: string | null; company_name?: string | null; client_code?: string | null }[] }) =>
        setClients((res?.data ?? []).map((c) => ({ id: c.id, name: c.legal_name ?? c.company_name ?? "", code: c.client_code ?? null }))))
      .catch(() => {})
  }, [report.clientFilter, given])

  const matches = useMemo(() => {
    const q = clientQuery.trim().toLowerCase()
    return clients.filter((c) => c.code && (!q || c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q))).slice(0, 60)
  }, [clients, clientQuery])
  const chosen = clients.find((c) => c.code === client)
  const datesOk = dateMode === "all" || (dateMode === "one" ? !!date : (!!from || !!to) && (!from || !to || from <= to))

  const go = async (signed: boolean) => {
    setBusy(signed ? "signed" : "draft")
    try {
      await api.downloadPaymentReport({
        type: report.id, file, pin: signed ? pin : undefined,
        client_code: report.clientFilter && client ? client : undefined,
        date: dateMode === "one" ? date : undefined,
        from_date: dateMode === "range" ? from || undefined : undefined,
        to_date: dateMode === "range" ? to || undefined : undefined,
      })
      toast.success(signed ? `${report.name} downloaded — signed` : `${report.name} downloaded — unsigned draft`)
      onDownloaded?.()
      onClose()
    } catch (e) { toast.error((e as Error).message) } finally { setBusy(null) }
  }

  const chip = (on: boolean) => `rounded-full px-3 py-1 text-xs font-medium transition ${on ? "bg-foreground text-background shadow-sm" : "border border-border text-muted-foreground hover:border-gold/50"}`
  const field = "h-10 w-full rounded-lg border border-border bg-background px-3 text-sm outline-none focus:border-gold/60 focus:ring-2 focus:ring-gold/15"

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-w-lg gap-0 overflow-hidden p-0">
        <div className="surface-mesh border-b border-border/60 px-6 pb-4 pt-5">
          <DialogHeader className="space-y-1 text-left">
            <DialogTitle className="flex items-center gap-2.5 text-lg">
              <span className="shadow-3d grid h-9 w-9 place-items-center rounded-xl bg-background"><Icon className={`h-4.5 w-4.5 ${report.color ?? "text-gold"}`} /></span>
              {report.name}
            </DialogTitle>
            <DialogDescription className="text-xs">{report.description}</DialogDescription>
          </DialogHeader>
        </div>

        <div className="space-y-4 px-6 py-5">
          {report.clientFilter && (
            <div className="relative">
              <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Client</div>
              <button type="button" onClick={() => setClientOpen((o) => !o)} className={`${field} flex items-center justify-between text-left`}>
                <span className="truncate">{chosen ? `${chosen.code} · ${chosen.name}` : "All clients (consolidated)"}</span>
                <ChevronDown className="h-4 w-4 shrink-0 opacity-60" />
              </button>
              {clientOpen && (
                <div className="glass absolute z-50 mt-1 w-full rounded-lg border border-border p-1.5 shadow-xl">
                  <div className="flex items-center gap-2 border-b border-border/60 px-2 pb-1.5">
                    <Search className="h-3.5 w-3.5 opacity-60" />
                    <input autoFocus value={clientQuery} onChange={(e) => setClientQuery(e.target.value)} placeholder="Search name or code…" className="h-8 w-full bg-transparent text-sm outline-none" />
                  </div>
                  <div className="max-h-56 overflow-y-auto py-1">
                    {[{ id: 0, name: "All clients (consolidated)", code: "" }, ...matches].map((c) => (
                      <button key={c.id} type="button" onClick={() => { setClient(c.code ?? ""); setClientOpen(false); setClientQuery("") }}
                        className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted">
                        <Check className={`h-3.5 w-3.5 ${client === (c.code ?? "") ? "text-gold" : "opacity-0"}`} />
                        {c.code ? <><span className="font-mono text-xs text-muted-foreground">{c.code}</span><span className="truncate">{c.name}</span></> : c.name}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Dates</div>
            <div className="mb-2 flex gap-1.5">
              {([["all", "All dates"], ["one", "One date"], ["range", "Date range"]] as const).map(([k, l]) => (
                <button key={k} type="button" onClick={() => setDateMode(k)} className={chip(dateMode === k)}>{l}</button>
              ))}
            </div>
            {dateMode === "one" && <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className={field} />}
            {dateMode === "range" && (
              <div className="flex items-center gap-2">
                <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={field} />
                <span className="text-muted-foreground">–</span>
                <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className={field} />
              </div>
            )}
          </div>

          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Format</div>
            <div className="grid grid-cols-3 gap-2">
              {FILES.map((f) => (
                <button key={f.key} type="button" onClick={() => setFile(f.key)}
                  className={`rounded-xl border p-3 text-left transition ${file === f.key ? "shadow-3d border-gold bg-gold/10" : "border-border hover:-translate-y-0.5 hover:border-gold/40"}`}>
                  <f.icon className="mb-1.5 h-4.5 w-4.5 text-gold" />
                  <div className="text-sm font-semibold">{f.label}</div>
                  <div className="text-[10px] leading-tight text-muted-foreground">{f.hint}</div>
                </button>
              ))}
            </div>
          </div>

          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Signature PIN</div>
            <input inputMode="numeric" maxLength={4} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
              onKeyDown={(e) => { if (e.key === "Enter" && pin.length === 4 && datesOk && !busy) go(true) }}
              placeholder="••••" className={`${field} text-center text-lg tracking-[0.6em]`} />
            <p className="mt-1 text-[10px] text-muted-foreground">Your 4-digit PIN adds the small gold signature seal. Without it the file is marked as an unsigned draft.</p>
          </div>
        </div>

        <div className="flex gap-2 border-t border-border/60 bg-muted/30 px-6 py-4">
          <Button variant="outline" className="flex-1" disabled={!!busy || !datesOk} onClick={() => go(false)}>
            {busy === "draft" ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Download className="mr-1.5 h-4 w-4" />}Unsigned draft
          </Button>
          <Button className="flex-1 bg-gold text-black hover:bg-gold/90" disabled={!!busy || !datesOk || pin.length !== 4} onClick={() => go(true)}>
            {busy === "signed" ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <PenLine className="mr-1.5 h-4 w-4" />}Sign &amp; download
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
