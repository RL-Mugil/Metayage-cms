import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { KeyRound, Loader2, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api, type NewPortalUser, type PortalDesignation, type PortalUser } from "@/lib/api-client"

/** Firm roles that create, reset and remove portal logins (ClientPortalUsers::MANAGE_ROLES). */
export const PORTAL_MANAGE_ROLES = ["super_admin", "partner", "manager", "hr"]

export const PORTAL_DESIGNATIONS: PortalDesignation[] = ["Manager", "CFO", "CTO", "Accounts Person", "Inventor", "Other"]

/** What each designation can see in the portal (fixed by designation). */
export const DESIGNATION_ACCESS: Record<PortalDesignation, string> = {
  Manager: "Admin: cases, documents, invoices and payments; manages the client's team",
  CFO: "Finance: invoices, payments and ledger only",
  "Accounts Person": "Finance: invoices, payments and ledger only",
  CTO: "Standard: cases, documents and invoices",
  Other: "Standard: cases, documents and invoices",
  Inventor: "Inventor: only the cases they are named on; also added to the Inventors tab",
}

const ACCESS_STYLE: Record<string, string> = {
  Admin: "bg-gold/15 text-gold",
  Finance: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
  Standard: "bg-muted text-muted-foreground",
  Inventor: "bg-sky-500/10 text-sky-700 dark:text-sky-400",
}

export const portalUsersKey = (clientId: number) => ["client-portal-users", clientId]

/**
 * A client's portal logins: the client panel's Users tab and the Client Portal page's
 * company profile show this same list (one query key), so a change in one shows in the other.
 */
