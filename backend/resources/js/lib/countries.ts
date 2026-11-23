import { useEffect, useState } from "react"

export interface CountryStates {
  name: string
  states: string[]
}

let cache: CountryStates[] | null = null
let pending: Promise<CountryStates[]> | null = null

/** Every country with its states (resources/js/data/country-states.json), loaded as a separate chunk on first use. */
export function loadCountries(): Promise<CountryStates[]> {
  if (cache) return Promise.resolve(cache)
  pending ??= import("@/data/country-states.json").then((module) => {
    cache = module.default as CountryStates[]
    return cache
  })
  return pending
}

export function useCountries(): CountryStates[] {
  const [countries, setCountries] = useState<CountryStates[]>(cache ?? [])
  useEffect(() => {
    let live = true
    if (!cache) loadCountries().then((list) => { if (live) setCountries(list) }).catch(() => {})
    return () => { live = false }
  }, [])
  return countries
}

/** Older client records stored short nationality labels; map them to the country list's names. */
const LEGACY_NAMES: Record<string, string> = {
  usa: "United States", us: "United States", "u.s.a.": "United States", america: "United States",
  uk: "United Kingdom", "u.k.": "United Kingdom", england: "United Kingdom", "great britain": "United Kingdom",
  uae: "United Arab Emirates", korea: "South Korea", "republic of korea": "South Korea",
  indian: "India", in: "India",
}

/** The country-list name for a stored value, or "" when it isn't a known country (e.g. "Other (Foreign)"). */
export function normaliseCountry(value: string | null | undefined, countries: CountryStates[]): string {
  const raw = (value ?? "").trim()
  if (!raw) return ""
  const key = raw.toLowerCase()
  const mapped = LEGACY_NAMES[key] ?? raw
  return countries.find((c) => c.name.toLowerCase() === mapped.toLowerCase())?.name ?? (countries.length ? "" : mapped)
}

export function isIndia(country: string | null | undefined): boolean {
  return ["india", "in", "indian"].includes((country ?? "").trim().toLowerCase())
}
