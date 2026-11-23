import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { CheckCircle2, KeyRound, Loader2, Pencil, Plus, Trash2, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"
import { InventorForm, place } from "@/components/inventors/inventor-picker"
import type { InventorRecord } from "@/types/ipo-forms"

/**
 * The client panel's Inventors tab: add, edit and delete the client's inventors with every
 * IPO Form 1 particular. The case form picks from this list, and the inventor's email is
 * also their portal login.
 */
export function ClientInventors({ clientId, canGrantPortal }: { clientId: number; canGrantPortal: boolean }) {
  const queryClient = useQueryClient()
  const key = ["client-inventors", clientId]
  const { data: inventors = [], isLoading, error } = useQuery<InventorRecord[]>({
    queryKey: key,
    queryFn: () => api.searchInventors({ clientId }),
  })
  // null: nothing open; "new": adding; a number: editing that inventor.
  const [editing, setEditing] = useState<"new" | number | null>(null)
  const [granting, setGranting] = useState<number | null>(null)
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    void queryClient.invalidateQueries({ queryKey: ["inventor-options"] })
    // The Users tab lists inventor logins too.
    void queryClient.invalidateQueries({ queryKey: ["client-portal-users", clientId] })
  }
  const remove = useMutation({
    mutationFn: (inventor: InventorRecord) => api.deleteInventor(inventor.id),
    onSuccess: () => { toast.success("Inventor deleted."); refresh() },
    onError: (failure: Error) => toast.error(failure.message),
  })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /></div>
  if (error) return <p className="py-10 text-center text-sm text-destructive">{(error as Error).message}</p>

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground">Inventors picked on this client's cases and printed on Form 1. Pick one of the client's users, or add a new person with their own inventor login.</p>
        {editing !== "new" && (
          <Button size="sm" onClick={() => setEditing("new")}><Plus className="mr-1 h-3.5 w-3.5" />Add inventor</Button>
        )}
      </div>

      {editing === "new" && (
        <InventorForm initial={{ client_id: clientId, full_name: "", nationality: "Indian", country: "India", country_of_residence: "India" }}
          link={{ clientId, canCreateLogin: canGrantPortal }}
          onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />
      )}

      {inventors.length === 0 && editing !== "new" ? (
        <p className="py-10 text-center text-sm text-muted-foreground">No inventors for this client yet.</p>
      ) : (
        <div className="divide-y divide-border rounded-md border border-border">
          {inventors.map((inventor) => (
            <div key={inventor.id} className="p-3 text-xs">
              <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-1.5 text-sm font-medium">
                    {inventor.complete
                      ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" aria-label="All Form 1 particulars recorded" />
                      : <TriangleAlert className="h-3.5 w-3.5 text-amber-500" aria-label="Form 1 particulars missing" />}
                    {inventor.full_name}
                    {inventor.has_portal_access && <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal">Portal access</span>}
                  </p>
                  <p className="text-muted-foreground">
                    {[inventor.address_house, inventor.address_street].filter(Boolean).join(", ")}{inventor.address_house || inventor.address_street ? " · " : ""}{place(inventor)}{inventor.pincode ? ` ${inventor.pincode}` : ""}
                  </p>
                  <p className="text-muted-foreground">
                    {[inventor.nationality, inventor.email, inventor.phone].filter(Boolean).join(" · ") || "No contact details"}
                    {" · "}{inventor.case_count ?? 0} case{inventor.case_count === 1 ? "" : "s"}
                  </p>
                </div>
                <div className="flex shrink-0 gap-1">
                  {canGrantPortal && !inventor.has_portal_access && (
                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setGranting(granting === inventor.id ? null : inventor.id)}>
                      <KeyRound className="mr-1 h-3.5 w-3.5" />Portal access
                    </Button>
                  )}
                  <Button size="icon" variant="ghost" className="h-7 w-7" title="Edit" onClick={() => setEditing(editing === inventor.id ? null : inventor.id)}>
                    <Pencil className="h-3.5 w-3.5" />
                  </Button>
                  <Button size="icon" variant="ghost" className="h-7 w-7" title="Delete" disabled={remove.isPending}
                    onClick={() => { if (window.confirm(`Delete ${inventor.full_name}? This cannot be undone.`)) remove.mutate(inventor) }}>
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
              {editing === inventor.id && (
                <InventorForm initial={inventor} onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} />
              )}
              {granting === inventor.id && (
                <PortalAccess inventor={inventor} onDone={() => { setGranting(null); refresh() }} />
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function PortalAccess({ inventor, onDone }: { inventor: InventorRecord; onDone: () => void }) {
  const [password, setPassword] = useState("")
  const grant = useMutation({
    mutationFn: () => api.grantInventorPortal(inventor.id, { password }),
    onSuccess: () => { toast.success(`Portal login created for ${inventor.email}. Share the password with them directly.`); onDone() },
    onError: (failure: Error) => toast.error(failure.message),
  })

  if (!inventor.email) {
    return <p className="mt-2 text-amber-600">Add the inventor's email first (Edit); it becomes their portal login.</p>
  }
  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground">Login: <span className="font-medium text-foreground">{inventor.email}</span></span>
      <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password (min. 8)"
        className="h-7 w-44 rounded border border-border bg-background px-2 font-mono text-xs" />
      <Button size="sm" className="h-7 text-xs" disabled={grant.isPending || password.length < 8} onClick={() => grant.mutate()}>
        {grant.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : "Create login"}
      </Button>
      <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onDone}>Cancel</Button>
    </div>
  )
}
