/**
 * Service codes are tagged to the patent offices (country codes) they apply to
 * (Settings → Codes → Service Codes). An empty list means every country.
 */
export interface CodeItem {
  code: string
  label: string
  /** Service codes only: patent office codes such as "IN", "US"; empty or missing = every country. */
  countries?: string[]
}

export const isAllCountries = (item: CodeItem) => !item.countries || item.countries.length === 0

/**
 * The service codes offered for a case filed at `office`: the codes tagged to it plus the
 * every-country ones. `keep` stays listed (the code already on an older case) even when
 * it is not tagged to that office. With no office chosen, every code is offered.
 */
export function codesForOffice<T extends CodeItem>(items: T[], office: string | null | undefined, keep?: string | null): T[] {
  const target = (office ?? "").trim().toUpperCase()
  if (!target) return items
  const kept = (keep ?? "").trim().toUpperCase()
  return items.filter((item) =>
    isAllCountries(item)
    || (item.countries ?? []).some((country) => country.toUpperCase() === target)
    || (kept !== "" && item.code.toUpperCase() === kept))
}

/** Country codes used in the tags, India and the US first, then alphabetical. */
export function taggedCountries(items: CodeItem[]): string[] {
  const set = new Set<string>()
  items.forEach((item) => (item.countries ?? []).forEach((country) => set.add(country.toUpperCase())))
  const order = ["IN", "US"]
  return [...set].sort((a, b) => {
    const ia = order.indexOf(a), ib = order.indexOf(b)
    if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
    return a.localeCompare(b)
  })
}
