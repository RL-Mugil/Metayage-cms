import type { ReactNode } from "react"
import { cn } from "@/lib/utils"

/**
 * Page frame shared by the modern pages: mesh background, a compact one-row header
 * (title, one-line summary, actions), an optional toolbar row, and a body that fills the
 * window on screens ≥1024×680 (lists then scroll inside their cards). Smaller screens stack
 * and scroll normally.
 */
export function FitPage({ title, eyebrow, summary, actions, toolbar, children, bodyClassName, fit = true }: {
  title: ReactNode
  eyebrow?: ReactNode
  summary?: ReactNode
  actions?: ReactNode
  toolbar?: ReactNode
  children: ReactNode
  bodyClassName?: string
  /** false: keep the frame but let the page scroll (pages that are naturally long). */
  fit?: boolean
}) {
  return (
    <div className={cn("surface-mesh flex min-h-[calc(100dvh-3.5rem)] flex-col", fit && "fit:h-[calc(100dvh-3.5rem)] fit:overflow-hidden")}>
      <header className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-4 pb-2 sm:px-6">
        <div className="mr-auto min-w-0">
          {eyebrow && <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gold">{eyebrow}</p>}
          <h1 className="truncate font-display text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
          {summary && <p className="truncate text-xs text-muted-foreground">{summary}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </header>
      {toolbar && <div className="flex shrink-0 flex-wrap items-center gap-2 px-4 pb-2 sm:px-6">{toolbar}</div>}
      <main className={cn("min-h-0 flex-1 px-4 pb-4 sm:px-6", fit && "fit:flex fit:flex-col fit:overflow-hidden", bodyClassName)}>{children}</main>
    </div>
  )
}
