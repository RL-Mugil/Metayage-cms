import { lazy, Suspense, useMemo } from "react"
import { useReducedMotion } from "framer-motion"
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { Bar3DShape, Bar3DDefs } from "@/components/modern/bar-3d"
import { Ring3D } from "@/components/modern/ring-3d"

export interface Series3D { key: string; label: string; color: string }
export interface Datum3D { label: string; values: Record<string, number> }

export interface Bars3DProps {
  data: Datum3D[]
  series: Series3D[]
  format?: (n: number) => string
  /** A column was clicked: its category label and series key. */
  onSelect?: (label: string, seriesKey: string) => void
  height?: number
  activeLabel?: string | null
}

export interface RingSlice3D { code: string; name: string; value: number; color: string }
export interface Ring3DProps {
  data: RingSlice3D[]
  total: number
  label: string
  format?: (n: number) => string
  activeCode?: string | null
  onSelect?: (code: string | null) => void
  size?: number
}

// The WebGL charts load only when a page shows one (three.js stays out of the main bundle).
const Bars3DGL = lazy(() => import("./bars-3d-gl"))
const Ring3DGL = lazy(() => import("./ring-3d-gl"))

let webgl: boolean | null = null
function hasWebGL(): boolean {
  if (webgl !== null) return webgl
  try {
    const cv = document.createElement("canvas")
    webgl = !!(cv.getContext("webgl2") || cv.getContext("webgl"))
  } catch {
    webgl = false
  }
  return webgl
}

export const inrShort = (n: number) => {
  const a = Math.abs(n)
  const sign = n < 0 ? "-" : ""
  if (a >= 1e7) return `${sign}₹${(a / 1e7).toFixed(2)} Cr`
  if (a >= 1e5) return `${sign}₹${(a / 1e5).toFixed(2)} L`
  return `${sign}₹${Math.round(a).toLocaleString("en-IN")}`
}

function Placeholder({ height }: { height: number }) {
  return <div className="w-full animate-pulse rounded-xl border border-border/60 bg-muted/30 shadow-3d" style={{ height }} />
}

/**
 * 3D column chart: WebGL (turnable, clickable) where the browser supports it; otherwise, or for
 * reduced-motion users, isometric SVG columns with the same click behaviour.
 */
export function Bars3D(props: Bars3DProps) {
  const reduce = useReducedMotion()
  const height = props.height ?? 300
  const gl = typeof window !== "undefined" && !reduce && hasWebGL()
  const flat = useMemo(() => props.data.map((d) => ({ label: d.label, ...d.values })), [props.data])
  if (props.data.length === 0) {
    return <div className="flex items-center justify-center rounded-xl border border-dashed border-border text-xs text-muted-foreground" style={{ height }}>No data for this view.</div>
  }
  if (gl) {
    return <Suspense fallback={<Placeholder height={height} />}><Bars3DGL {...props} height={height} /></Suspense>
  }
  const fmt = props.format ?? ((n: number) => n.toLocaleString("en-IN"))
  return (
    <div className="rounded-xl border border-border/60 p-2 shadow-3d" style={{ height }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={flat} onClick={(e: any) => e?.activeLabel && props.onSelect?.(String(e.activeLabel), props.series[0]?.key ?? "")}>
          <Bar3DDefs />
          <CartesianGrid strokeDasharray="3 3" opacity={0.4} />
          <XAxis dataKey="label" tick={{ fontSize: 11 }} />
          <YAxis tickFormatter={(v) => fmt(Number(v))} tick={{ fontSize: 10 }} width={70} />
          <Tooltip formatter={(v: number) => fmt(v)} />
          {props.series.map((s) => <Bar key={s.key} dataKey={s.key} name={s.label} fill={s.color} shape={<Bar3DShape />} />)}
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** 3D donut: WebGL extruded ring where supported; otherwise the bevelled SVG ring. */
export function Donut3D(props: Ring3DProps) {
  const reduce = useReducedMotion()
  const gl = typeof window !== "undefined" && !reduce && hasWebGL()
  if (gl) {
    return <Suspense fallback={<Placeholder height={props.size ?? 220} />}><Ring3DGL {...props} /></Suspense>
  }
  return <Ring3D data={props.data} total={props.total} label={props.label} activeCode={props.activeCode} onSelect={props.onSelect} size={props.size ?? 180} />
}
