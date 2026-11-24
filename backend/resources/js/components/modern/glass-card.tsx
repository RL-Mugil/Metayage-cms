import { forwardRef, type ReactNode } from "react"
import { motion, type HTMLMotionProps } from "framer-motion"
import { cn } from "@/lib/utils"

/** Fade/slide-in used by every tile; the parent sets the stagger. */
export const tileMotion = {
  hidden: { opacity: 0, y: 12, scale: 0.985 },
  show: { opacity: 1, y: 0, scale: 1, transition: { type: "spring", stiffness: 260, damping: 26 } },
} as const

export const staggerParent = {
  hidden: {},
  show: { transition: { staggerChildren: 0.045, delayChildren: 0.02 } },
} as const

type Props = Omit<HTMLMotionProps<"div">, "title"> & {
  title?: ReactNode
  icon?: ReactNode
  subtitle?: ReactNode
  actions?: ReactNode
  /** Body scrolls inside the card (fit-to-screen tiles). */
  scroll?: boolean
  bodyClassName?: string
  children?: ReactNode
}

/**
 * Translucent card with a soft layered shadow; optional header (icon, title, subtitle,
 * actions) and a body that can scroll inside the card so a page never needs to.
 */
export const GlassCard = forwardRef<HTMLDivElement, Props>(function GlassCard(
  { title, icon, subtitle, actions, scroll, className, bodyClassName, children, ...rest }, ref,
) {
  return (
    <motion.div ref={ref} variants={tileMotion} className={cn("glass flex min-h-0 flex-col rounded-2xl", className)} {...rest}>
      {(title || actions) && (
        <div className="flex shrink-0 items-start justify-between gap-2 px-4 pt-3.5 pb-2">
          <div className="min-w-0">
            {title && <h3 className="flex items-center gap-2 font-display text-[13px] font-semibold tracking-tight text-foreground">{icon}{title}</h3>}
            {subtitle && <p className="mt-0.5 truncate text-[11px] text-muted-foreground">{subtitle}</p>}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-1.5">{actions}</div>}
        </div>
      )}
      <div className={cn("min-h-0 flex-1", scroll && "thin-scrollbar overflow-y-auto", bodyClassName)}>{children}</div>
    </motion.div>
  )
})
