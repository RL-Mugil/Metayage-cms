import { useId, useState } from "react"
import { Cell, Pie, PieChart, ResponsiveContainer, Sector, Tooltip } from "recharts"
import { AnimatedNumber, type NumberFormat } from "./animated-number"

export interface RingDatum { code: string; name: string; value: number; color: string }

/**
 * A donut that reads as a bevelled 3D ring: each slice has a light-to-dark radial gradient,
 * the ring sits on a soft drop shadow over an inset track, the hovered or selected slice
 * lifts out, and the centre total counts up. Clicking a slice selects it (click again clears).
 */
export function Ring3D({ data, total, label, format = "number", activeCode, onSelect, size = 168, thickness = 22, showLegend = true }: {
  data: RingDatum[]
  total: number
  label: string
  format?: NumberFormat
  activeCode?: string | null
  onSelect?: (code: string | null) => void
  size?: number
  thickness?: number
  showLegend?: boolean
}) {
  const uid = useId().replace(/:/g, "")
  const [hover, setHover] = useState<number | null>(null)
  const outer = size / 2 - 8
  const inner = outer - thickness
  const selectedIndex = data.findIndex((d) => d.code === activeCode)
  const lifted = hover ?? (selectedIndex >= 0 ? selectedIndex : null)
  const rows = data.filter((d) => d.value > 0)

  return (
    <div className="flex w-full items-center gap-3">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        {/* inset track under the ring */}
        <div aria-hidden className="absolute rounded-full" style={{ inset: 8, boxShadow: "inset 0 3px 8px rgb(15 23 42 / 0.16), inset 0 -2px 4px rgb(255 255 255 / 0.6)", background: "color-mix(in oklab, var(--muted) 80%, transparent)" }} />
        <div aria-hidden className="absolute rounded-full bg-background" style={{ inset: 8 + thickness, boxShadow: "0 2px 6px rgb(15 23 42 / 0.10)" }} />
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <defs>
              {data.map((d, i) => (
                <radialGradient key={i} id={`${uid}-g${i}`} cx="50%" cy="50%" r="50%">
                  <stop offset={`${(inner / outer) * 100}%`} stopColor={d.color} stopOpacity={0.75} />
                  <stop offset="78%" stopColor={d.color} stopOpacity={1} />
                  <stop offset="100%" stopColor={d.color} stopOpacity={0.82} />
                </radialGradient>
              ))}
              <filter id={`${uid}-shadow`} x="-20%" y="-20%" width="140%" height="140%">
                <feDropShadow dx="0" dy="3" stdDeviation="3" floodColor="#0f172a" floodOpacity="0.22" />
              </filter>
            </defs>
            <Pie data={rows.length ? data : [{ code: "_", name: "None", value: 1, color: "#e5e7eb" }]} dataKey="value" nameKey="name"
              innerRadius={inner} outerRadius={outer} paddingAngle={rows.length > 1 ? 2 : 0} cornerRadius={4} stroke="none"
              isAnimationActive animationDuration={900} startAngle={90} endAngle={-270} style={{ filter: `url(#${uid}-shadow)` }}
              activeIndex={lifted ?? undefined}
              activeShape={(props: any) => <Sector {...props} outerRadius={props.outerRadius + 6} innerRadius={props.innerRadius - 1} />}
              onMouseEnter={(_, i) => setHover(i)} onMouseLeave={() => setHover(null)}
              onClick={(d: any) => onSelect && rows.length && onSelect(d.code === activeCode ? null : d.code)}>
              {(rows.length ? data : [{ color: "#e5e7eb" }]).map((d, i) => (
                <Cell key={i} fill={rows.length ? `url(#${uid}-g${i})` : (d as RingDatum).color} className={onSelect ? "cursor-pointer outline-none" : "outline-none"}
                  opacity={activeCode && rows.length && (d as RingDatum).code !== activeCode ? 0.45 : 1} />
              ))}
            </Pie>
            <Tooltip formatter={(v: number, n: string) => [Number(v).toLocaleString("en-IN"), n]} contentStyle={{ fontSize: 12, borderRadius: 10 }} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <AnimatedNumber value={total} format={format} className="font-display text-2xl font-semibold leading-none tracking-tight" />
          <span className="mt-1 max-w-[70%] text-center text-[10px] uppercase leading-tight tracking-wider text-muted-foreground">{label}</span>
        </div>
      </div>
      {showLegend && rows.length > 0 && (
        <ul className="thin-scrollbar min-w-0 flex-1 space-y-0.5 overflow-y-auto" style={{ maxHeight: size }}>
          {rows.map((d) => (
            <li key={d.code}>
              <button onClick={() => onSelect?.(d.code === activeCode ? null : d.code)} disabled={!onSelect}
                className={`flex w-full items-center justify-between gap-2 rounded-md px-1.5 py-0.5 text-[11px] transition-colors ${d.code === activeCode ? "bg-gold/15" : "hover:bg-muted/60"}`}>
                <span className="flex min-w-0 items-center gap-1.5"><span className="h-2 w-2 shrink-0 rounded-full" style={{ background: d.color, boxShadow: `0 0 6px ${d.color}` }} /><span className="truncate">{d.name}</span></span>
                <span className="font-mono text-muted-foreground">{d.value.toLocaleString("en-IN")}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
