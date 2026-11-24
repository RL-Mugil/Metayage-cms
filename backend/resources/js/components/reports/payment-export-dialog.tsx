import { useMemo, useState } from "react"
import { toast } from "sonner"
import { Download, FileSpreadsheet, FileText, Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"

/** Signed, letterhead export: report, format, client and one date or a range; PIN signs it. */
/** Pending Payments shortcut to the Payment Collection report (Reports module), pre-filled with the current view. */
export function PaymentExportDialog({ clients, initialClient = "", initialType = "payment-collection", initialFrom = "", initialTo = "", onClose }: { clients: { id: number; name: string; code: string | null }[]; initialClient?: string; initialType?: "payment-collection" | "financial-summary"; initialFrom?: string; initialTo?: string; onClose: () => void }) {
  const [type, setType] = useState<"payment-collection" | "financial-summary">(initialType);
  const [file, setFile] = useState<"xlsx" | "csv" | "pdf">("xlsx");
  const [client, setClient] = useState(() => clients.find((c) => String(c.id) === initialClient)?.code ?? "");
  const [dateMode, setDateMode] = useState<"all" | "one" | "range">(initialFrom || initialTo ? "range" : "all");
  const [date, setDate] = useState("");
  const [from, setFrom] = useState(initialFrom);
  const [to, setTo] = useState(initialTo);
  const [pin, setPin] = useState("");
  const [busy, setBusy] = useState(false);
  const valid = useMemo(() => (dateMode !== "one" || !!date) && (pin === "" || /^\d{4}$/.test(pin)), [dateMode, date, pin]);

  const go = async () => {
    setBusy(true);
    try {
      await api.downloadPaymentReport({ type, file, client_code: client || undefined, pin: pin || undefined,
        date: dateMode === "one" ? date : undefined, from_date: dateMode === "range" ? from || undefined : undefined, to_date: dateMode === "range" ? to || undefined : undefined });
      toast.success(pin ? "Signed report downloaded" : "Report downloaded (unsigned)");
      onClose();
    } catch (e: any) { toast.error(e?.message ?? "Export failed."); } finally { setBusy(false); }
  };
  const fileOpts = [
    { key: "xlsx", label: "Excel", hint: "Tabs, charts, filter dropdowns", icon: FileSpreadsheet },
    { key: "pdf", label: "PDF", hint: "Letterhead data sheet + charts", icon: FileText },
    { key: "csv", label: "CSV", hint: "One consolidated sheet", icon: Download },
  ] as const;

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader><DialogTitle>{type === "payment-collection" ? "Payment Collection report" : "Financial Summary report"}</DialogTitle></DialogHeader>
        <p className="-mt-2 text-[11px] text-muted-foreground">The same report as Reports → {type === "payment-collection" ? "Payment Collection" : "Financial Summary"}; it is also saved under Recent Reports.</p>
        <div className="space-y-3 text-xs">
          <div className="grid grid-cols-2 gap-2">
            {([["payment-collection", "Payment collection", "Pending follow-up, payments, waivers, ageing"], ["financial-summary", "Financial summary", "Every invoice with billed / received / waived / balance"]] as const).map(([k, l, h]) => (
              <button key={k} onClick={() => setType(k)} className={`rounded-xl border p-2.5 text-left ${type === k ? "border-gold bg-gold/10" : "border-border hover:border-gold/40"}`}>
                <div className="font-semibold">{l}</div><div className="text-[10px] text-muted-foreground">{h}</div>
              </button>
            ))}
          </div>
          <div className="grid grid-cols-3 gap-2">
            {fileOpts.map((f) => (
              <button key={f.key} onClick={() => setFile(f.key)} className={`rounded-xl border p-2.5 text-left ${file === f.key ? "border-gold bg-gold/10" : "border-border hover:border-gold/40"}`}>
                <f.icon className="mb-1 h-4 w-4 text-gold" /><div className="font-semibold">{f.label}</div><div className="text-[10px] text-muted-foreground">{f.hint}</div>
              </button>
            ))}
          </div>
          <label className="block">Client
            <select value={client} onChange={(e) => setClient(e.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm">
              <option value="">All clients (consolidated)</option>
              {clients.filter((c) => c.code).map((c) => <option key={c.id} value={c.code!}>{c.code} · {c.name}</option>)}
            </select></label>
          <div>
            <div className="mb-1 flex gap-1">
              {([["all", "All dates"], ["one", "One date"], ["range", "Date range"]] as const).map(([k, l]) => (
                <button key={k} onClick={() => setDateMode(k)} className={`rounded-full px-2.5 py-1 text-[11px] ${dateMode === k ? "bg-foreground text-background" : "border border-border text-muted-foreground"}`}>{l}</button>
              ))}
            </div>
            {dateMode === "one" && <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="h-9 w-full rounded-md border border-border bg-background px-2 text-sm" />}
            {dateMode === "range" && (
              <div className="flex items-center gap-2"><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-9 flex-1 rounded-md border border-border bg-background px-2 text-sm" />
                <span>–</span><input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-9 flex-1 rounded-md border border-border bg-background px-2 text-sm" /></div>
            )}
            <p className="mt-1 text-[10px] text-muted-foreground">Invoices by invoice date, payments by payment date, waivers by approval date. Excel also lets you filter any column by date or client.</p>
          </div>
          <label className="block">Signature PIN (4 digits) — signs the report with your internal digital signature
            <input inputMode="numeric" maxLength={4} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))} placeholder="Leave empty for an unsigned draft"
              className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm tracking-[0.4em]" /></label>
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={busy || !valid} onClick={go}>
            {busy ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <Download className="mr-1 h-4 w-4" />}{pin ? "Sign & download" : "Download"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
