import { useEffect, useState } from "react"
import { router } from "@inertiajs/react"
import { Activity } from "lucide-react"
import { useReducedMotion } from "framer-motion"
import { api, type PortfolioInsights } from "@/lib/api-client"

const DOT: Record<string, string> = { payment: "bg-emerald-500", invoice: "bg-blue-500", deadline: "bg-amber-500", stage: "bg-gold" }

/** "This week" strip: the week's stage moves, deadlines, invoices and payments, scrolling slowly (pauses on hover). */
export function ActivityTicker() {
  const [items, setItems] = useState<PortfolioInsights["changes"]>([])
  const reduce = useReducedMotion()
  useEffect(() => { api.getPortfolioInsights().then((d) => setItems(d.changes)).catch(() => setItems([])) }, [])

  const open = (c: PortfolioInsights["changes"][number]) => c.project_id && router.visit(`/projects/${c.project_id}${c.kind === "deadline" ? "?tab=deadlines" : c.kind === "stage" ? "?tab=lifecycle" : ""}`)
  const row = (keyPrefix: string) => items.map((c, i) => (
    <button key={`${keyPrefix}${i}`} onClick={() => open(c)} className="flex shrink-0 items-center gap-2 rounded-full border border-border/60 bg-background/70 px-3 py-1 text-[11px] hover:border-gold/50">
      <span className={`h-1.5 w-1.5 rounded-full ${DOT[c.kind] ?? "bg-muted-foreground"}`} />
      <span className="font-mono text-muted-foreground">{c.docket_number ?? ""}</span>
      <span className="max-w-[320px] truncate">{c.text}</span>
    </button>
  ))

  return (
    <div className="glass flex h-11 items-center gap-3 overflow-hidden rounded-2xl pl-3">
      <span className="flex shrink-0 items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"><Activity className="h-3.5 w-3.5 text-gold" /> This week</span>
      {items.length === 0 ? <span className="text-xs text-muted-foreground">No changes in the last 7 days.</span> : (
        <div className="group relative min-w-0 flex-1 overflow-hidden [mask-image:linear-gradient(90deg,transparent,black_4%,black_96%,transparent)]">
          <div className={`flex w-max gap-2 ${reduce || items.length < 4 ? "" : "animate-[ticker_60s_linear_infinite] group-hover:[animation-play-state:paused]"}`}>
            {row("a")}{!reduce && items.length >= 4 && row("b")}
          </div>
        </div>
      )}
    </div>
  )
}
