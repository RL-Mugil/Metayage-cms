import { useEffect, useState } from "react"
import { CalendarCheck, Check, Loader2, Trash2, X } from "lucide-react"
import { toast } from "sonner"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"
import type { StageDateSuggestion, StageEvent, WorkspaceStage } from "@/types/matter-workspace"

const today = () => new Date().toISOString().slice(0, 10)
const dateOnly = (value?: string | null) => (value ? value.slice(0, 10) : "")
const inputClass = "rounded-md border border-border bg-background px-2 py-1.5 text-xs"

function fmt(value?: string | null): string {
  if (!value) return "Not recorded"
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(date)
}

/** "recorded" / "auto" note next to a date. */
function SourceNote({ source, fromPrevious }: { source?: string | null; fromPrevious?: boolean }) {
  if (fromPrevious) return <span className="italic text-muted-foreground/70">(from previous stage)</span>
  if (source === "auto") return <span className="italic text-muted-foreground/70">(auto)</span>
  return null
}

/**
 * The case Lifecycle tab: the firm's internal work stages (the same stages as the Project
 * Tracker). Patent Office compliance dates live in the Deadlines tab. Dates are recorded
 * like docket events, logged automatically when a stage moves (only where none is
 * recorded), and back-logged for finished cases with suggested dates the case already knows.
 */
