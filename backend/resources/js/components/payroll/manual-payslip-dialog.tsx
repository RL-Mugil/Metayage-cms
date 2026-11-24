import { useEffect, useMemo, useState } from "react"
import { usePage } from "@inertiajs/react"
import { toast } from "sonner"
import { AlertTriangle, FileSignature, Loader2, PenLine } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api, type ManualPayslipPreview } from "@/lib/api-client"

const inr = (v: unknown) => `₹${Number(v || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

/**
 * Generate one employee's payslip for a month that has no payroll run (or a run they
 * are not in): preview from the salary template, adjust LOP / TDS / deductions, then the
 * signatory's PIN signs it and it is published to the employee's portal. It is saved as
 * real payroll (that month's Manual run), so it counts in totals, TDS and Form 16.
 */
export function ManualPayslipDialog({ employee, onClose, onDone }: { employee: { id: number; full_name?: string; employee_code?: string }; onClose: () => void; onDone: () => void }) {
  const { props } = usePage<{ systemSettings?: Record<string, unknown> }>()
  const signatory = String(props.systemSettings?.payroll_signatory_name ?? "")
  const designation = String(props.systemSettings?.payroll_signatory_designation ?? "")
  const [period, setPeriod] = useState(() => new Date().toISOString().slice(0, 7))
  const [lop, setLop] = useState("")
  const [tds, setTds] = useState("0")
  const [health, setHealth] = useState("0")
  const [other, setOther] = useState("0")
  const [pin, setPin] = useState("")
  const [preview, setPreview] = useState<ManualPayslipPreview | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState("")
  const [busy, setBusy] = useState(false)

  const input = useMemo(() => ({
    period,
    lop_days: lop === "" ? null : Number(lop),
    tds: Number(tds || 0), health_insurance: Number(health || 0), other_deductions: Number(other || 0),
  }), [period, lop, tds, health, other])

  useEffect(() => {
    if (!/^\d{4}-\d{2}$/.test(period)) return
    setLoading(true)
    setError("")
    const t = window.setTimeout(() => {
      api.previewManualPayslip(employee.id, input)
        .then((p) => { setPreview(p); if (lop === "" && p.fields.lop_days) setLop(String(p.fields.lop_days)) })
        .catch((e) => { setPreview(null); setError((e as Error).message) })
        .finally(() => setLoading(false))
    }, 300)
    return () => window.clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [employee.id, input])

  const generate = async () => {
    setBusy(true)
    try {
      const res = await api.createManualPayslip(employee.id, { ...input, pin })
      toast.success(res.message)
      onDone()
      onClose()
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setBusy(false)
    }
  }

  const f = preview?.fields ?? {}
  const earnings = preview?.components.filter((c) => c.category === "earning") ?? []
  const customDeductions = preview?.components.filter((c) => c.category === "deduction") ?? []
  const statutory: [string, unknown][] = [
    ["Loss of pay", f.lop_deduction], ["Professional tax", f.professional_tax], ["PF", f.pf_employee], ["ESI", f.esi_employee],
    ["Income tax (TDS)", f.tds], ["Education cess", f.educational_cess], ["Health insurance", f.health_insurance], ["Other / loan", f.other_deductions],
  ]
  const field = "h-9 w-full rounded-lg border border-border bg-background px-2.5 text-sm outline-none focus:border-gold/60 focus:ring-2 focus:ring-gold/15"

  return (
    <Dialog open onOpenChange={(o) => !o && !busy && onClose()}>
      <DialogContent className="max-h-[92vh] max-w-2xl gap-0 overflow-hidden p-0">
        <div className="surface-mesh border-b border-border/60 px-6 pb-4 pt-5">
          <DialogHeader className="space-y-1 text-left">
            <DialogTitle className="flex items-center gap-2"><FileSignature className="h-4.5 w-4.5 text-gold" />Generate payslip — {employee.full_name}</DialogTitle>
            <DialogDescription className="text-xs">Computed from the salary template. Saved as that month's payroll, signed and published to the employee's portal.</DialogDescription>
          </DialogHeader>
        </div>

        <div className="grid max-h-[62vh] gap-5 overflow-y-auto px-6 py-5 sm:grid-cols-[220px_1fr]">
          <div className="space-y-3 text-xs">
            <label className="block">Month<input type="month" value={period} onChange={(e) => { setPeriod(e.target.value); setLop("") }} className={`${field} mt-1`} /></label>
            <label className="block">LOP days<input inputMode="decimal" value={lop} onChange={(e) => setLop(e.target.value.replace(/[^\d.]/g, ""))} placeholder="From approved leave" className={`${field} mt-1`} /></label>
            <label className="block">Income tax (TDS) ₹<input inputMode="decimal" value={tds} onChange={(e) => setTds(e.target.value.replace(/[^\d.]/g, ""))} className={`${field} mt-1`} /></label>
            <label className="block">Health insurance ₹<input inputMode="decimal" value={health} onChange={(e) => setHealth(e.target.value.replace(/[^\d.]/g, ""))} className={`${field} mt-1`} /></label>
            <label className="block">Other deductions ₹<input inputMode="decimal" value={other} onChange={(e) => setOther(e.target.value.replace(/[^\d.]/g, ""))} className={`${field} mt-1`} /></label>
            {preview && preview.loan_deduction > 0 && <p className="text-[10px] text-muted-foreground">Includes loan installment {inr(preview.loan_deduction)} in Other / loan.</p>}
          </div>

          <div className="shadow-3d rounded-xl border border-border bg-background/60 p-4 text-xs">
            {loading && !preview ? (
              <div className="flex h-40 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
            ) : error ? (
              <p className="text-red-600">{error}</p>
            ) : preview && (
              <div className={loading ? "opacity-60" : ""}>
                {preview.existing && (
                  <div className="mb-3 flex gap-2 rounded-lg border border-amber-300/60 bg-amber-500/10 p-2 text-amber-800">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    <span>A payslip for this month already exists in the {preview.existing.run_type} run ({preview.existing.run_status}). Edit that payslip from Pay Runs instead.</span>
                  </div>
                )}
                <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Earnings</div>
                {earnings.map((c) => <div key={c.code} className="flex justify-between py-0.5"><span>{c.name}</span><span className="tabular-nums">{inr(c.amount)}</span></div>)}
                <div className="mt-3 mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Deductions</div>
                {customDeductions.map((c) => <div key={c.code} className="flex justify-between py-0.5"><span>{c.name}</span><span className="tabular-nums">{inr(c.amount)}</span></div>)}
                {statutory.filter(([, v]) => Number(v || 0) > 0).map(([l, v]) => <div key={l} className="flex justify-between py-0.5"><span>{l}</span><span className="tabular-nums">{inr(v)}</span></div>)}
                <div className="mt-2 flex justify-between border-t border-border pt-2 font-semibold"><span>Total deductions</span><span className="tabular-nums">{inr(f.total_deductions)}</span></div>
                {Number(f.reimbursements || 0) > 0 && <div className="flex justify-between py-0.5"><span>Reimbursements</span><span className="tabular-nums">{inr(f.reimbursements)}</span></div>}
                <div className="mt-2 flex justify-between rounded-lg bg-emerald-500/10 px-2 py-1.5 text-sm font-bold text-emerald-700"><span>Net pay</span><span className="tabular-nums">{inr(f.net_pay)}</span></div>
              </div>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-end gap-3 border-t border-border/60 bg-muted/30 px-6 py-4">
          <div className="min-w-0 flex-1 text-[11px]">
            <div className="text-muted-foreground">Signed as</div>
            <div className="font-semibold">{signatory || "Signatory not set — Settings → Payroll"}{designation ? ` · ${designation}` : ""}</div>
          </div>
          <input inputMode="numeric" maxLength={4} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))} placeholder="PIN"
            className="h-9 w-24 rounded-lg border border-border bg-background px-2 text-center tracking-[0.4em]" aria-label="Signatory PIN" />
          <Button variant="outline" disabled={busy} onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={busy || !preview || !!preview.existing || pin.length !== 4} onClick={generate}>
            {busy ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <PenLine className="mr-1.5 h-4 w-4" />}Generate, sign &amp; publish
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
