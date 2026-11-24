import { useEffect, useMemo, useState } from "react"
import { usePage } from "@inertiajs/react"
import { toast } from "sonner"
import { Loader2, Plus, Search, ShieldCheck, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { api } from "@/lib/api-client"

interface StaffLogin { id: number; name: string; role?: string; email?: string }

const ALWAYS = ["super_admin", "partner", "director", "finance", "hr"]
const ROLE_LABEL: Record<string, string> = {
  super_admin: "System Admin", partner: "Director", director: "Director", manager: "Patent Attorney", finance: "Accountant",
  hr: "HR", associate: "Patent Analyst", paralegal: "Paralegal", galvanizer: "Galvanizer",
}

/**
 * Settings → Finance → Financial access (System Admin): staff who see every invoice,
 * payment and finance report, and may act on them. Everyone else sees finance only for
 * the cases where they are Client Manager or Secondary CM.
 */
export function FinanceAccessCard() {
  const { props } = usePage<{ systemSettings?: { finance_access_user_ids?: number[] } }>()
  const [ids, setIds] = useState<number[]>(props.systemSettings?.finance_access_user_ids ?? [])
  const [staff, setStaff] = useState<StaffLogin[]>([])
  const [query, setQuery] = useState("")
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.getStaffPickerUsers().then((rows) => setStaff(rows.map((u) => ({ id: u.id, name: u.name, role: u.role, email: u.email })))).catch(() => {})
  }, [])

  const listed = staff.filter((u) => ids.includes(u.id))
  const candidates = useMemo(() => {
    const q = query.trim().toLowerCase()
    return staff.filter((u) => !ids.includes(u.id) && !ALWAYS.includes(u.role ?? "")
      && (!q || u.name.toLowerCase().includes(q) || (u.email ?? "").toLowerCase().includes(q))).slice(0, 8)
  }, [staff, ids, query])

  const save = async (next: number[]) => {
    setSaving(true)
    try {
      const res = await api.updateFinanceAccess(next)
      setIds(res.user_ids)
      toast.success("Financial access updated")
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-gold" />Financial access</CardTitle>
        <CardDescription>
          People listed here see every invoice, payment and finance report, and can mark invoices paid or request waivers.
          Director, Accountant, HR and System Admin always do. Everyone else sees finance only for cases where they are Client Manager or Secondary CM.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-wrap gap-2">
          {listed.length === 0 && <span className="text-xs text-muted-foreground">Nobody added yet.</span>}
          {listed.map((u) => (
            <span key={u.id} className="inline-flex items-center gap-1.5 rounded-full border border-gold/40 bg-gold/10 px-2.5 py-1 text-xs">
              {u.name}<span className="text-muted-foreground">· {ROLE_LABEL[u.role ?? ""] ?? u.role}</span>
              <button type="button" disabled={saving} onClick={() => save(ids.filter((x) => x !== u.id))} aria-label={`Remove ${u.name}`} className="rounded-full p-0.5 hover:bg-gold/20">
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
        </div>
        <div className="relative max-w-md">
          <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Add an employee — search name or email"
            className="h-9 w-full rounded-lg border border-border bg-background pl-8 pr-3 text-sm outline-none focus:border-gold/60" />
          {query.trim() && (
            <div className="absolute z-20 mt-1 w-full rounded-lg border border-border bg-background p-1 shadow-xl">
              {candidates.length === 0 && <div className="px-2 py-1.5 text-xs text-muted-foreground">No match (Director, Accountant, HR and System Admin already see all finance).</div>}
              {candidates.map((u) => (
                <button key={u.id} type="button" disabled={saving} onClick={() => { setQuery(""); save([...ids, u.id]) }}
                  className="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted">
                  <span>{u.name} <span className="text-xs text-muted-foreground">· {ROLE_LABEL[u.role ?? ""] ?? u.role}</span></span>
                  {saving ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Plus className="h-3.5 w-3.5 text-gold" />}
                </button>
              ))}
            </div>
          )}
        </div>
        <div className="flex justify-end"><Button variant="outline" size="sm" disabled={saving} onClick={() => save(ids)}>Save</Button></div>
      </CardContent>
    </Card>
  )
}
