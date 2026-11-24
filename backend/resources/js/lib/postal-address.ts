/**
 * Client address in the IPO-form parts (house, street, city, state, pincode). Mirrors
 * App\Support\PostalAddress on the server: compose() builds the one-line address;
 * splitAddress() is a best-effort parse of an old one-line address (or a GSTIN/CIN
 * fetched address) into the parts. Keep the two in step.
 */
export interface AddressParts {
  address_house: string
  address_street: string
  city: string
  state: string
  pincode: string
}

const STREET_WORDS = /\b(road|rd|street|st\.|lane|ln|cross|main|floor|flr|block|sector|phase|layout|stage|plot|no\.?|door|flat|building|bldg|tower|apartment|apartments|complex|opp\.?|near|behind|post|p\.?o\.?|village|taluk)\b/i

const INDIAN_STATES = [
  "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar", "Chhattisgarh", "Goa", "Gujarat", "Haryana", "Himachal Pradesh",
  "Jharkhand", "Karnataka", "Kerala", "Madhya Pradesh", "Maharashtra", "Manipur", "Meghalaya", "Mizoram", "Nagaland", "Odisha",
  "Punjab", "Rajasthan", "Sikkim", "Tamil Nadu", "Telangana", "Tripura", "Uttar Pradesh", "Uttarakhand", "West Bengal",
  "Andaman and Nicobar Islands", "Chandigarh", "Dadra and Nagar Haveli and Daman and Diu", "Delhi", "Jammu and Kashmir",
  "Ladakh", "Lakshadweep", "Puducherry", "New Delhi", "Orissa", "Pondicherry",
]

export const isIndiaCountry = (country?: string | null) => ["", "india", "in", "ind", "bharat"].includes((country ?? "").trim().toLowerCase())

export function composeAddress(p: Partial<AddressParts> & { country?: string }): string {
  const line = [p.address_house, p.address_street, p.city, p.state, p.country].map((v) => (v ?? "").trim()).filter(Boolean).join(", ")
  const pin = (p.pincode ?? "").trim()
  return pin ? (line ? `${line} - ${pin}` : pin) : line
}

export function splitAddress(text: string | null | undefined, state = "", country = "India"): AddressParts {
  const out: AddressParts = { address_house: "", address_street: "", city: "", state: state.trim(), pincode: "" }
  let t = (text ?? "").replace(/\r\n|\n|\r|;/g, ", ").replace(/\s+/g, " ").trim()
  if (!t) return out
  const india = isIndiaCountry(country)

  if (india) {
    const matches = [...t.matchAll(/(?<!\d)(\d{3})\s?(\d{3})(?!\d)/g)]
    const last = matches[matches.length - 1]
    if (last && last.index !== undefined) {
      out.pincode = last[1] + last[2]
      t = t.slice(0, last.index) + "," + t.slice(last.index + last[0].length)
    }
  } else {
    // Numeric ZIP / postcode (incl. ZIP+4), UK postcode, Canadian postal code — at the end.
    for (const re of [/[\s,](\d{3,6}(?:-\d{3,4})?)\s*$/, /[\s,]([A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2})\s*$/i, /[\s,]([A-Z]\d[A-Z]\s?\d[A-Z]\d)\s*$/i]) {
      const m = t.match(re)
      if (m) { out.pincode = m[1].trim().toUpperCase(); t = t.slice(0, -m[0].length); break }
    }
  }

  const clean = (p: string) => p.trim().replace(/^[-–—.:\s]+|[-–—.:\s]+$/g, "").replace(/\b(pin(code)?|pin code|zip)\s*[:-]?\s*$/i, "").trim()
  let parts = t.split(",").map(clean).filter(Boolean)
  const countryName = (country ?? "").trim().toLowerCase()
  parts = parts.filter((p) => {
    const l = p.toLowerCase()
    if (india ? ["india", "bharat"].includes(l) : l === countryName) return false
    if (india) {
      const st = INDIAN_STATES.find((s) => s.toLowerCase() === l)
      if (st) { out.state = out.state || st; return false }
    } else if (out.state && l === out.state.toLowerCase()) return false
    return true
  })

  if (parts.length) {
    const candidate = parts[parts.length - 1].replace(/\s*(dist(rict)?\.?|urban|rural)$/i, "").trim()
    if (candidate && !/\d/.test(candidate) && !STREET_WORDS.test(candidate) && candidate.length <= 40) {
      out.city = candidate
      parts.pop()
    }
  }
  if (parts.length > 1 && /^(no\.?|#|flat|door|plot|h\.?\s?no\.?|\d)/i.test(parts[0])) {
    let house = parts.shift() as string
    if (house.length <= 6 && parts.length > 1) house += ", " + parts.shift()
    out.address_house = house
  }
  out.address_street = parts.join(", ")
  return out
}
