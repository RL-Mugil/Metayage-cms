import { useRef, type ReactNode } from "react"
import { motion, useMotionTemplate, useMotionValue, useReducedMotion, useSpring, useTransform } from "framer-motion"
import { cn } from "@/lib/utils"
import { tileMotion } from "./glass-card"

/**
 * A glass card that leans toward the pointer (±6°) with a moving highlight, giving the
 * surface depth. Flat for reduced-motion users and on touch screens.
 */
export function TiltCard({ children, className, glow, onClick }: {
  children: ReactNode
  className?: string
  /** CSS colour of the soft glow behind the card. */
  glow?: string
  onClick?: () => void
}) {
  const ref = useRef<HTMLDivElement>(null)
  const reduce = useReducedMotion()
  const px = useMotionValue(0.5)
  const py = useMotionValue(0.5)
  const rx = useSpring(useTransform(py, [0, 1], [6, -6]), { stiffness: 200, damping: 18 })
  const ry = useSpring(useTransform(px, [0, 1], [-6, 6]), { stiffness: 200, damping: 18 })
  const hx = useTransform(px, (v) => `${v * 100}%`)
  const hy = useTransform(py, (v) => `${v * 100}%`)
  const highlight = useMotionTemplate`radial-gradient(240px circle at ${hx} ${hy}, rgb(255 255 255 / 0.35), transparent 60%)`

  const move = (e: React.PointerEvent) => {
    if (reduce || e.pointerType === "touch" || !ref.current) return
    const r = ref.current.getBoundingClientRect()
    px.set((e.clientX - r.left) / r.width)
    py.set((e.clientY - r.top) / r.height)
  }
  const leave = () => { px.set(0.5); py.set(0.5) }

  return (
    <motion.div variants={tileMotion} className="relative" style={{ perspective: 900 }}>
      {glow && <div aria-hidden className="pointer-events-none absolute inset-3 -z-0 rounded-3xl opacity-60 blur-2xl" style={{ background: glow }} />}
      <motion.div ref={ref} onPointerMove={move} onPointerLeave={leave} onClick={onClick}
        style={reduce ? undefined : { rotateX: rx, rotateY: ry, transformStyle: "preserve-3d" }}
        whileHover={reduce ? undefined : { y: -3 }}
        className={cn("glass relative h-full overflow-hidden rounded-2xl shadow-3d-lg", onClick && "cursor-pointer", className)}>
        {!reduce && <motion.div aria-hidden className="pointer-events-none absolute inset-0 z-10 mix-blend-soft-light" style={{ background: highlight }} />}
        <div className="relative" style={reduce ? undefined : { transform: "translateZ(24px)" }}>{children}</div>
      </motion.div>
    </motion.div>
  )
}
