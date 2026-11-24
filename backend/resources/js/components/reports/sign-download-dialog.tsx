import { useState } from "react"
import { toast } from "sonner"
import { Download, Loader2, PenLine } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"

/**
 * Before a Payment Collection / Financial Summary file downloads: sign it with your 4-digit
 * signature PIN, or download an unsigned draft. An edited preview cannot be signed — it can
 * only go out as an unsigned edited copy.
 */
export function SignDownloadDialog({ file, edited, onDownload, onEditedCopy, onClose }: {
  file: "xlsx" | "csv" | "pdf"
  edited: boolean
  onDownload: (pin?: string) => Promise<void>
  onEditedCopy: () => void
  onClose: () => void
}) {
  const [pin, setPin] = useState("")
  const [busy, setBusy] = useState(false)
  const label = file === "xlsx" ? "Excel" : file.toUpperCase()

  const go = async (withPin: boolean) => {
    setBusy(true)
    try {
      await onDownload(withPin ? pin : undefined)
      toast.success(withPin ? `Signed ${label} downloaded` : `${label} downloaded (unsigned draft)`)
      onClose()
    } catch (e) { toast.error((e as Error).message) } finally { setBusy(false) }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-sm">
        <DialogHeader><DialogTitle className="flex items-center gap-2"><PenLine className="h-4 w-4 text-gold" /> Download {label}</DialogTitle></DialogHeader>
        {edited ? (
          <div className="space-y-3 text-sm">
            <p className="rounded-md bg-amber-500/10 px-3 py-2 text-xs text-amber-700">You edited the preview. An edited copy can't be signed — the signature must match the register data.</p>
            <Button className="w-full" variant="outline" onClick={() => { onEditedCopy(); onClose() }}>Download unsigned edited copy</Button>
            <Button className="w-full bg-gold text-black hover:bg-gold/90" disabled={busy} onClick={() => go(false)}>
              {busy && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}Download original (unedited) instead
            </Button>
          </div>
        ) : (
          <div className="space-y-3">
            <label className="block text-xs text-muted-foreground">Signature PIN (4 digits) — adds your small digital signature seal
              <input autoFocus inputMode="numeric" maxLength={4} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
                onKeyDown={(e) => { if (e.key === "Enter" && pin.length === 4) go(true) }}
                className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-center text-lg tracking-[0.6em]" />
            </label>
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1" disabled={busy} onClick={() => go(false)}><Download className="mr-1 h-4 w-4" />Unsigned draft</Button>
              <Button className="flex-1 bg-gold text-black hover:bg-gold/90" disabled={busy || pin.length !== 4} onClick={() => go(true)}>
                {busy ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <PenLine className="mr-1 h-4 w-4" />}Sign & download
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
