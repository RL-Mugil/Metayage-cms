/**
 * recharts bar shape drawn as an isometric block: front face, a darker right side and a
 * lighter top, so bars read as 3D columns. Use as <Bar shape={<Bar3DShape />} />.
 */
export function Bar3DShape(props: { x?: number; y?: number; width?: number; height?: number; fill?: string }) {
  const { x = 0, y = 0, width = 0, height = 0, fill = "#3b82f6" } = props
  if (height <= 0 || width <= 0) return null
  const d = Math.min(7, width * 0.35)   // depth of the side/top faces
  const w = width - d
  return (
    <g>
      <rect x={x} y={y} width={w} height={height} fill={fill} rx={1.5} />
      <rect x={x} y={y} width={w} height={height} fill="url(#bar3d-sheen)" rx={1.5} />
      <path d={`M${x + w},${y} l${d},${-d} v${height} l${-d},${d} z`} fill={fill} style={{ filter: "brightness(0.72)" }} />
      <path d={`M${x},${y} l${d},${-d} h${w} l${-d},${d} z`} fill={fill} style={{ filter: "brightness(1.25)" }} />
    </g>
  )
}

/** Shared gradient used by Bar3DShape; render once inside the chart's <defs>. */
export function Bar3DDefs() {
  return (
    <defs>
      <linearGradient id="bar3d-sheen" x1="0" y1="0" x2="1" y2="0">
        <stop offset="0%" stopColor="#fff" stopOpacity={0.22} />
        <stop offset="45%" stopColor="#fff" stopOpacity={0.04} />
        <stop offset="100%" stopColor="#000" stopOpacity={0.08} />
      </linearGradient>
    </defs>
  )
}

/** A horizontal 3D bar for lists (e.g. pipeline stages): clickable, animated width. */
export function HBar3D({ label, value, max, color = "#d4a017", active, onClick, right }: {
  label: string; value: number; max: number; color?: string; active?: boolean; onClick?: () => void; right?: React.ReactNode
}) {
  const pct = max > 0 ? Math.max(3, (value / max) * 100) : 0
  return (
    <button onClick={onClick} className={`group block w-full rounded-lg px-2 py-1 text-left transition-colors ${active ? "bg-gold/15" : "hover:bg-muted/50"}`}>
      <div className="flex items-center justify-between gap-2 text-[11px]">
        <span className="truncate">{label}</span>
        <span className="shrink-0 font-mono text-muted-foreground">{right ?? value}</span>
      </div>
      <div className="relative mt-1 h-2.5 w-full rounded-full bg-muted/70" style={{ boxShadow: "inset 0 1px 2px rgb(15 23 42 / 0.12)" }}>
        <div className="absolute inset-y-0 left-0 rounded-full transition-[width] duration-700 ease-out"
          style={{ width: `${pct}%`, background: `linear-gradient(180deg, color-mix(in oklab, ${color} 70%, white) 0%, ${color} 55%, color-mix(in oklab, ${color} 75%, black) 100%)`, boxShadow: `0 2px 6px -1px ${color}88` }} />
      </div>
    </button>
  )
}
