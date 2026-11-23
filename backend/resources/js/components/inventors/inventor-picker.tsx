import { useState } from "react"
import { useQuery, useQueryClient } from "@tanstack/react-query"
import { ArrowDown, ArrowUp, CheckCircle2, Loader2, Pencil, Plus, Search, TriangleAlert, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { api, type PortalUser } from "@/lib/api-client"
import { AddressFields, Field, GENDERS, isIndianCountry, selectClass, TextField } from "@/components/ipo/party-fields"
import type { InventorInput, InventorRecord, IpoGender } from "@/types/ipo-forms"

export function place(inventor: InventorRecord): string {
  return [inventor.city, inventor.state, inventor.country].filter(Boolean).join(", ") || "No address yet"
}

/**
 * Ordered list of a case's inventors. Staff pick from the case client's inventors (or
 * search other clients for a shared inventor) or add a new one under the client with
 * every IPO Form 1 particular. Editing an inventor updates them on every case.
 */
export function InventorPicker({ value, onChange, clientId, disabled = false }: {
  value: InventorRecord[]
  onChange: (next: InventorRecord[]) => void
  clientId?: number | string | null
  disabled?: boolean
}) {
  const queryClient = useQueryClient()
  const client = clientId ? Number(clientId) : null
  const [term, setTerm] = useState("")
  const [otherClients, setOtherClients] = useState(false)
  // null: nothing open; "new": adding; a number: editing that inventor.
  const [editing, setEditing] = useState<"new" | number | null>(null)
  const search = term.trim()
  const { data: options = [], isFetching } = useQuery<InventorRecord[]>({
    queryKey: ["inventor-options", client, otherClients, search],
    queryFn: () => api.searchInventors({ clientId: client, search: search || undefined, otherClients }),
    enabled: client !== null && (!otherClients || search.length >= 2),
    staleTime: 30_000,
  })
  const chosen = new Set(value.map((inventor) => inventor.id))
  const available = options.filter((inventor) => !chosen.has(inventor.id))
  const refreshLists = () => {
    void queryClient.invalidateQueries({ queryKey: ["inventor-options"] })
    void queryClient.invalidateQueries({ queryKey: ["client-inventors"] })
  }

  const move = (index: number, step: -1 | 1) => {
    const next = [...value]
    const [item] = next.splice(index, 1)
    next.splice(index + step, 0, item)
    onChange(next)
  }
  const replace = (record: InventorRecord) => onChange(value.map((inventor) => (inventor.id === record.id ? record : inventor)))
  const emptyMessage = otherClients
    ? `No inventor at another client matches "${search}".`
    : search ? `No inventor of this client matches "${search}".` : "No other inventors for this client yet. Use New inventor to add one."

  return (
    <div className="space-y-2">
      {value.length === 0 && <p className="text-xs text-muted-foreground">No inventors on this case yet.</p>}
      {value.map((inventor, index) => (
        <div key={inventor.id} className="rounded-md border border-border/70 p-2 text-sm">
          <div className="flex items-center gap-2">
            <span className="w-5 text-xs text-muted-foreground">{index + 1}.</span>
            {inventor.complete
              ? <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-emerald-600" aria-label="All Form 1 particulars recorded" />
              : <TriangleAlert className="h-3.5 w-3.5 shrink-0 text-amber-500" aria-label="Form 1 particulars missing" />}
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium">{inventor.full_name}</p>
              <p className="truncate text-xs text-muted-foreground">{inventor.nationality ? `${inventor.nationality} · ` : ""}{place(inventor)}</p>
            </div>
            {!disabled && (
              <div className="flex shrink-0 gap-0.5">
                <Button type="button" size="icon" variant="ghost" className="h-7 w-7" title="Move up" disabled={index === 0} onClick={() => move(index, -1)}><ArrowUp className="h-3.5 w-3.5" /></Button>
                <Button type="button" size="icon" variant="ghost" className="h-7 w-7" title="Move down" disabled={index === value.length - 1} onClick={() => move(index, 1)}><ArrowDown className="h-3.5 w-3.5" /></Button>
                <Button type="button" size="icon" variant="ghost" className="h-7 w-7" title="Edit particulars" onClick={() => setEditing(editing === inventor.id ? null : inventor.id)}><Pencil className="h-3.5 w-3.5" /></Button>
                <Button type="button" size="icon" variant="ghost" className="h-7 w-7" title="Remove from this case" onClick={() => onChange(value.filter((item) => item.id !== inventor.id))}><X className="h-3.5 w-3.5" /></Button>
              </div>
            )}
          </div>
          {editing === inventor.id && (
            <InventorForm initial={inventor} onCancel={() => setEditing(null)} onSaved={(record) => { replace(record); setEditing(null); refreshLists() }} />
          )}
        </div>
      ))}

      {!disabled && client === null && <p className="text-xs text-muted-foreground">Select the client first to pick its inventors.</p>}

      {!disabled && client !== null && editing !== "new" && (
        <div className="space-y-1">
          <div className="flex flex-wrap gap-2">
            <div className="relative min-w-[12rem] flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input value={term} onChange={(event) => setTerm(event.target.value)}
                placeholder={otherClients ? "Search other clients' inventors by name or email" : "Filter this client's inventors"}
                className="h-9 pl-8 text-sm" />
              {isFetching && <Loader2 className="absolute right-2.5 top-2.5 h-3.5 w-3.5 animate-spin text-muted-foreground" />}
            </div>
            <Button type="button" size="sm" variant="outline" className="h-9" onClick={() => setEditing("new")}>
              <Plus className="mr-1 h-3.5 w-3.5" />New inventor
            </Button>
          </div>
          <label className="flex items-center gap-2 text-xs text-muted-foreground">
            <input type="checkbox" checked={otherClients} onChange={(event) => setOtherClients(event.target.checked)} />
            Search other clients (for an inventor shared between clients)
          </label>
          {(!otherClients || search.length >= 2) && !isFetching && (
            <div className="max-h-48 overflow-y-auto rounded-md border border-border bg-background">
              {available.length === 0
                ? <p className="px-3 py-2 text-xs text-muted-foreground">{emptyMessage}</p>
                : available.map((inventor) => (
                  <button key={inventor.id} type="button" className="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm hover:bg-muted"
                    onClick={() => { onChange([...value, inventor]); setTerm("") }}>
                    <span className="truncate">
                      <span className="font-medium">{inventor.full_name}</span>{" "}
                      <span className="text-xs text-muted-foreground">{otherClients && inventor.client_code ? `${inventor.client_code} · ` : ""}{place(inventor)}</span>
                    </span>
                    <Plus className="h-3.5 w-3.5 shrink-0 text-gold" />
                  </button>
                ))}
            </div>
          )}
        </div>
      )}

      {editing === "new" && client !== null && (
        <InventorForm initial={{ client_id: client, full_name: term.trim(), nationality: "Indian", country: "India", country_of_residence: "India" }}
          onCancel={() => setEditing(null)}
          onSaved={(record) => { onChange([...value, record]); setEditing(null); setTerm(""); refreshLists() }} />
      )}
    </div>
  )
}

type InventorParticulars = Omit<InventorInput, "client_id" | "full_name" | "email" | "phone">

/** India by default, as most of the firm's inventors are Indian. */
export const defaultInventorParticulars = (): InventorParticulars =>
  ({ nationality: "Indian", country: "India", country_of_residence: "India" })

/**
 * The first Form 1 particular still missing (the same rule as Inventor::particularRules on the
 * server): nationality, house no. or street, city, country, pin code, and state for India.
 */
export function inventorProblem(value: InventorParticulars, who = "the inventor"): string | null {
  const blank = (text: string | number | null | undefined) => String(text ?? "").trim() === ""
  if (blank(value.nationality)) return `Enter the nationality of ${who}.`
  if (blank(value.address_house) && blank(value.address_street)) return `Enter the house no. or street of ${who}.`
  if (blank(value.city)) return `Enter the city of ${who}.`
  if (blank(value.country)) return `Enter the country of ${who}.`
  if (isIndianCountry(value.country) && blank(value.state)) return `Enter the state of ${who} (needed for an Indian address).`
  if (blank(value.pincode)) return `Enter the pin code of ${who}.`
  return null
}

/** Nationality, gender, age, residence and the address: the Form 1 particulars after the name. */
export function InventorParticularsFields<T extends InventorParticulars>({ value, onChange }: { value: T; onChange: (next: T) => void }) {
  const set = (key: keyof InventorParticulars) => (text: string) => onChange({ ...value, [key]: text })
  return (
    <>
      <div className="grid gap-2 sm:grid-cols-4">
        <TextField label="Nationality *" value={value.nationality} onChange={set("nationality")} />
        <Field label="Gender">
          <select className={selectClass} value={value.gender ?? ""} onChange={(event) => onChange({ ...value, gender: (event.target.value || null) as IpoGender | null })}>
            <option value="">-</option>
            {GENDERS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
          </select>
        </Field>
        <TextField label="Age" type="number" value={value.age} onChange={set("age")} />
        <TextField label="Country of residence" value={value.country_of_residence} onChange={set("country_of_residence")} />
      </div>
      <AddressFields value={value} onChange={onChange} required />
    </>
  )
}

/**
 * Every Form 1 particular for one inventor; saves the inventor record under its client.
 * With `link` (the client panel's Inventors tab, adding): the name is a combobox over the
 * client's portal users. Picking one makes that person the inventor with their existing
 * login; a typed new name gets a new inventor-role login (email + password) when the
 * staff member may create logins.
 */
export function InventorForm({ initial, onSaved, onCancel, link }: {
  initial: InventorInput & { id?: number }
  onSaved: (record: InventorRecord) => void
  onCancel: () => void
  link?: { clientId: number; canCreateLogin: boolean }
}) {
  const [draft, setDraft] = useState<InventorInput>(initial)
  const [saving, setSaving] = useState(false)
  const set = (key: keyof InventorInput) => (text: string) => setDraft({ ...draft, [key]: text })
  const adding = !initial.id && !!link
  const picked = adding && !!draft.user_id
  const newLogin = adding && !picked && !!link?.canCreateLogin

  async function save() {
    if (!draft.full_name.trim()) { toast.error("Enter the inventor's full name."); return }
    const problem = inventorProblem(draft)
    if (problem) { toast.error(problem); return }
    if (newLogin) {
      if (!/^\S+@\S+\.\S+$/.test((draft.email ?? "").trim())) { toast.error("Enter the inventor's email; it is their portal login."); return }
      if ((draft.portal_password ?? "").length < 8) { toast.error("The login password must be at least 8 characters."); return }
    }
    setSaving(true)
    try {
      const payload: InventorInput = {
        ...draft,
        full_name: draft.full_name.trim(),
        age: draft.age === "" || draft.age === null || draft.age === undefined ? null : Number(draft.age),
        gender: draft.gender || null,
        email: draft.email?.trim() || null,
        user_id: picked ? draft.user_id : null,
        portal_password: newLogin ? draft.portal_password : null,
      }
      if (initial.id) { delete payload.user_id; delete payload.portal_password }
      const record = initial.id ? await api.updateInventor(initial.id, payload) : await api.createInventor(payload)
      toast.success(initial.id ? "Inventor updated on every case they are named on."
        : picked ? `${record.full_name} added as inventor, using their existing portal login.`
        : newLogin ? `Inventor added with portal login ${record.email}. Share the password with them directly.`
        : "Inventor added.")
      onSaved(record)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not save the inventor.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-2 space-y-2 rounded-md border border-gold/40 bg-muted/10 p-3">
      {adding && link ? (
        <InventorNameCombobox clientId={link.clientId} draft={draft} onChange={setDraft} />
      ) : (
        <TextField label="Name in full (family name first) *" value={draft.full_name} onChange={set("full_name")} />
      )}
      <InventorParticularsFields value={draft} onChange={setDraft} />
      <div className="grid gap-2 sm:grid-cols-3">
        <Field label={newLogin ? "Email (portal login) *" : "Email (also the portal login)"}>
          <Input type="email" value={draft.email ?? ""} disabled={picked} onChange={(event) => set("email")(event.target.value)} className="h-9 text-sm text-foreground" />
        </Field>
        <TextField label="Phone" value={draft.phone} onChange={set("phone")} />
        {newLogin && (
          <TextField label="Login password (min. 8) *" value={draft.portal_password} onChange={set("portal_password")} />
        )}
      </div>
      {adding && !picked && !link?.canCreateLogin && (
        <p className="text-xs text-muted-foreground">A partner, director or manager can give this inventor a portal login later.</p>
      )}
      <div className="flex gap-2">
        <Button type="button" size="sm" disabled={saving} onClick={save}>
          {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}{initial.id ? "Save inventor" : "Add inventor"}
        </Button>
        <Button type="button" size="sm" variant="outline" disabled={saving} onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  )
}

/**
 * Name field that searches the client's portal users (the Users tab list, same query key).
 * Picking a user fills name, email and phone and links their login; typing a name that
 * is not in the list adds a new person.
 */
function InventorNameCombobox({ clientId, draft, onChange }: {
  clientId: number
  draft: InventorInput
  onChange: (next: InventorInput) => void
}) {
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  // Same key as the Users tab (portalUsersKey in components/clients/client-portal-users).
  const { data: users = [] } = useQuery<PortalUser[]>({
    queryKey: ["client-portal-users", clientId],
    queryFn: () => api.getPortalClientUsers(clientId),
  })
  const term = draft.full_name.trim().toLowerCase()
  const options = users
    .filter((user) => !user.is_inventor && user.role !== "inventor")
    .filter((user) => !term || `${user.name} ${user.email}`.toLowerCase().includes(term))
    .slice(0, 8)
  const pickedUser = draft.user_id ? users.find((user) => user.id === draft.user_id) : undefined

  function pick(user: PortalUser) {
    onChange({ ...draft, full_name: user.name, email: user.email, phone: draft.phone || user.phone || "", user_id: user.id, portal_password: null })
    setOpen(false)
  }

  return (
    <Field label="Name in full (family name first) *: pick a portal user, or type a new name">
      <div className="relative">
        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={draft.full_name}
          role="combobox"
          aria-expanded={open}
          aria-autocomplete="list"
          placeholder="Search this client's users or type a new name"
          className="h-9 pl-8 text-sm text-foreground"
          onFocus={() => setOpen(true)}
          onBlur={() => window.setTimeout(() => setOpen(false), 150)}
          onChange={(event) => {
            // A picked user stays linked while the name is tidied (family name first); × unlinks.
            onChange({ ...draft, full_name: event.target.value })
            setOpen(true)
            setActive(0)
          }}
          onKeyDown={(event) => {
            if (!open || options.length === 0) return
            if (event.key === "ArrowDown") { event.preventDefault(); setActive((active + 1) % options.length) }
            else if (event.key === "ArrowUp") { event.preventDefault(); setActive((active - 1 + options.length) % options.length) }
            else if (event.key === "Enter") { event.preventDefault(); pick(options[active]) }
            else if (event.key === "Escape") setOpen(false)
          }}
        />
        {open && !draft.user_id && (
          <div role="listbox" className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-md border border-border bg-background shadow-md">
            {options.map((user, index) => (
              <button key={user.id} type="button" role="option" aria-selected={index === active}
                className={`flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm ${index === active ? "bg-muted" : "hover:bg-muted"}`}
                onMouseDown={(event) => event.preventDefault()} onClick={() => pick(user)}>
                <span className="truncate">
                  <span className="font-medium">{user.name}</span>{" "}
                  <span className="text-xs text-muted-foreground">{user.email}{user.designation ? ` · ${user.designation}` : ""}</span>
                </span>
                <Plus className="h-3.5 w-3.5 shrink-0 text-gold" />
              </button>
            ))}
            <p className="border-t border-border px-3 py-1.5 text-xs text-muted-foreground">
              {term ? `Not listed? Keep typing to add "${draft.full_name.trim()}" as a new inventor.` : "Type a name to add someone new."}
            </p>
          </div>
        )}
      </div>
      {draft.user_id && (
        <p className="mt-1 flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
          <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
          Uses the portal login {pickedUser?.email ?? draft.email}{pickedUser?.designation ? ` (${pickedUser.designation})` : ""}; no new login is made. Check the name is in full, family name first.
          <button type="button" className="ml-1 text-muted-foreground hover:text-foreground" title="Not this user"
            onClick={() => onChange({ ...draft, user_id: null, email: "", phone: "", full_name: "" })}>
            <X className="h-3.5 w-3.5" />
          </button>
        </p>
      )}
    </Field>
  )
}
