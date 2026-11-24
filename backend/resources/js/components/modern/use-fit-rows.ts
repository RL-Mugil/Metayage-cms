import { useEffect, useState } from "react"

/** Same breakpoint as the `fit` CSS variant in resources/css/app.css. */
const FIT_QUERY = "(min-width: 1024px) and (min-height: 680px)"

/**
 * How many list rows fit in an element: on fit-to-screen layouts the page size follows the
 * element's height (at least 3); elsewhere the fixed fallback is used. Returns a callback ref
 * to put on the element whose height the layout decides (not its content), and the row count.
 */
export function useFitRows(rowHeight: number, fallback: number, reserved = 0) {
  const [el, setEl] = useState<HTMLElement | null>(null)
  const [rows, setRows] = useState(fallback)

  useEffect(() => {
    if (!el || typeof window === "undefined") return
    const mq = window.matchMedia(FIT_QUERY)
    const calc = () => {
      setRows(mq.matches ? Math.max(3, Math.floor((el.clientHeight - reserved) / rowHeight)) : fallback)
    }
    const observer = new ResizeObserver(calc)
    observer.observe(el)
    mq.addEventListener("change", calc)
    calc()
    return () => { observer.disconnect(); mq.removeEventListener("change", calc) }
  }, [el, rowHeight, fallback, reserved])

  return [setEl, rows] as const
}