export function WorkflowStageLog({ projectId, stages, title, onChanged }: {
  projectId: number
  stages: WorkspaceStage[]
  title?: string
  onChanged: () => void
}) {
  const [events, setEvents] = useState<StageEvent[]>([])
  const [suggestions, setSuggestions] = useState<StageDateSuggestion[]>([])
  const [canRecord, setCanRecord] = useState(false)
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ stage_id: "", event: "completed" as "started" | "completed", event_date: today(), notes: "" })
  const [saving, setSaving] = useState(false)
  const [backlogOpen, setBacklogOpen] = useState(false)

  const load = () => {
    api.getStageEvents(projectId)
      .then((data) => { setEvents(data.events); setSuggestions(data.suggestions); setCanRecord(data.can_record) })
      .catch(() => { /* the tab still shows the stages */ })
      .finally(() => setLoading(false))
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [projectId])
  const refresh = () => { load(); onChanged() }

  const completed = stages.filter((stage) => stage.status === "Completed").length
  const progress = stages.length ? Math.round((completed / stages.length) * 100) : 0
  const suggestionFor = (stageId: number, event: "started" | "completed") => suggestions.find((s) => s.stage_id === stageId && s.event === event)

  async function record(stageId: number, event: "started" | "completed", date: string, notes?: string) {
    setSaving(true)
    try {
      await api.recordStageEvent(projectId, { stage_id: stageId, event, event_date: date, notes: notes || null })
      toast.success("Recorded.")
      refresh()
      return true
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not record the stage date.")
      return false
    } finally {
      setSaving(false)
    }
  }

  async function submit() {
    if (!form.stage_id || !form.event_date) return
    if (await record(Number(form.stage_id), form.event, form.event_date, form.notes)) {
      setForm({ ...form, stage_id: "", notes: "" })
    }
  }

  async function remove(event: StageEvent) {
    if (!window.confirm(`Remove "${event.stage_name} ${event.event} ${fmt(event.event_date)}" from the log? The stage's date is cleared if it came from this entry.`)) return
    try { await api.deleteStageEvent(event.id); refresh() } catch (error) { toast.error(error instanceof Error ? error.message : "Could not remove it.") }
  }

  return (
    <section className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold">Work stages (Project Tracker)</h2>
          <p className="mt-1 text-sm text-muted-foreground">{completed} of {stages.length} stages completed · internal workflow. Patent Office deadlines are in the Deadlines tab.</p>
          {title && <p className="mt-1 text-xs text-muted-foreground">{title}</p>}
        </div>
        <div className="flex items-center gap-3">
          {canRecord && stages.length > 0 && (
            <Button size="sm" variant="outline" onClick={() => setBacklogOpen(true)}><CalendarCheck className="mr-1 h-3.5 w-3.5" />Back-log dates</Button>
          )}
          <span className="font-mono text-sm font-semibold">{progress}%</span>
        </div>
      </div>

      {canRecord && (
        <div className="rounded-md border border-border bg-muted/20 p-3">
          <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Record workflow event</h4>
          <div className="flex flex-wrap items-end gap-2">
            <div>
              <label className="mb-1 block text-[10px] text-muted-foreground">Stage</label>
              <select value={form.stage_id} onChange={(e) => setForm({ ...form, stage_id: e.target.value })} className={`${inputClass} min-w-[220px]`}>
                <option value="">Select stage…</option>
                {stages.map((stage, index) => <option key={stage.id} value={stage.id}>{index + 1}. {stage.stage_name}</option>)}
              </select>
            </div>
            <div>
              <label className="mb-1 block text-[10px] text-muted-foreground">Event</label>
              <select value={form.event} onChange={(e) => setForm({ ...form, event: e.target.value as "started" | "completed" })} className={inputClass}>
                <option value="completed">Completed</option>
                <option value="started">Started</option>
              </select>
            </div>
            <div>
              <label className="mb-1 block text-[10px] text-muted-foreground">Date</label>
              <input type="date" value={form.event_date} max={today()} onChange={(e) => setForm({ ...form, event_date: e.target.value })} className={inputClass} />
            </div>
            <div className="min-w-[180px] flex-1">
              <label className="mb-1 block text-[10px] text-muted-foreground">Notes</label>
              <input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} placeholder="Optional notes" className={`${inputClass} w-full`} />
            </div>
            <Button size="sm" disabled={saving || !form.stage_id || !form.event_date} onClick={submit}>
              {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}Record
            </Button>
          </div>
          <p className="mt-2 text-[11px] text-muted-foreground">
            Moving a stage in the Project Tracker or the case list logs today automatically, only where no date is recorded. A stage's start is the previous stage's completion unless you record one.
          </p>
        </div>
      )}

      <div>
        {stages.map((stage, index) => {
          const startSuggestion = !stage.actual_start_at ? suggestionFor(stage.id, "started") : undefined
          const endSuggestion = !stage.actual_end_at ? suggestionFor(stage.id, "completed") : undefined
          const movedBack = stage.status === "Pending" && (stage.actual_start_at || stage.actual_end_at)
          return (
            <div key={stage.id} className="grid grid-cols-[32px_minmax(0,1fr)] gap-3">
              <div className="flex flex-col items-center">
                <div className={`mt-1 flex h-7 w-7 items-center justify-center rounded-full border text-[10px] font-semibold ${stage.status === "Completed" ? "border-emerald-500 bg-emerald-500 text-white" : stage.status === "In Progress" ? "border-blue-500 bg-blue-500 text-white" : "border-border bg-background text-muted-foreground"}`}>{index + 1}</div>
                {index < stages.length - 1 && <div className="min-h-12 w-px flex-1 bg-border" />}
              </div>
              <div className="pb-6">
                <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold">{stage.stage_name}</h3><Badge variant="outline">{stage.status}</Badge></div>
                <div className={`mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground ${movedBack ? "line-through decoration-muted-foreground/60" : ""}`}>
                  <span>Start: {fmt(stage.effective_start_at ?? stage.actual_start_at)} <SourceNote source={stage.start_source} fromPrevious={stage.start_from_previous} /></span>
                  <span>End: {fmt(stage.actual_end_at)} <SourceNote source={stage.end_source} /></span>
                  {stage.due_date && <span>Due: {fmt(stage.due_date)}</span>}
                  {stage.owner && <span>Owner: {stage.owner.name}</span>}
                  {stage.working_days != null && <span>{stage.working_days} working days</span>}
                </div>
                {canRecord && [startSuggestion, endSuggestion].filter(Boolean).map((suggestion) => suggestion && (
                  <div key={suggestion.event} className="mt-1.5 inline-flex items-center gap-2 rounded border border-dashed border-gold/50 bg-gold/5 px-2 py-1 text-[11px]">
                    <span>Suggested {suggestion.event === "started" ? "start" : "end"}: <strong>{fmt(suggestion.date)}</strong> · {suggestion.reason}</span>
                    <button className="inline-flex items-center gap-0.5 font-medium text-gold hover:underline" disabled={saving}
                      onClick={() => record(stage.id, suggestion.event, suggestion.date, `Accepted suggestion: ${suggestion.reason}`)}>
                      <Check className="h-3 w-3" />Accept
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )
        })}
      </div>

      <div>
        <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Workflow log</h4>
        {loading ? <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" /> : events.length === 0 ? (
          <p className="text-xs text-muted-foreground">Nothing logged yet.</p>
        ) : (
          <div className="divide-y divide-border rounded-md border border-border">
            {events.map((event) => (
              <div key={event.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-xs">
                <span className="w-24 font-mono">{fmt(event.event_date)}</span>
                <span className="min-w-0 flex-1"><span className="font-medium">{event.stage_name}</span> · {event.event === "completed" ? "Completed" : "Started"}{event.notes ? <span className="text-muted-foreground"> · {event.notes}</span> : null}</span>
                <Badge variant="outline" className="text-[10px]">{event.source_label}</Badge>
                <span className="text-muted-foreground">{event.created_by ?? "System"}</span>
                {canRecord && (
                  <button title="Remove from the log" className="text-muted-foreground hover:text-destructive" onClick={() => remove(event)}><Trash2 className="h-3.5 w-3.5" /></button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {backlogOpen && (
        <BacklogDialog projectId={projectId} stages={stages} suggestions={suggestions}
          onClose={() => setBacklogOpen(false)} onSaved={() => { setBacklogOpen(false); refresh() }} />
      )}
    </section>
  )
}

/**
 * One "Completed on" date per stage (start optional: it defaults to the previous stage's
 * completion). Suggested dates can be filled in one click. For finished cases.
 */
function BacklogDialog({ projectId, stages, suggestions, onClose, onSaved }: {
  projectId: number
  stages: WorkspaceStage[]
  suggestions: StageDateSuggestion[]
  onClose: () => void
  onSaved: () => void
}) {
  const [rows, setRows] = useState(() => stages.map((stage) => ({
    stage_id: stage.id,
    completed_on: stage.end_source === "manual" ? dateOnly(stage.actual_end_at) : "",
    started_on: stage.start_source === "manual" ? dateOnly(stage.actual_start_at) : "",
  })))
  const [saving, setSaving] = useState(false)
  const set = (index: number, key: "completed_on" | "started_on", value: string) =>
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, [key]: value } : row)))
  const useSuggestions = () => setRows((prev) => prev.map((row) => {
    const done = suggestions.find((s) => s.stage_id === row.stage_id && s.event === "completed")
    const start = suggestions.find((s) => s.stage_id === row.stage_id && s.event === "started")
    return { ...row, completed_on: row.completed_on || done?.date || "", started_on: row.started_on || start?.date || "" }
  }))
  const outOfOrder = rows.some((row, i) => {
    const previous = rows.slice(0, i).map((r) => r.completed_on).filter(Boolean).pop()
    return row.completed_on && previous && row.completed_on < previous
  })

  async function save() {
    const changed = rows.filter((row) => row.completed_on || row.started_on)
    if (changed.length === 0) { onClose(); return }
    setSaving(true)
    try {
      const result = await api.backlogStageDates(projectId, changed.map((row) => ({ stage_id: row.stage_id, completed_on: row.completed_on || null, started_on: row.started_on || null })))
      toast.success(`${result.dates} date${result.dates === 1 ? "" : "s"} saved.`)
      onSaved()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not save the dates.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-border bg-background shadow-2xl">
        <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
          <div>
            <h3 className="font-display text-base font-semibold">Back-log stage dates</h3>
            <p className="mt-0.5 text-xs text-muted-foreground">Enter when each stage was completed. Start is optional: it is taken from the previous stage's completion.</p>
          </div>
          <button onClick={onClose} className="rounded p-1 hover:bg-muted"><X className="h-4 w-4" /></button>
        </div>
        <div className="flex-1 overflow-auto px-5 py-3">
          <table className="w-full text-xs">
            <thead className="text-[10px] uppercase tracking-wider text-muted-foreground">
              <tr><th className="py-1.5 text-left">Stage</th><th className="py-1.5 text-left">Completed on</th><th className="py-1.5 text-left">Started on (optional)</th><th className="py-1.5 text-left">Suggestion</th></tr>
            </thead>
            <tbody>
              {stages.map((stage, index) => {
                const done = suggestions.find((s) => s.stage_id === stage.id && s.event === "completed")
                const start = suggestions.find((s) => s.stage_id === stage.id && s.event === "started")
                return (
                  <tr key={stage.id} className="border-t border-border">
                    <td className="py-1.5 pr-2">{index + 1}. {stage.stage_name}</td>
                    <td className="py-1.5 pr-2"><input type="date" max={today()} value={rows[index].completed_on} onChange={(e) => set(index, "completed_on", e.target.value)} className={inputClass} /></td>
                    <td className="py-1.5 pr-2"><input type="date" max={today()} value={rows[index].started_on} onChange={(e) => set(index, "started_on", e.target.value)} className={inputClass} tabIndex={-1} /></td>
                    <td className="py-1.5 text-muted-foreground">
                      {done && <div>Done {fmt(done.date)} · {done.reason}</div>}
                      {start && <div>Started {fmt(start.date)} · {start.reason}</div>}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          {outOfOrder && <p className="mt-2 text-xs text-amber-600">Some completion dates are earlier than a previous stage's. Check the order before saving.</p>}
        </div>
        <div className="flex items-center justify-between gap-2 border-t border-border px-5 py-3">
          <Button size="sm" variant="outline" disabled={suggestions.length === 0} onClick={useSuggestions}>Use all suggestions ({suggestions.length})</Button>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
            <Button size="sm" onClick={save} disabled={saving}>{saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}Save dates</Button>
          </div>
        </div>
      </div>
    </div>
  )
}
