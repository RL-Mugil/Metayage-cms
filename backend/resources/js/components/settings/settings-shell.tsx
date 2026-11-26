import type { ComponentType, ReactNode } from "react"
import { AnimatePresence, motion, useReducedMotion } from "framer-motion"
import { cn } from "@/lib/utils"

export interface SettingsSection<T extends string> {
  id: T
  label: string
  icon: ComponentType<{ className?: string }>
  hint: string
  group: string
  /** Accent colour of the section's 3D icon tile. */
  color: string
  /** Wide sections (big tables) use the full width of the content panel. */
  wide?: boolean
}

/** A raised, glossy 3D icon tile (gradient face, lighter top edge, coloured glow). */
export function IconTile({ icon: Icon, color, size = "md" }: { icon: ComponentType<{ className?: string }>; color: string; size?: "md" | "lg" }) {
  const box = size === "lg" ? "h-12 w-12 rounded-2xl" : "h-8 w-8 rounded-xl"
  return (
    <span className={cn("relative grid shrink-0 place-items-center text-white", box)}
      style={{ background: `linear-gradient(145deg, color-mix(in oklab, ${color} 78%, white) 0%, ${color} 55%, color-mix(in oklab, ${color} 70%, black) 100%)`,
        boxShadow: `0 1px 0 0 rgba(255,255,255,.45) inset, 0 -2px 0 0 rgba(0,0,0,.18) inset, 0 6px 14px -6px ${color}` }}>
      <Icon className={size === "lg" ? "h-6 w-6 drop-shadow" : "h-4 w-4 drop-shadow"} />
    </span>
  )
}

/**
 * Settings frame: a 3D section menu on the left (grouped; a scrolling pill row on small screens)
 * and the chosen section on the right, with its own header card. The page fits the window: the
 * menu and the content scroll inside their panels. Changing section flips the content in.
 */
export function SettingsShell<T extends string>({ sections, active, onChange, banner, children }: {
  sections: SettingsSection<T>[]
  active: T
  onChange: (id: T) => void
  banner?: ReactNode
  children: ReactNode
}) {
  const reduce = useReducedMotion()
  const current = sections.find((s) => s.id === active) ?? sections[0]
  const groups = [...new Set(sections.map((s) => s.group))]

  return (
    <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[250px_minmax(0,1fr)] fit:h-full">
      {/* Menu: pills on small screens */}
      <div className="no-scrollbar -mx-1 flex gap-2 overflow-x-auto px-1 pb-1 lg:hidden">
        {sections.map((s) => (
          <button key={s.id} onClick={() => onChange(s.id)}
            className={cn("glass flex shrink-0 items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium", s.id === active ? "ring-2 ring-gold/60" : "text-muted-foreground")}>
            <IconTile icon={s.icon} color={s.color} />{s.label}
          </button>
        ))}
      </div>

      {/* Menu: 3D side panel on large screens */}
      <nav className="glass thin-scrollbar hidden min-h-0 overflow-y-auto rounded-2xl p-3 lg:block" style={{ perspective: 900 }}>
        {groups.map((g) => (
          <div key={g} className="mb-3 last:mb-0">
            <p className="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">{g}</p>
            <div className="space-y-1.5">
              {sections.filter((s) => s.group === g).map((s) => {
                const on = s.id === active
                return (
                  <motion.button key={s.id} onClick={() => onChange(s.id)}
                    whileHover={reduce ? undefined : { x: 3, rotateY: 6 }} whileTap={reduce ? undefined : { scale: 0.98 }}
                    animate={on && !reduce ? { rotateY: -4, z: 12 } : { rotateY: 0, z: 0 }}
                    className={cn("relative flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-left transition-colors",
                      on ? "bg-background/90 shadow-3d-lg" : "hover:bg-muted/40")}
                    style={on ? { boxShadow: `0 0 0 1.5px ${s.color}, var(--shadow-3d-lg)` } : undefined}>
                    {on && <span aria-hidden className="absolute inset-y-2 left-0 w-1 rounded-full" style={{ background: s.color, boxShadow: `0 0 10px ${s.color}` }} />}
                    <IconTile icon={s.icon} color={s.color} />
                    <span className="min-w-0">
                      <span className={cn("block text-sm leading-tight", on ? "font-semibold" : "font-medium text-foreground/85")}>{s.label}</span>
                      <span className="block truncate text-[10px] text-muted-foreground">{s.hint}</span>
                    </span>
                  </motion.button>
                )
              })}
            </div>
          </div>
        ))}
      </nav>

      {/* Content */}
      <section className="glass thin-scrollbar min-h-0 overflow-y-auto rounded-2xl fit:h-full" style={{ perspective: 1400 }}>
        <div className="sticky top-0 z-10 border-b border-border/60 bg-background/80 px-5 py-3 backdrop-blur-xl">
          <div className="flex items-center gap-3">
            <IconTile icon={current.icon} color={current.color} size="lg" />
            <div className="min-w-0">
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em]" style={{ color: current.color }}>{current.group}</p>
              <h2 className="font-display text-lg font-semibold leading-tight">{current.label}</h2>
              <p className="truncate text-xs text-muted-foreground">{current.hint}</p>
            </div>
          </div>
          {banner}
        </div>
        <AnimatePresence mode="wait" initial={false}>
          <motion.div key={active} className={cn("settings-3d mx-auto space-y-5 p-5", current.wide ? "max-w-none" : "max-w-5xl")}
            initial={reduce ? { opacity: 0 } : { opacity: 0, rotateX: -10, y: 24, scale: 0.98 }}
            animate={{ opacity: 1, rotateX: 0, y: 0, scale: 1 }}
            exit={reduce ? { opacity: 0 } : { opacity: 0, rotateX: 8, y: -12, scale: 0.99 }}
            transition={{ type: "spring", stiffness: 260, damping: 26 }}
            style={{ transformOrigin: "50% 0%" }}>
            {children}
          </motion.div>
        </AnimatePresence>
      </section>
    </div>
  )
}
