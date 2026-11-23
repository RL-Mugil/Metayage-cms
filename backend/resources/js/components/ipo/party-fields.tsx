import type { ReactNode } from "react"
import { Input } from "@/components/ui/input"
import type { IpoAddress, IpoGender } from "@/types/ipo-forms"

export const INDIAN_STATES = [
  "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar", "Chhattisgarh", "Goa",
  "Gujarat", "Haryana", "Himachal Pradesh", "Jharkhand", "Karnataka", "Kerala",
  "Madhya Pradesh", "Maharashtra", "Manipur", "Meghalaya", "Mizoram", "Nagaland",
  "Odisha", "Punjab", "Rajasthan", "Sikkim", "Tamil Nadu", "Telangana", "Tripura",
  "Uttar Pradesh", "Uttarakhand", "West Bengal",
  "Andaman & Nicobar Islands", "Chandigarh", "Dadra & Nagar Haveli", "Daman & Diu",
  "Delhi", "Jammu & Kashmir", "Ladakh", "Lakshadweep", "Puducherry",
]

export const GENDERS: IpoGender[] = ["Male", "Female", "Others", "Prefer not to disclose"]

export const selectClass = "h-9 w-full rounded-md border border-input bg-background px-2 text-sm"

export function Field({ label, children, className = "" }: { label: string; children: ReactNode; className?: string }) {
  return (
    <label className={`space-y-1 text-xs text-muted-foreground ${className}`}>
      <span>{label}</span>
      {children}
    </label>
  )
}

export function TextField({ label, value, onChange, type = "text", className, placeholder }: {
  label: string
  value: string | number | null | undefined
  onChange: (value: string) => void
  type?: string
  className?: string
  placeholder?: string
}) {
  return (
    <Field label={label} className={className}>
      <Input type={type} value={value ?? ""} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} className="h-9 text-sm text-foreground" />
    </Field>
  )
}

/** House no. / street / city / state / country / pin code, shared by applicants and inventors. */
export function AddressFields<T extends IpoAddress>({ value, onChange, required = false }: {
  value: T
  onChange: (next: T) => void
  /** Star the fields Form 1 needs (inventors): house or street, city, country, pin code, state for India. */
  required?: boolean
}) {
  const set = (key: keyof IpoAddress) => (text: string) => onChange({ ...value, [key]: text })
  const listId = "ipo-indian-states"
  const star = required ? " *" : ""
  const stateStar = required && isIndianCountry(value.country) ? " *" : ""
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      <TextField label={`House no. / building${required ? " (or street) *" : ""}`} value={value.address_house} onChange={set("address_house")} />
      <TextField label={`Street / area${required ? " (or house no.) *" : ""}`} value={value.address_street} onChange={set("address_street")} />
      <TextField label={`City${star}`} value={value.city} onChange={set("city")} />
      <Field label={`State${stateStar}`}>
        <Input list={listId} value={value.state ?? ""} onChange={(event) => set("state")(event.target.value)} className="h-9 text-sm text-foreground" />
        <datalist id={listId}>{INDIAN_STATES.map((state) => <option key={state} value={state} />)}</datalist>
      </Field>
      <TextField label={`Country${star}`} value={value.country} onChange={set("country")} />
      <TextField label={`Pin code${star}`} value={value.pincode} onChange={set("pincode")} />
    </div>
  )
}

export function isIndianCountry(country: string | null | undefined): boolean {
  return ["india", "in"].includes((country ?? "").trim().toLowerCase())
}
