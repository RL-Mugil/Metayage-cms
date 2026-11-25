import { useEffect, useMemo, useState } from "react"
import { usePage } from "@inertiajs/react"
import { toast } from "sonner"
import { AlertTriangle, Check, ChevronsUpDown, KeyRound, Loader2, ShieldCheck, X } from "lucide-react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Switch } from "@/components/ui/switch"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { api, type OpenAccess } from "@/lib/api-client"

interface StaffLogin { id: number; name: string; role?: string; email?: string }

/** Always see all finance, so never offered for the Financial access list. */
const ALWAYS_FINANCE = ["super_admin", "partner", "director", "finance", "hr"]
const ROLE_LABEL: Record<string, string> = {
  super_admin: "System Admin", partner: "Director", director: "Director", manager: "Patent Attorney", finance: "Accountant",
  hr: "HR", associate: "Patent Analyst / Engineer", paralegal: "Paralegal", galvanizer: "Galvanizer",
}

const SWITCHES: { key: "clients" | "cases" | "portal"; label: string; help: string }[] = [
  { key: "clients", label: "Clients", help: "Patent Analysts / Engineers and Patent Attorneys see and can edit every client, so they can claim theirs by setting themselves as Client Manager." },
  { key: "cases",   label: "Cases",   help: "They see and can edit every case (and its tasks, documents, compliance and dashboards), e.g. to set themselves as PCM or SCM." },
  { key: "portal",  label: "Client Portal", help: "They see and manage every client's portal logins." },
]

/**
 * Settings → System → Open access (System Admin). Normally Patent Analysts / Engineers and
 * Patent Attorneys see only their own clients and cases (Client Manager, PCM or SCM). These
 * switches open everything while needed; finance is never opened here — that is the
 * Financial access list below.
 */
export function OpenAccessCard() {
  const { props } = usePage<{ systemSettings?: { open_access?: OpenAccess; finance_access_user_ids?: number[] } }>()
  const [open, setOpen] = useState<OpenAccess>(props.systemSettings?.open_access ?? { clients: false, cases: false, portal: false })
  const [savingSwitch, setSavingSwitch] = useState(false)

  const [ids, setIds] = useState<number[]>(props.systemSettings?.finance_access_user_ids ?? [])
  const [staff, setStaff] = useState<StaffLogin[]>([])
  const [pickerOpen, setPickerOpen] = useState(false)
  const [savingList, setSavingList] = useState(false)

  useEffect(() => {
    api.getStaffPickerUsers().then((rows) => setStaff(rows.map((u) => ({ id: u.id, name: u.name, role: u.role, email: u.email })))).catch(() => {})
  }, [])

  const anyOn = open.clients || open.cases || open.portal

  const toggle = async (key: "clients" | "cases" | "portal", value: boolean) => {
    const next = { clients: open.clients, cases: open.cases, portal: open.portal, [key]: value }
    setSavingSwitch(true)
    try {
      setOpen(await api.updateOpenAccess(next))
      toast.success(`${SWITCHES.find((s) => s.key === key)?.label} access ${value ? "opened to all staff" : "back to own clients and cases"}`)
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setSavingSwitch(false)
    }
  }

  const listed = staff.filter((u) => ids.includes(u.id))
  const candidates = useMemo(() => staff.filter((u) => !ALWAYS_FINANCE.includes(u.role ?? "")), [staff])

  const saveList = async (next: number[]) => {
    setSavingList(true)
    try {
      const res = await api.updateFinanceAccess(next)
      setIds(res.user_ids)
      toast.success("Financial access updated")
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setSavingList(false)
    }
  }

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display flex items-center gap-2"><KeyRound className="h-4 w-4 text-gold" />Open access</CardTitle>
        <CardDescription>
          Patent Analysts / Engineers and Patent Attorneys normally see only their own clients and cases (where they are Client Manager, PCM or SCM).
          Turn a switch on to open everything while needed — for example so they can claim their clients — and turn it off afterwards.
          System Admin, Director, Accountant, HR and Paralegal always see all clients and cases.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {anyOn && (
          <div className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Open access is on — turn it off when claiming is done.
          </div>
        )}

        <div className="divide-y divide-border rounded-lg border border-border">
          {SWITCHES.map((s) => (
            <label key={s.key} className="flex items-start justify-between gap-4 px-3 py-3">
              <span>
                <span className="block text-sm font-medium">{s.label}</span>
                <span className="block text-xs text-muted-foreground">{s.help}</span>
              </span>
              <Switch checked={open[s.key]} disabled={savingSwitch} onCheckedChange={(v) => toggle(s.key, v)} aria-label={`Open ${s.label} to all staff`} />
            </label>
          ))}
        </div>
        <p className="text-xs text-muted-foreground">
          These switches never open finance or widen Bulk Operations.
          {open.updated_by && open.updated_at && <> Last changed by {open.updated_by} on {new Date(open.updated_at).toLocaleString()}.</>}
        </p>

        <div className="space-y-2 border-t border-border pt-4">
          <div className="flex items-center gap-2 text-sm font-medium"><ShieldCheck className="h-4 w-4 text-gold" />Financial access</div>
          <p className="text-xs text-muted-foreground">
            People listed here see every invoice, payment and finance report, and can mark invoices paid or request waivers.
            Director, Accountant, HR and System Admin always do. Everyone else sees finance only for cases where they are Client Manager or Secondary CM.
          </p>

          <Popover open={pickerOpen} onOpenChange={setPickerOpen}>
            <PopoverTrigger asChild>
              <button type="button" disabled={savingList} role="combobox" aria-expanded={pickerOpen}
                className="flex h-9 w-full max-w-md items-center justify-between rounded-lg border border-border bg-background px-3 text-sm text-muted-foreground hover:border-gold/60">
                Add an employee…
                {savingList ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ChevronsUpDown className="h-3.5 w-3.5" />}
              </button>
            </PopoverTrigger>
            <PopoverContent className="w-[var(--radix-popover-trigger-width)] min-w-[280px] p-0" align="start">
              <Command>
                <CommandInput placeholder="Search employee name or email" />
                <CommandList>
                  <CommandEmpty>No employee found.</CommandEmpty>
                  <CommandGroup>
                    {candidates.map((u) => {
                      const on = ids.includes(u.id)
                      return (
                        <CommandItem key={u.id} value={`${u.name} ${u.email ?? ""}`}
                          onSelect={() => saveList(on ? ids.filter((x) => x !== u.id) : [...ids, u.id])}>
                          <Check className={`mr-2 h-3.5 w-3.5 ${on ? "opacity-100 text-gold" : "opacity-0"}`} />
                          <span className="flex-1">{u.name}</span>
                          <span className="text-xs text-muted-foreground">{ROLE_LABEL[u.role ?? ""] ?? u.role}</span>
                        </CommandItem>
                      )
                    })}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>

          <div className="flex flex-wrap gap-2">
            {listed.length === 0 && <span className="text-xs text-muted-foreground">Nobody added yet.</span>}
            {listed.map((u) => (
              <span key={u.id} className="inline-flex items-center gap-1.5 rounded-full border border-gold/40 bg-gold/10 px-2.5 py-1 text-xs">
                {u.name}<span className="text-muted-foreground">· {ROLE_LABEL[u.role ?? ""] ?? u.role}</span>
                <button type="button" disabled={savingList} onClick={() => saveList(ids.filter((x) => x !== u.id))} aria-label={`Remove ${u.name}`} className="rounded-full p-0.5 hover:bg-gold/20">
                  <X className="h-3 w-3" />
                </button>
              </span>
            ))}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
