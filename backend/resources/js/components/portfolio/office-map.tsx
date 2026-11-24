import { Globe2 } from "lucide-react"

/** Rough world placement of patent offices on a 12 × 6 tile grid (column, row). */
const PLACES: Record<string, [number, number]> = {
  CA: [1, 0], GB: [5, 0], EP: [6, 0], RU: [8, 0],
  US: [1, 1], FR: [5, 1], DE: [6, 1], EA: [7, 1], CN: [9, 1], KR: [10, 1], JP: [11, 1],
  MX: [1, 2], IL: [6, 2], AE: [7, 2], IN: [8, 2], TW: [10, 2],
  BR: [3, 3], SA: [7, 3], TH: [9, 3], VN: [10, 3], PH: [11, 3],
  ZA: [6, 4], MY: [9, 4], SG: [8, 4], ID: [10, 4],
  AU: [10, 5], NZ: [11, 5],
}

type Counts = Record<string, number>

/**
 * Where the filings are: one tile per office, shaded by number of cases, split into
 * active / granted on hover. WO (PCT) sits apart since it is not a country. Clicking a
 * tile filters the page to that office (same as clicking a donut slice).
 */
export function OfficeMap({ active, granted, other, label, selected, onSelect }: {
  active: Counts; granted: Counts; other: Counts
  label: (code: string) => string
  selected: string | null
  onSelect: (code: string) => void
}) {
  const codes = Array.from(new Set([...Object.keys(active), ...Object.keys(granted), ...Object.keys(other)])).filter(Boolean)
  const total = (c: string) => (active[c] ?? 0) + (granted[c] ?? 0) + (other[c] ?? 0)
  const max = Math.max(1, ...codes.map(total))
  const placed = codes.filter((c) => PLACES[c])
  const unplaced = codes.filter((c) => !PLACES[c] && c !== "WO")

  const Tile = ({ code, style }: { code: string; style?: React.CSSProperties }) => {
    const n = total(code)
    const tip = `${label(code)}: ${n} case${n === 1 ? "" : "s"} · ${active[code] ?? 0} active · ${granted[code] ?? 0} granted`
    return (
      <button title={tip} onClick={() => onSelect(code)} style={{ ...style, backgroundColor: `hsl(45 93% 47% / ${0.12 + (n / max) * 0.7})` }}
        className={`flex flex-col items-center justify-center rounded-md border text-[10px] leading-tight transition-transform hover:scale-105 ${selected === code ? "border-foreground ring-2 ring-gold" : "border-gold/30"}`}>
        <span className="font-mono font-semibold">{code}</span>
        <span className="text-foreground/80">{n}</span>
      </button>
    )
  }

  if (codes.length === 0) return null
  return (
    <div>
      <div className="mb-2 flex items-center gap-2 text-xs text-muted-foreground"><Globe2 className="h-3.5 w-3.5 text-gold" /> Darker = more cases. Click an office to filter.</div>
      <div className="relative grid gap-1" style={{ gridTemplateColumns: "repeat(12, minmax(0, 1fr))", gridTemplateRows: "repeat(6, 38px)" }}>
        {Array.from({ length: 72 }, (_, i) => <div key={i} className="rounded-sm bg-muted/20" style={{ gridColumn: (i % 12) + 1, gridRow: Math.floor(i / 12) + 1 }} />)}
        {placed.map((c) => <Tile key={c} code={c} style={{ gridColumn: PLACES[c][0] + 1, gridRow: PLACES[c][1] + 1 }} />)}
      </div>
      {(codes.includes("WO") || unplaced.length > 0) && (
        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          {codes.includes("WO") && <div className="h-10 w-24"><Tile code="WO" style={{ width: "100%", height: "100%" }} /></div>}
          {unplaced.map((c) => <div key={c} className="h-10 w-14"><Tile code={c} style={{ width: "100%", height: "100%" }} /></div>)}
          {codes.includes("WO") && <span className="text-[10px] text-muted-foreground">WO = international (PCT) applications</span>}
        </div>
      )}
    </div>
  )
}
