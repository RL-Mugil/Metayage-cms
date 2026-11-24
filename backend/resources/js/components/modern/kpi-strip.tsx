import { motion, useReducedMotion } from "framer-motion"
import { cn } from "@/lib/utils"
import { AnimatedNumber, type NumberFormat } from "./animated-number"

export interface KpiChip {
  key: string
  label: string
  value: number
  /** CSS colour for the dot, glow and number. */
  color: string
  format?: NumberFormat
  hint?: string
  onClick?: () => void
  active?: boolean
}

/**
 * One compact row of KPI chips (about 56px tall) in place of a tall card grid: coloured
 * dot with glow, counting number, label, click to drill. Scrolls sideways when there are
 * more chips than fit.
 */
export function KpiStrip({ items, className }: { items: KpiChip[]; className?: string }) {
  const reduce = useReducedMotion()
  return (
    <div className={cn("no-scrollbar -mx-1 flex shrink-0 gap-2 overflow-x-auto px-1 py-1", className)}>
      {items.map((k, i) => (
        <motion.button key={k.key} type="button" onClick={k.onClick} title={k.hint ?? (k.onClick ? `Show ${k.label.toLowerCase()}` : undefined)}
          initial={reduce ? false : { opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.03, type: "spring", stiffness: 300, damping: 26 }}
          whileHover={k.onClick && !reduce ? { y: -2 } : undefined} whileTap={k.onClick && !reduce ? { scale: 0.98 } : undefined}
          className={cn("glass group relative flex min-w-[120px] flex-1 items-center gap-2.5 overflow-hidden rounded-xl px-3 py-2 text-left",
            k.onClick ? "cursor-pointer" : "cursor-default", k.active && "ring-2 ring-gold/50")}>
          <span aria-hidden className="pointer-events-none absolute -right-6 -top-8 h-16 w-16 rounded-full opacity-25 blur-xl transition-opacity group-hover:opacity-45" style={{ background: k.color }} />
          <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: k.color, boxShadow: `0 0 8px ${k.color}` }} />
          <span className="min-w-0">
            <AnimatedNumber value={k.value} format={k.format} className="block font-display text-lg font-semibold leading-tight" />
            <span className="block truncate text-[10px] uppercase tracking-wider text-muted-foreground">{k.label}</span>
          </span>
        </motion.button>
      ))}
    </div>
  )
}
