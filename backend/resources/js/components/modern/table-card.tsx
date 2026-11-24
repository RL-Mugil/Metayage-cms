import { forwardRef, type ReactNode } from "react"
import { cn } from "@/lib/utils"

/**
 * Glass card holding a data table: the table area scrolls inside the card (both ways), the
 * header row stays put (give <thead> the `table-sticky-head` class), and an optional footer
 * (pager) sits at the bottom. On fit-to-screen layouts the card fills the remaining height.
 * The ref points at the scroll area so callers can size pages with useFitRows.
 */
export const TableCard = forwardRef<HTMLDivElement, {
  children: ReactNode
  footer?: ReactNode
  header?: ReactNode
  className?: string
  bodyClassName?: string
}>(function TableCard({ children, footer, header, className, bodyClassName }, ref) {
  return (
    <div className={cn("glass flex min-h-[320px] flex-col overflow-hidden rounded-2xl fit:min-h-0 fit:flex-1", className)}>
      {header && <div className="shrink-0 border-b border-border/60 px-4 py-2.5">{header}</div>}
      <div ref={ref} className={cn("thin-scrollbar min-h-0 flex-1 overflow-auto", bodyClassName)}>{children}</div>
      {footer && <div className="shrink-0 border-t border-border/60 bg-background/40">{footer}</div>}
    </div>
  )
})
