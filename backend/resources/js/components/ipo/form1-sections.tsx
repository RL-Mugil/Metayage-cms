import { useState } from "react"
import { Loader2, Pencil, Plus, Trash2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"
import { AddressFields, Field, GENDERS, selectClass, TextField } from "@/components/ipo/party-fields"
import type {
  Form1Client, Form1Input, IpoApplicantCategory, IpoApplicationRef, IpoApplicationType,
  IpoCoApplicant, IpoConventionRow, IpoGender, IpoSubType,
} from "@/types/ipo-forms"

type Patch = (patch: Partial<Form1Input>) => void

const TYPE_OPTIONS: Array<{ value: IpoApplicationType; label: string }> = [
  { value: "ordinary", label: "Ordinary" },
  { value: "convention", label: "Convention" },
  { value: "pct_np", label: "PCT national phase" },
  { value: "pph", label: "PPH" },
]

const SUB_OPTIONS: Array<{ value: IpoSubType; label: string }> = [
  { value: "none", label: "None" },
  { value: "divisional", label: "Divisional" },
  { value: "patent_of_addition", label: "Patent of addition" },
]

export const CATEGORY_OPTIONS: Array<{ value: IpoApplicantCategory; label: string }> = [
  { value: "natural_person", label: "Natural person" },
  { value: "other_than_natural", label: "Other than natural person" },
  { value: "small_entity", label: "Other than natural person: small entity" },
  { value: "startup", label: "Other than natural person: startup" },
  { value: "others", label: "Other than natural person: others" },
  { value: "educational", label: "Educational institution" },
]

/** Declarations tied to an application type are ticked or cleared when the type changes. */
const TYPE_DECLARATIONS: Record<string, (type: IpoApplicationType, sub: IpoSubType) => boolean> = {
  convention_first: (type) => type === "convention",
  convention_priority: (type) => type === "convention",
  pct_based: (type) => type === "pct_np",
  divisional: (_type, sub) => sub === "divisional",
  addition: (_type, sub) => sub === "patent_of_addition",
}

function syncDeclarations(current: string[], type: IpoApplicationType, sub: IpoSubType): string[] {
  const kept = current.filter((key) => !(key in TYPE_DECLARATIONS))
  const typed = Object.entries(TYPE_DECLARATIONS).filter(([, applies]) => applies(type, sub)).map(([key]) => key)
  return [...kept, ...typed]
}

export function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3 rounded-md border border-border p-3">
      <h3 className="text-sm font-semibold">{title}</h3>
      {children}
    </section>
  )
}

function RefFields({ label, value, onChange }: { label: string; value: IpoApplicationRef; onChange: (next: IpoApplicationRef) => void }) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      <TextField label={`${label} number`} value={value.application_number} onChange={(text) => onChange({ ...value, application_number: text })} />
      <TextField label={`${label} filing date`} type="date" value={value.filing_date} onChange={(text) => onChange({ ...value, filing_date: text })} />
    </div>
  )
}

