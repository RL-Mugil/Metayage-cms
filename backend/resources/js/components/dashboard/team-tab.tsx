import { useEffect, useMemo, useState } from "react"
import { router } from "@inertiajs/react"
import { CalendarDays, Loader2, Trophy, Users } from "lucide-react"
import { motion } from "framer-motion"
import { GlassCard, staggerParent } from "@/components/modern/glass-card"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api, type TeamInsights } from "@/lib/api-client"

const fmt = (d?: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—")
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`

type ListState = { title: string; rows: Array<{ key: string | number; main: string; sub: string; right: string; url: string }> } | null

/**
 * Dashboard → Team: deadline heat-map (next 26 weeks, darker = more statutory deadlines that
 * day), workload per person as PCM / SCM / PR with overdue deadlines, and the opt-in
 * leaderboard. Every cell or bar opens the list behind it.
 */
export function TeamTab() {
  const [data, setData] = useState<TeamInsights | null>(null)
  const [error, setError] = useState("")
  const [list, setList] = useState<ListState>(null)
  const [loadingList, setLoadingList] = useState(false)

  useEffect(() => { api.getTeamInsights().then(setData).catch((e: Error) => setError(e.message)) }, [])

  const weeks = useMemo(() => {
    if (!data) return []
    const start = new Date(`${data.heatmap.start}T00:00:00`)
    start.setDate(start.getDate() - start.getDay())   // back to Sunday
    return Array.from({ length: 27 }, (_, w) => Array.from({ length: 7 }, (_, d) => {
      const day = new Date(start); day.setDate(start.getDate() + w * 7 + d)
      return iso(day)
    }))
  }, [data])

  if (error) return <p className="py-10 text-center text-sm text-destructive">{error}</p>
  if (!data) return <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>

  const max = Math.max(1, ...Object.values(data.heatmap.days))
  const today = data.heatmap.start
  const open = async (title: string, load: () => Promise<ListState>) => {
    setLoadingList(true); setList({ title, rows: [] })
    try { setList(await load()) } catch { setList({ title, rows: [] }) } finally { setLoadingList(false) }
  }
  const openDay = (day: string) => open(`Deadlines on ${fmt(day)}`, async () => ({
    title: `Deadlines on ${fmt(day)}`,
    rows: (await api.getDashboardDeadlines("day", day)).map((d: any) => ({ key: d.id, main: d.docket_number ?? "—", sub: `${d.title} · ${d.client ?? ""}`, right: d.risk_level ?? "", url: `/projects/${d.project_id}?tab=deadlines` })),
  }))
  const openPerson = (id: number, name: string) => open(`${name}'s active cases`, async () => ({
    title: `${name}'s active cases`,
    rows: (await api.getTeamCases(id)).map((c) => ({ key: c.id, main: c.docket_number ?? "—", sub: `${c.project_name ?? ""} · ${c.client ?? ""}`, right: `${c.as}${c.hard_deadline ? ` · ${fmt(c.hard_deadline)}` : ""}`, url: `/projects/${c.id}` })),
  }))
  const maxLoad = Math.max(1, ...data.workload.map((w) => w.total))

  return (
    <motion.div variants={staggerParent} initial="hidden" animate="show" className="flex flex-col gap-3 fit:grid fit:h-full fit:grid-rows-[auto_minmax(0,1fr)]">
      <GlassCard title="Deadline load · next 26 weeks" icon={<CalendarDays className="h-4 w-4 text-gold" />}
        subtitle={<>Darker days carry more open statutory deadlines. {data.heatmap.overdue > 0 && <span className="font-medium text-destructive">{data.heatmap.overdue} already overdue.</span>} Click a day to list it.</>}
        bodyClassName="thin-scrollbar overflow-x-auto px-4 pb-4">
        <div>
          <div className="flex gap-[3px]">
            <div className="mr-1 flex flex-col gap-[3px] pt-4 text-[9px] text-muted-foreground">
              {["S", "M", "T", "W", "T", "F", "S"].map((d, i) => <span key={i} className="h-3 leading-3">{i % 2 ? d : ""}</span>)}
            </div>
            {weeks.map((week, w) => (
              <div key={w} className="flex flex-col gap-[3px]">
                <span className="h-3 text-[9px] leading-3 text-muted-foreground">{week[0].endsWith("-01") || (w === 0) || new Date(`${week[0]}T00:00:00`).getDate() <= 7 ? new Date(`${week[0]}T00:00:00`).toLocaleDateString("en-IN", { month: "short" }) : ""}</span>
                {week.map((day) => {
                  const n = data.heatmap.days[day] ?? 0
                  const past = day < today
                  return (
                    <button key={day} disabled={past || n === 0} onClick={() => openDay(day)} title={`${fmt(day)}: ${n} deadline${n === 1 ? "" : "s"}`}
                      className={`h-3 w-3 rounded-[2px] ${past ? "opacity-20" : ""} ${n ? "hover:ring-1 hover:ring-foreground" : ""}`}
                      style={{ backgroundColor: n ? `hsl(${n / max > 0.66 ? 0 : 38} 85% ${62 - (n / max) * 22}%)` : "hsl(var(--muted))" }} />
                  )
                })}
              </div>
            ))}
          </div>
        </div>
      </GlassCard>

      <div className="grid min-h-0 gap-3 lg:grid-cols-3">
        <GlassCard className={`min-h-[260px] ${data.leaderboard ? "lg:col-span-2" : "lg:col-span-3"}`} title="Workload by person" icon={<Users className="h-4 w-4 text-gold" />}
          subtitle="Active cases as PCM, SCM and PR · click a person for their cases" scroll bodyClassName="space-y-1.5 px-3 pb-3">
            {data.workload.length === 0 ? <p className="py-4 text-center text-sm text-muted-foreground">No active cases assigned.</p> : data.workload.map((w) => (
              <button key={w.user_id} onClick={() => openPerson(w.user_id, w.name)} className="block w-full rounded-md p-1.5 text-left hover:bg-muted/40">
                <div className="flex items-center justify-between gap-2 text-xs">
                  <span className="truncate font-medium">{w.name}</span>
                  <span className="flex items-center gap-2 whitespace-nowrap">
                    {w.overdue > 0 && <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-red-600">{w.overdue} overdue</span>}
                    <span className="font-mono">{w.total}</span>
                  </span>
                </div>
                <div className="mt-1 flex h-2 overflow-hidden rounded-full bg-muted" style={{ width: `${Math.max(6, (w.total / maxLoad) * 100)}%` }}>
                  <div className="bg-blue-500" style={{ flex: w.pcm }} title={`PCM ${w.pcm}`} />
                  <div className="bg-violet-500" style={{ flex: w.scm }} title={`SCM ${w.scm}`} />
                  <div className="bg-emerald-500" style={{ flex: w.pr }} title={`PR ${w.pr}`} />
                </div>
              </button>
            ))}
            <div className="flex gap-3 pt-1 text-[10px] text-muted-foreground">
              <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-blue-500" />PCM</span>
              <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-violet-500" />SCM</span>
              <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" />PR</span>
            </div>
        </GlassCard>

        {data.leaderboard && (
          <GlassCard className="min-h-[260px]" title="This month" icon={<Trophy className="h-4 w-4 text-gold" />} subtitle="Stages completed + deadlines met on time" scroll bodyClassName="space-y-1.5 px-3 pb-3">
              {data.leaderboard.length === 0 ? <p className="py-4 text-center text-sm text-muted-foreground">Nothing completed yet this month.</p> : data.leaderboard.map((l, i) => (
                <div key={l.user_id} className="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-xs odd:bg-muted/30">
                  <span className="flex items-center gap-2"><span className={`w-4 text-center font-semibold ${i === 0 ? "text-gold" : "text-muted-foreground"}`}>{i + 1}</span>{l.name}</span>
                  <span className="font-mono text-muted-foreground">{l.stages} stages · {l.deadlines} on time</span>
                </div>
              ))}
          </GlassCard>
        )}
      </div>

      {list && (
        <Dialog open onOpenChange={(o) => !o && setList(null)}>
          <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
            <DialogHeader><DialogTitle>{list.title}</DialogTitle></DialogHeader>
            {loadingList ? <Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /> : list.rows.length === 0 ? <p className="text-sm text-muted-foreground">Nothing here.</p> : (
              <div className="divide-y divide-border">
                {list.rows.map((r) => (
                  <button key={r.key} onClick={() => router.visit(r.url)} className="flex w-full items-center justify-between gap-3 px-1 py-2 text-left hover:bg-muted/30">
                    <div className="min-w-0"><p className="font-mono text-xs font-semibold text-gold">{r.main}</p><p className="truncate text-xs text-muted-foreground">{r.sub}</p></div>
                    <span className="shrink-0 text-[11px] text-muted-foreground">{r.right}</span>
                  </button>
                ))}
              </div>
            )}
          </DialogContent>
        </Dialog>
      )}
    </motion.div>
  )
}
