import { useEffect, useState } from "react"
import { CheckCircle2, Loader2, ShieldAlert } from "lucide-react"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"

/** Opened from the verification link printed on a signed report (/reports?verify=CODE). */
export function ReportVerifyDialog({ code, onClose }: { code: string; onClose: () => void }) {
  const [result, setResult] = useState<Awaited<ReturnType<typeof api.verifyReport>> | null>(null)
  const [error, setError] = useState("")

  useEffect(() => { api.verifyReport(code).then(setResult).catch((e: Error) => setError(e.message)) }, [code])

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader><DialogTitle>Verify report · {code}</DialogTitle></DialogHeader>
        {!result && !error && <Loader2 className="mx-auto h-6 w-6 animate-spin text-gold" />}
        {error && <p className="flex items-center gap-2 text-sm text-destructive"><ShieldAlert className="h-4 w-4" />{error}</p>}
        {result && (
          <div className="space-y-2 text-sm">
            <p className={`flex items-center gap-2 font-semibold ${result.valid ? "text-emerald-600" : "text-destructive"}`}>
              {result.valid ? <CheckCircle2 className="h-5 w-5" /> : <ShieldAlert className="h-5 w-5" />}
              {result.valid ? "Genuine — the report has not been changed since it was signed." : "Not valid — the report data no longer matches its signature."}
            </p>
            <dl className="grid grid-cols-3 gap-y-1 text-xs">
              <dt className="text-muted-foreground">Report</dt><dd className="col-span-2">{result.report} ({result.format})</dd>
              <dt className="text-muted-foreground">Signed by</dt><dd className="col-span-2">{result.signed_by} · {result.signer_role}</dd>
              <dt className="text-muted-foreground">Signed at</dt><dd className="col-span-2">{result.signed_at}</dd>
              <dt className="text-muted-foreground">Filters</dt><dd className="col-span-2">{Object.entries(result.filters ?? {}).filter(([, v]) => v).map(([k, v]) => `${k.replace("_", " ")}: ${v}`).join(" · ") || "All data"}</dd>
              <dt className="text-muted-foreground">Rows</dt><dd className="col-span-2">{result.rows}</dd>
            </dl>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