export function ApplicationTypeSection({ input, patch }: { input: Form1Input; patch: Patch }) {
  const setType = (type: IpoApplicationType) => {
    const sub = type === "pph" ? "none" : input.sub_type
    patch({
      application_type: type,
      sub_type: sub,
      declarations: syncDeclarations(input.declarations, type, sub),
      convention: type === "convention" && input.convention.length === 0 ? [{}] : input.convention,
    })
  }
  const setSub = (sub: IpoSubType) => patch({ sub_type: sub, declarations: syncDeclarations(input.declarations, input.application_type, sub) })
  const setConvention = (index: number, row: IpoConventionRow) => patch({ convention: input.convention.map((item, i) => (i === index ? row : item)) })

  return (
    <Section title="Type of application">
      <div className="flex flex-wrap gap-4 text-sm">
        {TYPE_OPTIONS.map((option) => (
          <label key={option.value} className="flex items-center gap-1.5">
            <input type="radio" name="ipo-type" checked={input.application_type === option.value} onChange={() => setType(option.value)} />
            {option.label}
          </label>
        ))}
      </div>
      {input.application_type !== "pph" && (
        <div className="flex flex-wrap gap-4 text-sm">
          <span className="text-muted-foreground">Also:</span>
          {SUB_OPTIONS.map((option) => (
            <label key={option.value} className="flex items-center gap-1.5">
              <input type="radio" name="ipo-sub-type" checked={input.sub_type === option.value} onChange={() => setSub(option.value)} />
              {option.label}
            </label>
          ))}
        </div>
      )}

      {input.application_type === "convention" && (
        <div className="space-y-2">
          <p className="text-xs font-medium">Priority (convention) applications: section 8</p>
          {input.convention.map((row, index) => (
            <div key={index} className="grid gap-2 rounded border border-border/60 p-2 sm:grid-cols-5">
              <TextField label="Country" value={row.country} onChange={(text) => setConvention(index, { ...row, country: text })} />
              <TextField label="Application no." value={row.application_number} onChange={(text) => setConvention(index, { ...row, application_number: text })} />
              <TextField label="Filing date" type="date" value={row.filing_date} onChange={(text) => setConvention(index, { ...row, filing_date: text })} />
              <TextField label="Applicant name" value={row.applicant_name} onChange={(text) => setConvention(index, { ...row, applicant_name: text })} />
              <div className="flex items-end gap-1">
                <TextField className="flex-1" label="Title" value={row.title} onChange={(text) => setConvention(index, { ...row, title: text })} />
                <Button type="button" size="icon" variant="ghost" className="h-9 w-9" title="Remove priority application"
                  onClick={() => patch({ convention: input.convention.filter((_, i) => i !== index) })}>
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </div>
          ))}
          <Button type="button" size="sm" variant="outline" onClick={() => patch({ convention: [...input.convention, {}] })}>
            <Plus className="mr-1 h-3.5 w-3.5" />Add priority application
          </Button>
        </div>
      )}
      {input.application_type === "pct_np" && <RefFields label="International application" value={input.pct} onChange={(pct) => patch({ pct })} />}
      {input.sub_type === "divisional" && <RefFields label="Original (first) application" value={input.original} onChange={(original) => patch({ original })} />}
      {input.sub_type === "patent_of_addition" && <RefFields label="Main application/patent" value={input.main} onChange={(main) => patch({ main })} />}
    </Section>
  )
}

function clientName(client: Form1Client): string {
  return client.legal_name || client.company_name || client.client_code || "Client"
}

function addressLine(value: { address_house?: string | null; address_street?: string | null; city?: string | null; state?: string | null; country?: string | null; pincode?: string | null }, fallback?: string | null): string {
  const parts = [value.address_house, value.address_street, value.city, value.state, value.country, value.pincode].filter(Boolean)
  return parts.length ? parts.join(", ") : (fallback || "No address on record")
}