export function ClientPortalUsers({ clientId, canManage }: { clientId: number; canManage: boolean }) {
  const queryClient = useQueryClient()
  const { data: users = [], isLoading, error } = useQuery<PortalUser[]>({
    queryKey: portalUsersKey(clientId),
    queryFn: () => api.getPortalClientUsers(clientId),
  })
  const [adding, setAdding] = useState(false)
  const [resetting, setResetting] = useState<number | null>(null)
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: portalUsersKey(clientId) })
    void queryClient.invalidateQueries({ queryKey: ["client-inventors", clientId] })
  }
  const remove = useMutation({
    mutationFn: (user: PortalUser) => api.removePortalClientUser(clientId, user.id),
    onSuccess: () => { toast.success("Portal login removed."); refresh() },
    onError: (failure: Error) => toast.error(failure.message),
  })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /></div>
  if (error) return <p className="py-10 text-center text-sm text-destructive">{(error as Error).message}</p>

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground">People at this client who log in to the portal. What they see follows their designation.</p>
        {canManage && !adding && (
          <Button size="sm" onClick={() => setAdding(true)}><Plus className="mr-1 h-3.5 w-3.5" />Add user</Button>
        )}
      </div>

      {adding && <PortalUserForm clientId={clientId} onCancel={() => setAdding(false)} onSaved={() => { setAdding(false); refresh() }} />}

      {users.length === 0 && !adding ? (
        <p className="py-10 text-center text-sm text-muted-foreground">No portal users for this client yet.</p>
      ) : (
        <div className="divide-y divide-border rounded-md border border-border">
          {users.map((user) => (
            <div key={user.id} className="p-3 text-xs">
              <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                    {user.name}
                    {user.designation && <span className="rounded border border-border px-1.5 py-0.5 text-[10px] font-normal">{user.designation}</span>}
                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-normal ${ACCESS_STYLE[user.access] ?? "bg-muted"}`}>{user.access}</span>
                    {user.is_primary && <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal">Primary</span>}
                    {user.status !== "Active" && <span className="text-[10px] font-normal text-amber-600">{user.status}</span>}
                  </p>
                  <p className="text-muted-foreground">{[user.email, user.phone].filter(Boolean).join(" · ")}</p>
                </div>
                {canManage && (
                  <div className="flex shrink-0 gap-1">
                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setResetting(resetting === user.id ? null : user.id)}>
                      <KeyRound className="mr-1 h-3.5 w-3.5" />Reset password
                    </Button>
                    {!user.is_primary && (
                      <Button size="icon" variant="ghost" className="h-7 w-7" title="Remove login" disabled={remove.isPending}
                        onClick={() => { if (window.confirm(`Remove ${user.name}'s portal login?`)) remove.mutate(user) }}>
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    )}
                  </div>
                )}
              </div>
              {resetting === user.id && <ResetPassword clientId={clientId} user={user} onDone={() => setResetting(null)} />}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

const inputClass = "h-8 w-full rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"

/** Fields for one new portal login; used by the Users tab and the New Client form. */
export function PortalUserFields({ value, onChange, designations = PORTAL_DESIGNATIONS }: {
  value: NewPortalUser
  onChange: (next: NewPortalUser) => void
  designations?: PortalDesignation[]
}) {
  const set = (key: keyof NewPortalUser) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    onChange({ ...value, [key]: event.target.value })
  return (
    <div className="grid gap-2 sm:grid-cols-5">
      <label className="text-[10px] text-muted-foreground">Designation
        <select value={value.designation} onChange={set("designation")} className={inputClass}>
          {designations.map((designation) => <option key={designation}>{designation}</option>)}
        </select>
      </label>
      <label className="text-[10px] text-muted-foreground">Name
        <input value={value.name} onChange={set("name")} className={inputClass} />
      </label>
      <label className="text-[10px] text-muted-foreground">Email (login)
        <input type="email" value={value.email} onChange={set("email")} className={inputClass} />
      </label>
      <label className="text-[10px] text-muted-foreground">Phone
        <input value={value.phone ?? ""} onChange={set("phone")} className={inputClass} />
      </label>
      <label className="text-[10px] text-muted-foreground">Password (min. 8)
        <input value={value.password} onChange={set("password")} className={`${inputClass} font-mono`} />
      </label>
      <p className="text-[10px] text-muted-foreground sm:col-span-5">{DESIGNATION_ACCESS[value.designation]}</p>
    </div>
  )
}

export const blankPortalUser = (designation: PortalDesignation = "Manager"): NewPortalUser =>
  ({ designation, name: "", email: "", phone: "", password: "" })

export function portalUserProblem(user: NewPortalUser): string | null {
  if (!user.name.trim()) return "Enter the portal user's name."
  if (!/^\S+@\S+\.\S+$/.test(user.email.trim())) return `Enter a valid email for ${user.name || "the portal user"}.`
  if (user.password.length < 8) return `The password for ${user.name || user.email} must be at least 8 characters.`
  return null
}

function PortalUserForm({ clientId, onCancel, onSaved }: { clientId: number; onCancel: () => void; onSaved: () => void }) {
  const [user, setUser] = useState<NewPortalUser>(blankPortalUser())
  const save = useMutation({
    mutationFn: () => api.addPortalClientUser(clientId, { ...user, name: user.name.trim(), email: user.email.trim() }),
    onSuccess: (result) => { toast.success(`Portal login created for ${result.user.email}. Share the password with them directly.`); onSaved() },
    onError: (failure: Error) => toast.error(failure.message),
  })
  const problem = portalUserProblem(user)
  return (
    <div className="space-y-2 rounded-md border border-border bg-muted/20 p-3">
      <PortalUserFields value={user} onChange={setUser} />
      <div className="flex gap-2">
        <Button size="sm" className="h-7 text-xs" disabled={save.isPending || !!problem} title={problem ?? undefined} onClick={() => save.mutate()}>
          {save.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : "Create login"}
        </Button>
        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  )
}

function ResetPassword({ clientId, user, onDone }: { clientId: number; user: PortalUser; onDone: () => void }) {
  const [password, setPassword] = useState("")
  const reset = useMutation({
    mutationFn: () => api.resetPortalUserPassword(clientId, user.id, password),
    onSuccess: () => { toast.success(`Password changed for ${user.email}. Share it with them directly.`); onDone() },
    onError: (failure: Error) => toast.error(failure.message),
  })
  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="New password (min. 8)"
        className="h-7 w-48 rounded border border-border bg-background px-2 font-mono text-xs" />
      <Button size="sm" className="h-7 text-xs" disabled={reset.isPending || password.length < 8} onClick={() => reset.mutate()}>
        {reset.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : "Save password"}
      </Button>
      <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onDone}>Cancel</Button>
    </div>
  )
}
