import { useEffect, useRef, useState } from "react"
import { animate, useReducedMotion } from "framer-motion"

export type NumberFormat = "number" | "inr" | "lakh" | "percent"

export function formatValue(value: number, format: NumberFormat = "number", decimals = 0): string {
  switch (format) {
    case "inr": return `₹${Math.round(value).toLocaleString("en-IN")}`
    case "lakh":
      if (Math.abs(value) >= 1e7) return `₹${(value / 1e7).toFixed(2)} Cr`
      if (Math.abs(value) >= 1e5) return `₹${(value / 1e5).toFixed(1)} L`
      return `₹${Math.round(value).toLocaleString("en-IN")}`
    case "percent": return `${value.toFixed(decimals)}%`
    default: return Math.round(value).toLocaleString("en-IN")
  }
}

/** Counts up to the value on mount and whenever it changes. */
export function AnimatedNumber({ value, format = "number", decimals = 0, className }: {
  value: number; format?: NumberFormat; decimals?: number; className?: string
}) {
  const reduce = useReducedMotion()
  const [shown, setShown] = useState(reduce ? value : 0)
  const from = useRef(reduce ? value : 0)

  useEffect(() => {
    if (reduce) { setShown(value); return }
    const controls = animate(from.current, value, {
      duration: 0.9, ease: [0.16, 1, 0.3, 1],
      onUpdate: (v) => setShown(v),
      onComplete: () => { from.current = value },
    })
    return () => controls.stop()
  }, [value, reduce])

  return <span className={className}>{formatValue(shown, format, decimals)}</span>
}
