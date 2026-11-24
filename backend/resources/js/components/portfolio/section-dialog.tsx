import { useMemo, useState, type ReactNode } from "react"
import { ArrowUpDown, ChevronDown, ChevronUp, Search, X } from "lucide-react"

export interface SectionColumn<T> {
  key: string
  label: string
  /** Cell content; defaults to the row's value at `key`. */
  render?: (row: T) => ReactNode
  /** Value used for sorting and search; defaults to the row's value at `key`. */
  value?: (row: T) => string | number | null | undefined
  align?: "left" | "right"
}

const PAGE = 25

/**
 * "View all" for a Patent Portfolio section: every row in a large dialog with search,
 * sortable columns and paging. Clicking a row calls onRowClick (e.g. go to the action).
 */
export function SectionDialog<T>({ title, rows, columns, onRowClick, onClose, initialSort, empty = "Nothing here." }: {
  title: string
  rows: T[]
  columns: SectionColumn<T>[]
  onRowClick?: (row: T) => void
  onClose: () => void
  initialSort?: { key: string; dir: "asc" | "desc" }
  empty?: string
}) {
  const [search, setSearch] = useState("")
  const [sort, setSort] = useState(initialSort ?? { key: columns[0]?.key ?? "", dir: "asc" as "asc" | "desc" })
  const [page, setPage] = useState(1)

  const valueOf = (row: T, column: SectionColumn<T>) => column.value ? column.value(row) : (row as Record<string, unknown>)[column.key] as string | number | null | undefined

  const shown = useMemo(() => {
    const q = search.trim().toLowerCase()
    let list = q ? rows.filter((row) => columns.some((c) => String(valueOf(row, c) ?? "").toLowerCase().includes(q))) : rows
    const column = columns.find((c) => c.key === sort.key)
    if (column) {
      list = [...list].sort((a, b) => {
        const av = valueOf(a, column); const bv = valueOf(b, column)
        if (av == null && bv == null) return 0
        if (av == null) return 1
        if (bv == null) return -1
        const cmp = typeof av === "number" && typeof bv === "number" ? av - bv : String(av).localeCompare(String(bv), undefined, { numeric: true })
        return sort.dir === "asc" ? cmp : -cmp
      })
    }
    return list
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows, search, sort])

  const pages = Math.max(1, Math.ceil(shown.length / PAGE))
  const current = Math.min(page, pages)
  const pageRows = shown.slice((current - 1) * PAGE, current * PAGE)

  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 backdrop-blur-sm md:p-8" onClick={onClose}>
      <div className="flex h-full w-full flex-col overflow-hidden rounded-2xl border border-border bg-background shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-6 py-4">
          <div>
            <h2 className="font-display text-lg font-semibold">{title}</h2>
            <p className="text-xs text-muted-foreground">{shown.length} of {rows.length}</p>
          </div>
          <div className="flex items-center gap-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <input autoFocus value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} placeholder="Search…"
                className="h-9 w-64 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
            </div>
            <button onClick={onClose} className="rounded-md p-2 hover:bg-muted" title="Close"><X className="h-5 w-5 text-muted-foreground" /></button>
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {pageRows.length === 0 ? (
            <p className="py-16 text-center text-sm text-muted-foreground">{search ? "No matches." : empty}</p>
          ) : (
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/70 text-xs uppercase tracking-wider text-muted-foreground backdrop-blur">
                <tr>
                  {columns.map((c) => (
                    <th key={c.key} className={`px-4 py-3 ${c.align === "right" ? "text-right" : "text-left"}`}>
                      <button className="inline-flex items-center gap-1 hover:text-foreground"
                        onClick={() => setSort((s) => ({ key: c.key, dir: s.key === c.key && s.dir === "asc" ? "desc" : "asc" }))}>
                        {c.label}
                        {sort.key === c.key ? (sort.dir === "asc" ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />) : <ArrowUpDown className="h-3 w-3 opacity-40" />}
                      </button>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {pageRows.map((row, i) => (
                  <tr key={i} onClick={() => onRowClick?.(row)}
                    className={`border-t border-border ${onRowClick ? "cursor-pointer hover:bg-muted/30" : ""}`}>
                    {columns.map((c) => (
                      <td key={c.key} className={`px-4 py-2.5 ${c.align === "right" ? "text-right" : ""}`}>
                        {c.render ? c.render(row) : String(valueOf(row, c) ?? "—")}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
        {pages > 1 && (
          <div className="flex items-center justify-between border-t border-border px-6 py-3 text-sm text-muted-foreground">
            <span>{(current - 1) * PAGE + 1}–{Math.min(current * PAGE, shown.length)} of {shown.length}</span>
            <div className="flex gap-2">
              <button disabled={current <= 1} onClick={() => setPage(current - 1)} className="rounded border border-border px-3 py-1 hover:bg-muted disabled:opacity-40">Previous</button>
              <button disabled={current >= pages} onClick={() => setPage(current + 1)} className="rounded border border-border px-3 py-1 hover:bg-muted disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
