import { useEffect, useState } from "react"
import { CheckCircle2, Circle, FileWarning, HeartPulse, Upload } from "lucide-react"
import { api, type CaseInsightsData } from "@/lib/api-client"
import { GlossaryText } from "@/components/glossary-text"

const fmt = (d?: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "")

/**
 * Case overview strip: health score with reasons, the journey (stages done → now → next,
 * with office milestones and coming deadlines), and the documents this filing needs.
 */
export function CaseInsightsPanel({ projectId, onOpenDocuments }: { projectId: number; onOpenDocuments: () => void }) {
  const [data, setData] = useState<CaseInsightsData | null>(null)

  useEffect(() => { api.getCaseInsights(projectId).then(setData).catch(() => setData(null)) }, [projectId])
  if (!data) return null

  const { health, journey, checklist } = data
  const tone = health?.level === "good" ? "text-emerald-500 border-emerald-500/40" : health?.level === "watch" ? "text-amber-500 border-amber-500/40" : "text-red-500 border-red-500/40"
  const missing = checklist.filter((c) => !c.present)

  return (
    <section className="grid gap-4 lg:grid-cols-[220px_1fr_260px]">
      {health && (
        <div className={`rounded-md border p-4 ${tone}`}>
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider"><HeartPulse className="h-4 w-4" /> Case health</div>
          <div className="mt-2 font-display text-4xl font-semibold">{health.score}<span className="text-base text-muted-foreground">/100</span></div>
          <ul className="mt-2 space-y-0.5 text-xs text-muted-foreground">
            {health.reasons.length === 0 ? <li>On track — nothing needs attention.</li> : health.reasons.map((r) => <li key={r}>• {r}</li>)}
          </ul>
        </div>
      )}

      <div className="rounded-md border border-border p-4">
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Journey</p>
        <ol className="mt-3 flex items-start gap-0 overflow-x-auto pb-1">
          {journey.stages.map((s, i) => {
            const done = s.status === "Completed", now = s.status === "In Progress"
            return (
              <li key={s.id} className="flex min-w-[92px] flex-1 flex-col items-center text-center">
                <div className="flex w-full items-center">
                  <div className={`h-0.5 flex-1 ${i === 0 ? "opacity-0" : done || now ? "bg-gold" : "bg-border"}`} />
                  <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 ${done ? "border-gold bg-gold text-black" : now ? "animate-pulse border-gold bg-gold/20" : "border-border bg-background"}`}>
                    {done ? <CheckCircle2 className="h-3 w-3" /> : <Circle className="h-2 w-2 opacity-0" />}
                  </span>
                  <div className={`h-0.5 flex-1 ${i === journey.stages.length - 1 ? "opacity-0" : done ? "bg-gold" : "bg-border"}`} />
                </div>
                <GlossaryText text={s.name} className={`mt-1.5 px-1 text-[10px] leading-tight ${now ? "font-semibold text-foreground" : "text-muted-foreground"}`} />
                <span className="text-[9px] text-muted-foreground">{done ? fmt(s.ended) : now ? (s.started ? `since ${fmt(s.started)}` : "now") : s.due ? `by ${fmt(s.due)}` : ""}</span>
              </li>
            )
          })}
        </ol>
        {(journey.milestones.length > 0 || journey.upcoming.length > 0) && (
          <div className="mt-3 flex flex-wrap gap-1.5 border-t border-border pt-3 text-[11px]">
            {journey.milestones.map((m) => <span key={m.label} className="rounded bg-muted px-1.5 py-0.5"><GlossaryText text={m.label} />: {fmt(m.date)}</span>)}
            {journey.upcoming.map((d) => <span key={d.id} className="rounded bg-amber-500/10 px-1.5 py-0.5 text-amber-600">Next: <GlossaryText text={d.title} /> · {fmt(d.date)}</span>)}
          </div>
        )}
      </div>

      {checklist.length > 0 && (
        <div className="rounded-md border border-border p-4">
          <div className="flex items-center justify-between">
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Documents</p>
            <span className={`text-[10px] font-semibold ${missing.length ? "text-amber-600" : "text-emerald-600"}`}>{checklist.length - missing.length}/{checklist.length} on file</span>
          </div>
          <ul className="mt-2 space-y-1">
            {checklist.map((c) => (
              <li key={c.key} className="flex items-center justify-between gap-2 text-xs">
                <span className="flex items-center gap-1.5">
                  {c.present ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" /> : <FileWarning className="h-3.5 w-3.5 text-amber-500" />}
                  <GlossaryText text={c.label} className={c.present ? "text-muted-foreground" : ""} />
                </span>
                {!c.present && (
                  <button onClick={onOpenDocuments} className="flex items-center gap-1 text-[10px] font-medium text-gold hover:underline"><Upload className="h-3 w-3" /> Upload</button>
                )}
              </li>
            ))}
          </ul>
          <p className="mt-2 text-[10px] text-muted-foreground">Matched from uploaded file names.</p>
        </div>
      )}
    </section>
  )
}
