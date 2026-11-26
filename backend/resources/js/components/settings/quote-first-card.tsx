import { useEffect, useMemo, useState } from "react"
import { usePage } from "@inertiajs/react"
import { toast } from "sonner"
import { Check, ChevronsUpDown, FileText, Loader2, X } from "lucide-react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { api } from "@/lib/api-client"

interface QuoteFirst { enabled: boolean; exempt_client_ids: number[]; enabled_at: string | null }
interface ClientOption { id: number; client_code: string; legal_name: string }

/**
 * Settings → System → Quote-first billing (System Admin). Quotes (proformas) are the receivable;
 * a quote becomes a tax invoice only once the client has paid, so GST is never payable before the
 * money is in. Exempt clients (preferred) may be invoiced before payment.
 */
export function QuoteFirstCard() {
  const { props } = usePage<{ systemSettings?: { quote_first?: QuoteFirst } }>()
  const [qf, setQf] = useState<QuoteFirst>(props.systemSettings?.quote_first ?? { enabled: false, exempt_client_ids: [], enabled_at: null })
  const [clients, setClients] = useState<ClientOption[]>([])
  const [pickerOpen, setPickerOpen] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.request<any>("/clients?per_page=2000").then((r) => setClients((r?.data ?? r ?? []).map((c: any) => ({ id: c.id, client_code: c.client_code, legal_name: c.legal_name || c.company_name }))))
      .catch(() => {})
  }, [])
  const byId = useMemo(() => new Map(clients.map((c) => [c.id, c])), [clients])

  const save = async (next: QuoteFirst, ok: string) => {
    setSaving(true)
    try {
      setQf(await api.request<QuoteFirst>("/settings/quote-first", { method: "PUT", body: JSON.stringify({ enabled: next.enabled, exempt_client_ids: next.exempt_client_ids }) }))
      toast.success(ok)
    } catch (e) { toast.error((e as Error).message) } finally { setSaving(false) }
  }
  const toggleClient = (id: number) => {
    const list = qf.exempt_client_ids.includes(id) ? qf.exempt_client_ids.filter((x) => x !== id) : [...qf.exempt_client_ids, id]
    save({ ...qf, exempt_client_ids: list }, "Exempt clients saved")
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base"><FileText className="h-4 w-4 text-gold" />Quote-first billing</CardTitle>
        <CardDescription>
          Quotes (proformas) are the receivable; a quote becomes a tax invoice only once the client has paid it (a TDS shortfall of up to 10% of professional
          fees counts as paid), so GST is never payable before the money is in. Raising an invoice before payment asks for a reason, which is logged.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        <label className="flex items-center justify-between gap-3">
          <span>
            <span className="font-medium">Quotes first, invoice after payment</span>
            <span className="block text-xs text-muted-foreground">On: unpaid quotes count as money due (Pending Payments, dashboards, reminders, client statements).
              {qf.enabled && qf.enabled_at ? ` On since ${new Date(qf.enabled_at).toLocaleDateString("en-IN")}.` : ""}</span>
          </span>
          <Switch checked={qf.enabled} disabled={saving} onCheckedChange={(v) => save({ ...qf, enabled: v }, v ? "Quote-first billing on" : "Quote-first billing off")} />
        </label>
        <div>
          <p className="mb-1 font-medium">Exempt clients <span className="text-xs font-normal text-muted-foreground">(may be invoiced before they pay)</span></p>
          <div className="mb-2 flex flex-wrap gap-1.5">
            {qf.exempt_client_ids.length === 0 && <span className="text-xs text-muted-foreground">None.</span>}
            {qf.exempt_client_ids.map((id) => (
              <span key={id} className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-xs">
                <span className="font-mono">{byId.get(id)?.client_code ?? `#${id}`}</span> {byId.get(id)?.legal_name ?? ""}
                <button disabled={saving} onClick={() => toggleClient(id)} className="text-muted-foreground hover:text-red-600"><X className="h-3 w-3" /></button>
              </span>
            ))}
          </div>
          <Popover open={pickerOpen} onOpenChange={setPickerOpen}>
            <PopoverTrigger asChild>
              <button className="inline-flex h-8 w-full max-w-md items-center justify-between rounded-md border border-border bg-background px-3 text-xs text-muted-foreground">
                {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "Add or remove an exempt client…"}<ChevronsUpDown className="h-3.5 w-3.5" />
              </button>
            </PopoverTrigger>
            <PopoverContent className="w-[360px] p-0" align="start">
              <Command>
                <CommandInput placeholder="Search code or name…" />
                <CommandList>
                  <CommandEmpty>No client found.</CommandEmpty>
                  <CommandGroup>
                    {clients.map((c) => (
                      <CommandItem key={c.id} value={`${c.client_code} ${c.legal_name}`} onSelect={() => toggleClient(c.id)}>
                        <Check className={`mr-2 h-3.5 w-3.5 ${qf.exempt_client_ids.includes(c.id) ? "opacity-100" : "opacity-0"}`} />
                        <span className="font-mono text-xs">{c.client_code}</span><span className="ml-2 truncate text-xs">{c.legal_name}</span>
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </div>
      </CardContent>
    </Card>
  )
}
