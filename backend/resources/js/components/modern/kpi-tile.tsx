import type { LucideIcon } from "lucide-react"
import { ArrowDownRight, ArrowUpRight } from "lucide-react"
import { motion, useReducedMotion } from "framer-motion"
import { Area, AreaChart, ResponsiveContainer } from "recharts"
import { useId } from "react"
import { cn } from "@/lib/utils"
import { AnimatedNumber, type NumberFormat } from "./animated-number"
import { tileMotion } from "./glass-card"

export const ACCENTS = {
  blue: { from: "#3b82f6", to: "#6366f1", soft: "rgb(59 130 246 / 0.12)" },
  gold: { from: "#d4a017", to: "#f59e0b", soft: "rgb(212 160 23 / 0.14)" },
  emerald: { from: "#10b981", to: "#14b8a6", soft: "rgb(16 185 129 / 0.12)" },
  violet: { from: "#8b5cf6", to: "#a855f7", soft: "rgb(139 92 246 / 0.12)" },
  rose: { from: "#f43f5e", to: "#fb7185", soft: "rgb(244 63 94 / 0.12)" },
  slate: { from: "#64748b", to: "#94a3b8", soft: "rgb(100 116 139 / 0.12)" },
} as const
export type Accent = keyof typeof ACCENTS

/**
 * KPI tile: gradient icon chip, counting value, delta chip, subtitle and a gradient area
 * sparkline along the bottom. Clickable tiles lift on hover.
 */
export function KpiTile({ label, value, format = "number", icon: Icon, accent = "blue", delta, trend, subtitle, spark, onClick, className }: {
  label: string
  value: number
  format?: NumberFormat
  icon: LucideIcon
  accent?: Accent
  delta?: string | null
  trend?: "up" | "down" | "flat" | "neutral" | null
  subtitle?: string | null
  spark?: number[] | null
  onClick?: () => void
  className?: string
}) {
  const a = ACCENTS[accent]
  const uid = useId().replace(/:/g, "")
  const reduce = useReducedMotion()
  const points = (spark ?? []).map((v, i) => ({ i, v }))
  const showSpark = points.length > 1 && points.some((p) => p.v !== 0)

  return (
    <motion.div variants={tileMotion} whileHover={onClick && !reduce ? { y: -3 } : undefined} whileTap={onClick && !reduce ? { scale: 0.985 } : undefined}
      onClick={onClick} role={onClick ? "button" : undefined} tabIndex={onClick ? 0 : undefined}
      onKeyDown={onClick ? (e) => { if (e.key === "Enter" || e.key === " ") onClick() } : undefined}
      className={cn("glass group relative flex min-h-[104px] flex-col overflow-hidden rounded-2xl p-3.5", onClick && "cursor-pointer", className)}>
      <div aria-hidden className="pointer-events-none absolute -right-8 -top-10 h-28 w-28 rounded-full blur-2xl transition-opacity group-hover:opacity-100" style={{ background: a.soft, opacity: 0.8 }} />
      <div className="relative flex items-start justify-between gap-2">
        <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">{label}</span>
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl text-white shadow-3d"
          style={{ background: `linear-gradient(135deg, ${a.from}, ${a.to})`, boxShadow: `0 6px 14px -6px ${a.from}` }}>
          <Icon className="h-4 w-4" />
        </span>
      </div>
      <AnimatedNumber value={value} format={format} className="relative mt-1 font-display text-[26px] font-semibold leading-tight tracking-tight" />
      <div className="relative flex min-h-[18px] flex-wrap items-center gap-1.5">
        {delta && (
          <span className={cn("inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold",
            trend === "down" ? "bg-rose-500/10 text-rose-600" : trend === "up" ? "bg-emerald-500/10 text-emerald-600" : "bg-muted text-muted-foreground")}>
            {trend === "down" ? <ArrowDownRight className="h-3 w-3" /> : trend === "up" ? <ArrowUpRight className="h-3 w-3" /> : null}{delta}
          </span>
        )}
        {subtitle && <span className="truncate text-[10px] text-muted-foreground">{subtitle}</span>}
      </div>
      {showSpark && (
        <div className="pointer-events-none absolute inset-x-0 bottom-0 h-9 opacity-80">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={points} margin={{ top: 2, right: 0, left: 0, bottom: 0 }}>
              <defs>
                <linearGradient id={`${uid}-a`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={a.from} stopOpacity={0.35} />
                  <stop offset="100%" stopColor={a.from} stopOpacity={0} />
                </linearGradient>
              </defs>
              <Area type="monotone" dataKey="v" stroke={a.from} strokeWidth={1.75} fill={`url(#${uid}-a)`} isAnimationActive={!reduce} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}
    </motion.div>
  )
}