export function ApplicantSection({ projectId, client, input, patch, onSaved }: {
  projectId: number
  client: Form1Client | null
  input: Form1Input
  patch: Patch
  onSaved: () => void
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState<Form1Client | null>(client)
  const [saving, setSaving] = useState(false)
  // index null = adding a new co-applicant; otherwise editing that row.
  const [coEdit, setCoEdit] = useState<{ index: number | null; row: IpoCoApplicant } | null>(null)

  async function saveApplicant() {
    if (!draft) return
    setSaving(true)
    try {
      await api.updateIpoApplicant(projectId, {
        address_house: draft.address_house, address_street: draft.address_street, city: draft.city,
        state: draft.state, country: draft.country, pincode: draft.pincode, nationality: draft.nationality,
        contact_email: draft.contact_email, phone: draft.phone,
      })
      toast.success("Applicant details saved to the client record.")
      setEditing(false)
      onSaved()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not save the applicant.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <Section title="Applicant(s)">
      {!client ? <p className="text-sm text-destructive">This case has no client.</p> : (
        <div className="space-y-2 rounded border border-border/60 p-2 text-sm">
          <div className="flex items-start justify-between gap-2">
            <div>
              <p className="font-medium">{clientName(client)} <span className="text-xs text-muted-foreground">(client, applicant 1)</span></p>
              <p className="text-xs text-muted-foreground">{addressLine(client, client.address)}</p>
              <p className="text-xs text-muted-foreground">{[client.nationality, client.contact_email, client.phone].filter(Boolean).join(" · ")}</p>
            </div>
            <Button type="button" size="sm" variant="ghost" onClick={() => { setDraft(client); setEditing((open) => !open) }}>
              <Pencil className="mr-1 h-3.5 w-3.5" />Edit
            </Button>
          </div>
          {editing && draft && (
            <div className="space-y-2 border-t border-border pt-2">
              <AddressFields value={draft} onChange={setDraft} />
              <div className="grid gap-2 sm:grid-cols-3">
                <TextField label="Nationality" value={draft.nationality} onChange={(text) => setDraft({ ...draft, nationality: text })} />
                <TextField label="Email" type="email" value={draft.contact_email} onChange={(text) => setDraft({ ...draft, contact_email: text })} />
                <TextField label="Contact number" value={draft.phone} onChange={(text) => setDraft({ ...draft, phone: text })} />
              </div>
              <Button type="button" size="sm" disabled={saving} onClick={saveApplicant}>
                {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}Save to client
              </Button>
            </div>
          )}
        </div>
      )}

      {input.co_applicants.map((co, index) => (
        <div key={index} className="flex items-start justify-between gap-2 rounded border border-border/60 p-2 text-sm">
          <div>
            <p className="font-medium">{co.name} <span className="text-xs text-muted-foreground">(co-applicant)</span></p>
            <p className="text-xs text-muted-foreground">{addressLine(co)}</p>
          </div>
          <div className="flex gap-1">
            <Button type="button" size="icon" variant="ghost" className="h-8 w-8" title="Edit co-applicant" onClick={() => setCoEdit({ index, row: co })}><Pencil className="h-3.5 w-3.5" /></Button>
            <Button type="button" size="icon" variant="ghost" className="h-8 w-8" title="Remove co-applicant" onClick={() => patch({ co_applicants: input.co_applicants.filter((_, i) => i !== index) })}><Trash2 className="h-3.5 w-3.5" /></Button>
          </div>
        </div>
      ))}

      {coEdit ? (
        <CoApplicantForm key={coEdit.index ?? "new"} value={coEdit.row} onCancel={() => setCoEdit(null)} onSave={(row) => {
          const next = [...input.co_applicants]
          if (coEdit.index === null) next.push(row)
          else next[coEdit.index] = row
          patch({ co_applicants: next })
          setCoEdit(null)
        }} />
      ) : (
        <Button type="button" size="sm" variant="outline" onClick={() => setCoEdit({ index: null, row: { name: "", country: "India", nationality: "Indian" } })}>
          <Plus className="mr-1 h-3.5 w-3.5" />Add co-applicant
        </Button>
      )}

      <Field label="Category of applicant (3B)">
        <select className={selectClass} value={input.applicant_category} onChange={(event) => patch({ applicant_category: event.target.value as IpoApplicantCategory })}>
          {CATEGORY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
      </Field>
    </Section>
  )
}

function CoApplicantForm({ value, onSave, onCancel }: { value: IpoCoApplicant; onSave: (row: IpoCoApplicant) => void; onCancel: () => void }) {
  const [row, setRow] = useState<IpoCoApplicant>(value)
  return (
    <div className="space-y-2 rounded border border-gold/40 p-2">
      <div className="grid gap-2 sm:grid-cols-3">
        <TextField label="Name in full (family name first)" value={row.name} onChange={(text) => setRow({ ...row, name: text })} />
        <TextField label="Nationality" value={row.nationality} onChange={(text) => setRow({ ...row, nationality: text })} />
        <TextField label="Country of residence" value={row.country_of_residence} onChange={(text) => setRow({ ...row, country_of_residence: text })} />
      </div>
      <label className="flex items-center gap-2 text-xs">
        <input type="checkbox" checked={!!row.natural_person} onChange={(event) => setRow({ ...row, natural_person: event.target.checked })} />
        Natural person (individual)
      </label>
      {row.natural_person && (
        <div className="grid gap-2 sm:grid-cols-2">
          <Field label="Gender (optional)">
            <select className={selectClass} value={row.gender ?? ""} onChange={(event) => setRow({ ...row, gender: (event.target.value || null) as IpoGender | null })}>
              <option value="">-</option>
              {GENDERS.map((gender) => <option key={gender} value={gender}>{gender}</option>)}
            </select>
          </Field>
          <TextField label="Age (optional)" type="number" value={row.age} onChange={(text) => setRow({ ...row, age: text ? Number(text) : null })} />
        </div>
      )}
      <AddressFields value={row} onChange={setRow} />
      <div className="grid gap-2 sm:grid-cols-2">
        <TextField label="Email" type="email" value={row.email} onChange={(text) => setRow({ ...row, email: text })} />
        <TextField label="Contact number" value={row.phone} onChange={(text) => setRow({ ...row, phone: text })} />
      </div>
      <div className="flex gap-2">
        <Button type="button" size="sm" disabled={!row.name.trim()} onClick={() => onSave(row)}>Done</Button>
        <Button type="button" size="sm" variant="outline" onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  )
}
