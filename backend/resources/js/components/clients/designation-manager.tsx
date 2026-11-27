import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { Check, Loader2, Pencil, Plus, ShieldCheck, Trash2, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"

export interface Designation { id: number; name: string; permissions: string[]; ready_made: boolean; is_inventor: boolean; users: number }
interface DesignationData { permissions: { key: string; label: string }[]; designations: Designation[] }

/** Whose designations: a client's (firm side) or the signed-in client's own ("mine"). */
export type DesignationScope = { clientId: number } | "mine"

const base = (scope: DesignationScope) => (scope === "mine" ? "/my-portal" : `/portal/clients/${scope.clientId}`)
export const designationsKey = (scope: DesignationScope) => ["client-designations", scope === "mine" ? "mine" : scope.clientId]

export function useDesignations(scope: DesignationScope) {
  return useQuery<DesignationData>({ queryKey: designationsKey(scope), queryFn: () => api.request<DesignationData>(`${base(scope)}/designations`) })
}

/** Give one login another designation (its access follows the boxes). */
export function assignDesignation(scope: DesignationScope, userId: number, designationId: number) {
  return api.request(`${base(scope)}/users/${userId}`, { method: "PUT", body: JSON.stringify({ designation_id: designationId }) })
}

/** A designation picker: ready-made ones, then the client's own. */
export function DesignationSelect({ scope, value, onChange, inventor = false, className }: {
  scope: DesignationScope; value: number | null | undefined; onChange: (id: number) => void; inventor?: boolean; className?: string
}) {
  const { data } = useDesignations(scope)
  const list = (data?.designations ?? []).filter((d) => d.is_inventor === inventor)
  return (
    <select value={value ?? ""} onChange={(e) => onChange(Number(e.target.value))}
      className={className ?? "h-7 rounded border border-border bg-background px-1.5 text-xs"}>
      {value == null && <option value="">Choose…</option>}
      {list.filter((d) => d.ready_made).length > 0 && <optgroup label="Ready-made">{list.filter((d) => d.ready_made).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</optgroup>}
      {list.filter((d) => !d.ready_made).length > 0 && <optgroup label="Your designations">{list.filter((d) => !d.ready_made).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</optgroup>}
    </select>
  )
}

/** The access a designation gives, as small chips. */
export function AccessChips({ permissions, labels }: { permissions: string[]; labels?: Record<string, string> }) {
  return (
    <span className="flex flex-wrap gap-1">
      {permissions.map((p) => <span key={p} className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] text-emerald-700 dark:text-emerald-400">{labels?.[p] ?? p}</span>)}
    </span>
  )
}

/**
 * Designations and their access checkboxes: the ready-made ones (Manager, CFO, CTO, ...) and the
 * client's own, which can be created, edited and deleted. Used on the client panel's Users tab,
 * the Client Portal page and the client's own Portal Users page.
 */
export function DesignationManager({ scope, canManage }: { scope: DesignationScope; canManage: boolean }) {
  const queryClient = useQueryClient()
  const { data, isLoading } = useDesignations(scope)
  const [editing, setEditing] = useState<Designation | "new" | null>(null)
  const labels = Object.fromEntries((data?.permissions ?? []).map((p) => [p.key, p.label]))
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: designationsKey(scope) })
    void queryClient.invalidateQueries({ queryKey: ["client-portal-users"] })
    void queryClient.invalidateQueries({ queryKey: ["my-portal-users"] })
  }
  const remove = useMutation({
    mutationFn: (d: Designation) => api.request(`${base(scope)}/designations/${d.id}`, { method: "DELETE" }),
    onSuccess: () => { toast.success("Designation deleted"); refresh() },
    onError: (e: Error) => toast.error(e.message),
  })

  if (isLoading || !data) return <div className="flex justify-center py-6"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-xs font-semibold"><ShieldCheck className="h-4 w-4 text-gold" />Designations and access</p>
        {canManage && editing === null && <Button size="sm" className="h-7 text-xs" onClick={() => setEditing("new")}><Plus className="mr-1 h-3.5 w-3.5" />New designation</Button>}
      </div>
      {editing !== null && (
        <DesignationEditor scope={scope} permissions={data.permissions} designation={editing === "new" ? null : editing}
          onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />
      )}
      <div className="divide-y divide-border rounded-xl border border-border">
        {data.designations.map((d) => (
          <div key={d.id} className="flex flex-wrap items-start gap-3 p-2.5 text-xs">
            <div className="w-40 shrink-0">
              <p className="font-medium">{d.name}</p>
              <p className="text-[10px] text-muted-foreground">{d.ready_made ? "Ready-made" : "Your designation"} · {d.users} user{d.users === 1 ? "" : "s"}</p>
            </div>
            <div className="min-w-0 flex-1"><AccessChips permissions={d.permissions} labels={labels} /></div>
            {canManage && !d.ready_made && (
              <div className="flex shrink-0 gap-1">
                <Button size="icon" variant="ghost" className="h-7 w-7" title="Edit" onClick={() => setEditing(d)}><Pencil className="h-3.5 w-3.5" /></Button>
                <Button size="icon" variant="ghost" className="h-7 w-7" title={d.users ? "Move its users first" : "Delete"} disabled={d.users > 0 || remove.isPending}
                  onClick={() => { if (window.confirm(`Delete the designation "${d.name}"?`)) remove.mutate(d) }}><Trash2 className="h-3.5 w-3.5" /></Button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  )
}

function DesignationEditor({ scope, permissions, designation, onCancel, onSaved }: {
  scope: DesignationScope; permissions: { key: string; label: string }[]; designation: Designation | null; onCancel: () => void; onSaved: () => void
}) {
  const [name, setName] = useState(designation?.name ?? "")
  const [picked, setPicked] = useState<string[]>(designation?.permissions ?? [])
  const save = useMutation({
    mutationFn: () => api.request(designation ? `${base(scope)}/designations/${designation.id}` : `${base(scope)}/designations`,
      { method: designation ? "PUT" : "POST", body: JSON.stringify({ name: name.trim(), permissions: picked }) }),
    onSuccess: () => { toast.success(designation ? "Designation saved; its users' access changed" : "Designation created"); onSaved() },
    onError: (e: Error) => toast.error(e.message),
  })
  const toggle = (key: string) => setPicked((p) => (p.includes(key) ? p.filter((x) => x !== key) : [...p, key]))
  return (
    <div className="card-3d space-y-3 rounded-xl border border-gold/40 bg-background/80 p-3">
      <label className="block text-[10px] text-muted-foreground">Designation name
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Legal Head" maxLength={80}
          className="mt-0.5 h-8 w-full max-w-xs rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
      </label>
      <div>
        <p className="mb-1 text-[10px] text-muted-foreground">Access (tick what people with this designation may use)</p>
        <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
          {permissions.map((p) => (
            <label key={p.key} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-2 py-1.5 text-xs ${picked.includes(p.key) ? "border-gold bg-gold/10" : "border-border hover:bg-muted"}`}>
              <input type="checkbox" checked={picked.includes(p.key)} onChange={() => toggle(p.key)} className="accent-[var(--gold)]" />{p.label}
            </label>
          ))}
        </div>
      </div>
      <div className="flex gap-2">
        <Button size="sm" className="h-7 text-xs" disabled={save.isPending || !name.trim() || picked.length === 0} onClick={() => save.mutate()}>
          {save.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : <><Check className="mr-1 h-3.5 w-3.5" />Save</>}
        </Button>
        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onCancel}><X className="mr-1 h-3.5 w-3.5" />Cancel</Button>
      </div>
    </div>
  )
}
