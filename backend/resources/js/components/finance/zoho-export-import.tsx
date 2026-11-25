import { useEffect, useState } from "react"
import { CheckCircle2, FileUp, Loader2, TriangleAlert, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api, type ZohoExportLastImport, type ZohoExportReport, type ZohoExportResult } from "@/lib/api-client"

const KIND_LABEL: Record<ZohoExportReport["kind"], string> = {
  invoices: "Invoices", quotes: "Quotes", payments: "Customer payments", creditnotes: "Credit notes", contacts: "Customers",
}
const KIND_ORDER: ZohoExportReport["kind"][] = ["invoices", "quotes", "payments", "creditnotes", "contacts"]
const ago = (iso: string) => {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return days <= 0 ? "today" : days === 1 ? "yesterday" : `${days} days ago`
}
const inr = (value: number) => `₹${Math.round(value).toLocaleString("en-IN")}`

/**
 * Financial Suite → Indian Patents → "Import Zoho export". Upload Zoho Books' Invoice /
 * Quote / Customer Payment CSV exports, preview what is new, changed or already identical
 * (nothing is written), then import. Same Zoho IDs as the API sync, so nothing duplicates.
 */
export function ZohoExportImport({ onClose, onImported }: { onClose: () => void; onImported: () => void }) {
  const [files, setFiles] = useState<File[]>([])
  const [preview, setPreview] = useState<(ZohoExportResult & { token: string }) | null>(null)
  const [result, setResult] = useState<ZohoExportResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [removeMissing, setRemoveMissing] = useState(false)
  const [lastImport, setLastImport] = useState<ZohoExportLastImport>({})

  useEffect(() => { api.getZohoExportStatus().then((s) => setLastImport(s.last_import ?? {})).catch(() => undefined) }, [])

  async function check() {
    setBusy(true)
    try {
      setPreview(await api.previewZohoExport(files))
      setResult(null)
      setRemoveMissing(false)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not read the files.")
    } finally {
      setBusy(false)
    }
  }

  async function confirm() {
    if (!preview) return
    setBusy(true)
    try {
      const done = await api.importZohoExport(preview.token, removeMissing)
      setResult(done)
      if (done.last_import) setLastImport(done.last_import)
      setPreview(null)
      const added = done.reports.reduce((sum, r) => sum + r.new, 0)
      const updated = done.reports.reduce((sum, r) => sum + r.changed, 0)
      const removed = done.reports.reduce((sum, r) => sum + (r.removed ?? 0), 0)
      toast.success(`Imported: ${added} new, ${updated} updated${removed ? `, ${removed} removed` : ""}.`)
      onImported()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Import failed.")
    } finally {
      setBusy(false)
    }
  }

  const shown = result ?? preview
  const canRemove = preview ? preview.reports.some((r) => r.removable) : false
  const nothingToDo = preview ? preview.reports.every((r) => r.new === 0 && r.changed === 0) && !(removeMissing && canRemove) : false

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="flex max-h-[92vh] w-full max-w-4xl flex-col rounded-xl border border-border bg-background shadow-2xl">
        <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
          <div>
            <h3 className="font-display text-base font-semibold">Import Zoho export</h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              In Zoho Books, export Invoices, Quotes, Customer Payments, Credit Notes and Customers (Contacts) as CSV, for all dates and all
              statuses, then upload them here. Records already in the portal are skipped, records Zoho changed are updated, and new ones are
              added. Customers update only the Zoho side (opening balances); the Clients tab is not changed. Nothing is written until you confirm.
            </p>
            <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
              <span className="text-muted-foreground">Last imported:</span>
              {KIND_ORDER.map((k) => {
                const last = lastImport[k]
                const stale = !last || Date.now() - new Date(last.at).getTime() > 7 * 86400000
                return (
                  <span key={k} className={stale ? "text-amber-600" : "text-muted-foreground"} title={last ? `${last.file} · ${last.documents} records · by ${last.by}` : "Never imported from a file"}>
                    {KIND_LABEL[k]}: {last ? ago(last.at) : "never"}
                  </span>
                )
              })}
            </p>
          </div>
          <button onClick={onClose} className="rounded p-1 hover:bg-muted"><X className="h-4 w-4" /></button>
        </div>

        <div className="flex-1 space-y-4 overflow-auto px-5 py-4">
          {!result && (
            <div className="flex flex-wrap items-center gap-3">
              <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border px-3 py-2 text-xs hover:bg-muted/40">
                <FileUp className="h-4 w-4" />
                {files.length ? files.map((f) => f.name).join(", ") : "Choose up to 5: Invoice / Quote / Customer_Payment / Credit_Note / Contacts .csv"}
                <input type="file" accept=".csv,text/csv" multiple className="hidden"
                  onChange={(e) => { setFiles(Array.from(e.target.files ?? []).slice(0, 5)); setPreview(null) }} />
              </label>
              <Button size="sm" variant="outline" disabled={busy || files.length === 0} onClick={check}>
                {busy && !preview ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}Check files
              </Button>
            </div>
          )}

          {shown && (
            <>
              {result && <p className="flex items-center gap-1.5 text-sm font-medium text-emerald-600"><CheckCircle2 className="h-4 w-4" />Import finished.</p>}
              <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {shown.reports.map((report) => (
                  <div key={report.kind} className="rounded-md border border-border p-3 text-xs">
                    <p className="font-semibold">{KIND_LABEL[report.kind]} <span className="font-normal text-muted-foreground">· {report.file}</span></p>
                    <p className="mt-1 text-muted-foreground">{report.documents} in the file · {report.kind === "payments" ? `${inr(report.totals.total)} received`
                      : report.kind === "contacts" ? `${inr(report.totals.total)} opening balances` : `${inr(report.totals.total)} total`}</p>
                    <div className="mt-2 grid grid-cols-3 gap-1 text-center">
                      <div className="rounded bg-emerald-500/10 py-1"><p className="text-base font-semibold text-emerald-600">{report.new}</p><p className="text-[10px] text-muted-foreground">new</p></div>
                      <div className="rounded bg-amber-500/10 py-1"><p className="text-base font-semibold text-amber-600">{report.changed}</p><p className="text-[10px] text-muted-foreground">changed</p></div>
                      <div className="rounded bg-muted py-1"><p className="text-base font-semibold">{report.identical}</p><p className="text-[10px] text-muted-foreground">already in portal</p></div>
                    </div>
                    {report.changes.length > 0 && (
                      <details className="mt-2">
                        <summary className="cursor-pointer text-muted-foreground">What changed ({report.changes.length})</summary>
                        <ul className="mt-1 max-h-40 space-y-0.5 overflow-auto">
                          {report.changes.map((c) => <li key={c.number}><span className="font-mono">{c.number}</span>: {c.what.join(", ")}</li>)}
                        </ul>
                      </details>
                    )}
                    {report.kind === "contacts" && (report.unlinked ?? 0) > 0 && (
                      <p className="mt-2 text-[11px] text-muted-foreground">{report.unlinked} customer(s) have no portal client linked (shown only; the Clients tab is not changed).</p>
                    )}
                    {(report.missing ?? 0) > 0 && (
                      <details className="mt-2">
                        <summary className={`cursor-pointer ${report.removed ? "text-red-600" : "text-amber-600"}`}>
                          {report.removed ? `${report.removed} removed (not in Zoho any more)` : `${report.missing} in the portal but not in this file`}
                        </summary>
                        <p className="mt-1 text-[11px] text-muted-foreground">
                          {report.removable ? "If this is a full export, these were deleted in Zoho and can be removed." : "Too many to be deletions: this looks like a partial export, so nothing will be removed."}
                        </p>
                        <ul className="mt-1 max-h-32 space-y-0.5 overflow-auto font-mono text-[11px]">{(report.missing_sample ?? []).map((n) => <li key={n}>{n}</li>)}</ul>
                      </details>
                    )}
                    {report.cross_check.length > 0 && (
                      <details className="mt-2">
                        <summary className="flex cursor-pointer items-center gap-1 text-amber-600"><TriangleAlert className="h-3 w-3" />Fee split differs from Zoho's fields ({report.cross_check.length})</summary>
                        <ul className="mt-1 max-h-40 space-y-0.5 overflow-auto text-muted-foreground">
                          {report.cross_check.map((c) => (
                            <li key={c.number}><span className="font-mono">{c.number}</span>: office {inr(c.zoho_office_fee)} in Zoho vs {inr(c.portal_office_fee)}; service {inr(c.zoho_service_fee)} vs {inr(c.portal_service_fee)}</li>
                          ))}
                        </ul>
                      </details>
                    )}
                  </div>
                ))}
              </div>

              {(shown.reconciliation.invoices || shown.reconciliation.quotes) && (
                <div className="rounded-md border border-border p-3 text-xs">
                  <p className="mb-2 font-semibold">Portal vs Zoho {result ? "(after import)" : "(right now)"}</p>
                  <table className="w-full">
                    <thead className="text-[10px] uppercase tracking-wider text-muted-foreground">
                      <tr><th className="py-1 text-left"></th><th className="py-1 text-right">Zoho export</th><th className="py-1 text-right">Portal</th><th className="py-1 text-right">Difference</th></tr>
                    </thead>
                    <tbody>
                      {shown.reconciliation.invoices && ([
                        ["Invoices", "count"], ["Billed", "billed"], ["Collected", "collected"], ["Outstanding", "outstanding"],
                      ] as const).map(([label, key]) => {
                        const z = shown.reconciliation.invoices!.export[key]
                        const p = shown.reconciliation.invoices!.portal[key]
                        const diff = Math.round((p - z) * 100) / 100
                        return (
                          <tr key={key} className="border-t border-border">
                            <td className="py-1">{label}</td>
                            <td className="py-1 text-right font-mono">{key === "count" ? z : inr(z)}</td>
                            <td className="py-1 text-right font-mono">{key === "count" ? p : inr(p)}</td>
                            <td className={`py-1 text-right font-mono ${diff === 0 ? "text-emerald-600" : "text-amber-600"}`}>{diff === 0 ? "✓ 0" : key === "count" ? diff : inr(diff)}</td>
                          </tr>
                        )
                      })}
                      {shown.reconciliation.quotes && (
                        <tr className="border-t border-border">
                          <td className="py-1">Quotes</td>
                          <td className="py-1 text-right font-mono">{shown.reconciliation.quotes.export.count}</td>
                          <td className="py-1 text-right font-mono">{shown.reconciliation.quotes.portal.count}</td>
                          <td className={`py-1 text-right font-mono ${shown.reconciliation.quotes.portal.count === shown.reconciliation.quotes.export.count ? "text-emerald-600" : "text-amber-600"}`}>
                            {shown.reconciliation.quotes.portal.count === shown.reconciliation.quotes.export.count ? "✓ 0" : shown.reconciliation.quotes.portal.count - shown.reconciliation.quotes.export.count}
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                  {!result && <p className="mt-2 text-[11px] text-muted-foreground">Differences here are what the import will close.</p>}
                </div>
              )}
            </>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3">
          {preview && canRemove && (
            <label className="mr-auto flex items-center gap-1.5 text-xs">
              <input type="checkbox" checked={removeMissing} onChange={(e) => setRemoveMissing(e.target.checked)} />
              Full export (all dates, all statuses): remove records Zoho no longer has
            </label>
          )}
          <Button size="sm" variant="outline" onClick={onClose} disabled={busy}>{result ? "Close" : "Cancel"}</Button>
          {preview && (
            <Button size="sm" disabled={busy || nothingToDo} onClick={confirm} className="bg-gold text-black hover:bg-gold/90">
              {busy ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
              {nothingToDo ? "Portal already up to date" : "Import"}
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
