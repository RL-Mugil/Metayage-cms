import { useState } from "react"
import { toast } from "sonner"
import { Loader2, PenLine } from "lucide-react"
import { usePage } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"

/**
 * Asks for the payroll signatory's 4-digit signature PIN before payslips are signed and
 * published (a whole run, one payslip, or a manual payslip). The signatory's name and
 * designation come from Settings → Payroll and are printed beside the seal.
 */
export function SignatoryPinDialog({ title, description, confirmLabel = "Sign & publish", onConfirm, onClose }: {
  title: string
  description: string
  confirmLabel?: string
  onConfirm: (pin: string) => Promise<string | void>
  onClose: () => void
}) {
  const { props } = usePage<{ systemSettings?: Record<string, unknown> }>()
  const name = String(props.systemSettings?.payroll_signatory_name ?? "")
  const designation = String(props.systemSettings?.payroll_signatory_designation ?? "")
  const [pin, setPin] = useState("")
  const [busy, setBusy] = useState(false)

  const go = async () => {
    setBusy(true)
    try {
      const message = await onConfirm(pin)
      toast.success(message || "Signed and published.")
      onClose()
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><PenLine className="h-4 w-4 text-gold" />{title}</DialogTitle>
          <DialogDescription className="text-xs">{description}</DialogDescription>
        </DialogHeader>
        <div className="rounded-xl border border-gold/30 bg-gold/5 px-3 py-2 text-xs">
          <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Signed as</div>
          <div className="font-semibold">{name || "Signatory not set — Settings → Payroll"}</div>
          {designation && <div className="text-muted-foreground">{designation}</div>}
        </div>
        <label className="block text-xs text-muted-foreground">Signatory's signature PIN
          <input autoFocus inputMode="numeric" maxLength={4} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
            onKeyDown={(e) => { if (e.key === "Enter" && pin.length === 4 && !busy) go() }}
            placeholder="••••" className="mt-1 h-10 w-full rounded-lg border border-border bg-background px-3 text-center text-lg tracking-[0.6em]" />
        </label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" disabled={busy} onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={busy || pin.length !== 4} onClick={go}>
            {busy ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <PenLine className="mr-1.5 h-4 w-4" />}{confirmLabel}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
