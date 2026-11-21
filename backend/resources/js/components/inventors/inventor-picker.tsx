import { useState } from "react"
import { useQuery } from "@tanstack/react-query"
import { ArrowDown, ArrowUp, CheckCircle2, Loader2, Pencil, Plus, Search, TriangleAlert, X } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { api } from "@/lib/api-client"
import { AddressFields, Field, GENDERS, selectClass, TextField } from "@/components/ipo/party-fields"
import type { InventorInput, InventorRecord, IpoGender } from "@/types/ipo-forms"

function place(inventor: InventorRecord): string {
  return [inventor.city, inventor.state, inventor.country].filter(Boolean).join(", ") || "No address yet"
}

/**
 * Ordered list of a case's inventors. Staff pick existing (firm-wide) inventors by
 * search or add a new one with every IPO Form 1 particular. Editing an inventor here
 * updates their record everywhere they are named.
 */
export function InventorPicker({ value, onChange, disabled = false }: {
  value: InventorRecord[]
  onChange: (next: InventorRecord[]) => void
  disabled?: boolean
}) {
  const [term, setTerm] = useState("")
  // null: nothing open; "new": adding; a number: editing that inventor.
  const [editing, setEditing] = useState<"new" | number | null>(null)
  const search = term.trim()
  const { data: results = [], isFetching } = useQuery<InventorRecord[]>({
    queryKey: ["inventor-search", search],
    queryFn: () => api.searchInventors(search),
    enabled: search.length >= 2,
    staleTime: 30_000,
  })
  const chosen = new Set(value.map((inventor) => inventor.id))
  const matches = results.filter((inventor) => !chosen.has(inventor.id))

  const move = (index: number, step: -1 | 1) => {
    const next = [...value]
    const [item] = next.splice(index, 1)
    next.splice(index + step, 0, item)
    onChange(next)
  }
  const replace = (record: InventorRecord) => onChange(value.map((inventor) => (inventor.id === record.id ? record : inventor)))

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
            <InventorForm initial={inventor} onCancel={() => setEditing(null)} onSaved={(record) => { replace(record); setEditing(null) }} />
          )}
        </div>
      ))}

      {!disabled && editing !== "new" && (
        <div className="space-y-1">
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Search existing inventors by name or email" className="h-9 pl-8 text-sm" />
              {isFetching && <Loader2 className="absolute right-2.5 top-2.5 h-3.5 w-3.5 animate-spin text-muted-foreground" />}
            </div>
            <Button type="button" size="sm" variant="outline" className="h-9" onClick={() => setEditing("new")}>
              <Plus className="mr-1 h-3.5 w-3.5" />New inventor
            </Button>
          </div>
          {search.length >= 2 && !isFetching && (
            <div className="rounded-md border border-border bg-background">
              {matches.length === 0
                ? <p className="px-3 py-2 text-xs text-muted-foreground">No other inventor matches “{search}”. Use “New inventor” to add them.</p>
                : matches.map((inventor) => (
                  <button key={inventor.id} type="button" className="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm hover:bg-muted"
                    onClick={() => { onChange([...value, inventor]); setTerm("") }}>
                    <span className="truncate"><span className="font-medium">{inventor.full_name}</span> <span className="text-xs text-muted-foreground">{place(inventor)}</span></span>
                    <Plus className="h-3.5 w-3.5 shrink-0 text-gold" />
                  </button>
                ))}
            </div>
          )}
        </div>
      )}

      {editing === "new" && (
        <InventorForm initial={{ full_name: term.trim(), nationality: "Indian", country: "India", country_of_residence: "India" }}
          onCancel={() => setEditing(null)}
          onSaved={(record) => { onChange([...value, record]); setEditing(null); setTerm("") }} />
      )}
    </div>
  )
}

/** Every Form 1 particular for one inventor; saves the firm-wide record. */
export function InventorForm({ initial, onSaved, onCancel }: {
  initial: InventorInput & { id?: number }
  onSaved: (record: InventorRecord) => void
  onCancel: () => void
}) {
  const [draft, setDraft] = useState<InventorInput>(initial)
  const [saving, setSaving] = useState(false)
  const set = (key: keyof InventorInput) => (text: string) => setDraft({ ...draft, [key]: text })

  async function save() {
    if (!draft.full_name.trim()) { toast.error("Enter the inventor's full name."); return }
    setSaving(true)
    try {
      const payload: InventorInput = {
        ...draft,
        full_name: draft.full_name.trim(),
        age: draft.age === "" || draft.age === null || draft.age === undefined ? null : Number(draft.age),
        gender: draft.gender || null,
        email: draft.email || null,
      }
      const record = initial.id ? await api.updateInventor(initial.id, payload) : await api.createInventor(payload)
      toast.success(initial.id ? "Inventor updated on every case they are named on." : "Inventor added.")
      onSaved(record)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not save the inventor.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-2 space-y-2 rounded-md border border-gold/40 bg-muted/10 p-3">
      <div className="grid gap-2 sm:grid-cols-3">
        <TextField className="sm:col-span-2" label="Name in full (family name first) *" value={draft.full_name} onChange={set("full_name")} />
        <TextField label="Nationality" value={draft.nationality} onChange={set("nationality")} />
        <Field label="Gender (optional)">
          <select className={selectClass} value={draft.gender ?? ""} onChange={(event) => setDraft({ ...draft, gender: (event.target.value || null) as IpoGender | null })}>
            <option value="">-</option>
            {GENDERS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
          </select>
        </Field>
        <TextField label="Age (optional)" type="number" value={draft.age} onChange={set("age")} />
        <TextField label="Country of residence" value={draft.country_of_residence} onChange={set("country_of_residence")} />
      </div>
      <AddressFields value={draft} onChange={setDraft} />
      <div className="grid gap-2 sm:grid-cols-2">
        <TextField label="Email (optional)" type="email" value={draft.email} onChange={set("email")} />
        <TextField label="Phone (optional)" value={draft.phone} onChange={set("phone")} />
      </div>
      <div className="flex gap-2">
        <Button type="button" size="sm" disabled={saving} onClick={save}>
          {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}{initial.id ? "Save inventor" : "Add inventor"}
        </Button>
        <Button type="button" size="sm" variant="outline" disabled={saving} onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  )
}
