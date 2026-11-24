import { useId, type ReactNode } from "react"
import { motion } from "framer-motion"
import { cn } from "@/lib/utils"

/** Pill switch with a sliding highlight; used for page tabs and in-card toggles. */
export function SegmentTabs<T extends string>({ value, onChange, options, size = "md", className }: {
  value: T
  onChange: (value: T) => void
  options: { value: T; label: ReactNode; badge?: number }[]
  size?: "sm" | "md"
  className?: string
}) {
  const id = useId()
  return (
    <div role="tablist" className={cn("inline-flex shrink-0 items-center rounded-full border border-border/70 bg-muted/50 p-0.5 backdrop-blur", className)}>
      {options.map((o) => {
        const active = o.value === value
        return (
          <button key={o.value} role="tab" aria-selected={active} onClick={() => onChange(o.value)}
            className={cn("relative whitespace-nowrap rounded-full font-medium transition-colors",
              size === "sm" ? "px-2.5 py-0.5 text-[11px]" : "px-3.5 py-1.5 text-xs",
              active ? "text-foreground" : "text-muted-foreground hover:text-foreground")}>
            {active && <motion.span layoutId={`seg-${id}`} className="absolute inset-0 rounded-full bg-background shadow-3d" transition={{ type: "spring", stiffness: 420, damping: 34 }} />}
            <span className="relative flex items-center gap-1.5">
              {o.label}
              {!!o.badge && <span className="rounded-full bg-gold/90 px-1.5 text-[10px] font-semibold leading-4 text-black">{o.badge}</span>}
            </span>
          </button>
        )
      })}
    </div>
  )
}
