import { useMemo, useState } from "react"
import { Link, router } from "@inertiajs/react"
import { motion } from "framer-motion"
import { AlertTriangle, Archive, ArrowUpRight, Briefcase, CalendarClock, Clock, Eye, Flame, Landmark, ShieldAlert, Users, Wallet } from "lucide-react"
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { Badge } from "@/components/ui/badge"
import { GlassCard, staggerParent } from "@/components/modern/glass-card"
import { KpiTile } from "@/components/modern/kpi-tile"
import { Ring3D, type RingDatum } from "@/components/modern/ring-3d"
import { Bar3DDefs, Bar3DShape } from "@/components/modern/bar-3d"
import { SegmentTabs } from "@/components/modern/segment-tabs"
import { AnimatedNumber, formatValue } from "@/components/modern/animated-number"
import { ActionItemRow, type ActionItem } from "@/components/action-item-row"
import { ActivityTicker } from "@/components/dashboard/activity-ticker"
import { fmtDate } from "@/lib/date-utils"
import { statusColor } from "@/lib/utils"
import type { DashboardTrends } from "@/lib/api-client"

type Drill = "active_cases" | "inactive_cases" | "active_clients" | "quoted" | "collected" | "dl_overdue" | "dl_next_7_days" | "dl_unreviewed" | "dl_critical"

const RISK: { key: Drill; label: string; field: string; icon: React.ComponentType<{ className?: string }>; hot: boolean }[] = [
  { key: "dl_overdue", label: "Overdue", field: "overdue", icon: Flame, hot: true },
  { key: "dl_next_7_days", label: "Next 7 days", field: "next_7_days", icon: CalendarClock, hot: false },
  { key: "dl_unreviewed", label: "Unreviewed", field: "unreviewed", icon: Eye, hot: false },
  { key: "dl_critical", label: "Critical", field: "critical", icon: ShieldAlert, hot: true },
]

/**
 * Dashboard → Overview as one screen: KPI strip; deadline risk and case mix on the left;
 * money (or new cases) chart in the middle; everything needing attention on the right,
 * scrolling inside its tile; the week's activity along the bottom.
 */
export function OverviewGrid({ metrics, trends, projects, tasks, statusMix, showMoney, onDrill, onRenew }: {
  metrics: Record<string, any>
  trends: DashboardTrends | null
  projects: any[]
  tasks: any[]
  statusMix: { data: RingDatum[]; total: number }
  showMoney: boolean
  onDrill: (key: Drill) => void
  onRenew: (item: ActionItem) => void
}) {
  const actions: ActionItem[] = metrics.action_items ?? []
  const [focus, setFocus] = useState<"actions" | "cases" | "tasks">(actions.length ? "actions" : "cases")
  const risk = metrics.deadline_risk ?? {}
  const chart = useMemo(() => (trends?.months ?? []).map((m, i) => ({
    month: m, billed: trends?.billed[i] ?? 0, collected: trends?.collected[i] ?? 0, opened: trends?.opened[i] ?? 0,
  })), [trends])
  const moneyChart = showMoney && chart.some((c) => c.billed || c.collected)
  const rate = Number(metrics.realization_rate ?? 0)

  return (
    <motion.div variants={staggerParent} initial="hidden" animate="show"
      className="flex flex-col gap-3 fit:grid fit:h-full fit:grid-rows-[auto_minmax(0,1fr)_auto]">
      {/* KPI strip */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        <KpiTile label="Active cases" value={Number(metrics.active_matters ?? 0)} icon={Briefcase} accent="blue" spark={trends?.opened}
          delta={metrics.opened_this_month != null ? `+${metrics.opened_this_month} this month` : null} trend="up" onClick={() => onDrill("active_cases")}
          subtitle={metrics.distinct_matters != null && metrics.distinct_matters !== metrics.active_matters ? `${metrics.distinct_matters} matters` : null} />
        <KpiTile label="Inactive cases" value={Number(metrics.inactive_matters ?? 0)} icon={Archive} accent="slate" onClick={() => onDrill("inactive_cases")} />
        <KpiTile label="Active clients" value={Number(metrics.clients ?? 0)} icon={Users} accent="gold" delta={metrics.clients_delta} trend={metrics.clients_delta_trend ?? "up"} onClick={() => onDrill("active_clients")} />
        <KpiTile label="Quoted, not invoiced" value={Number(metrics.quoted_open ?? 0)} format="lakh" icon={Clock} accent="violet" spark={trends?.quoted}
          subtitle={`${metrics.quoted_count ?? 0} open quotes`} onClick={() => onDrill("quoted")} />
        <KpiTile label="Collected this month" value={Number(metrics.collected_mtd ?? 0)} format="lakh" icon={Wallet} accent="emerald" spark={trends?.collected}
          delta={metrics.revenue_delta} trend={metrics.revenue_delta_trend ?? "up"} onClick={() => onDrill("collected")} />
        <KpiTile label="Outstanding" value={Number(metrics.zoho_outstanding ?? 0)} format="lakh" icon={Landmark} accent="rose"
          subtitle={`${metrics.overdue_invoices ?? 0} invoices overdue`} onClick={() => router.visit("/pending-payments")} />
      </div>

      {/* Main band */}
      <div className="grid min-h-0 gap-3 lg:grid-cols-12">
        {/* Left: deadline risk + case mix */}
        <div className="flex min-h-0 flex-col gap-3 lg:col-span-3">
          <GlassCard title="Statutory deadline risk" icon={<AlertTriangle className="h-4 w-4 text-amber-500" />}
            actions={<Link href="/projects" className="text-[11px] font-medium text-gold hover:underline">Matters</Link>} className="shrink-0">
            <div className="grid grid-cols-2 gap-1.5 px-3 pb-3">
              {RISK.map((r) => {
                const n = Number(risk[r.field] ?? 0)
                const alarm = r.hot && n > 0
                return (
                  <motion.button key={r.key} whileHover={n ? { y: -1 } : undefined} onClick={() => n && onDrill(r.key)} disabled={!n} title={n ? `Show the ${r.label.toLowerCase()} deadlines` : undefined}
                    className={`relative flex items-center gap-2 overflow-hidden rounded-lg border px-2 py-1.5 text-left transition-colors ${alarm ? "border-rose-500/30 bg-gradient-to-br from-rose-500/15 to-rose-500/5" : "border-border/60 bg-background/60"} ${n ? "cursor-pointer hover:border-gold/50" : "cursor-default opacity-80"}`}>
                    {alarm && <span className="absolute right-1.5 top-1.5 h-1.5 w-1.5 animate-ping rounded-full bg-rose-500" />}
                    <r.icon className={`h-3.5 w-3.5 shrink-0 ${alarm ? "text-rose-500" : "text-muted-foreground"}`} />
                    <AnimatedNumber value={n} className={`font-display text-base font-semibold leading-none ${alarm ? "text-rose-600" : ""}`} />
                    <span className="truncate text-[10px] text-muted-foreground">{r.label}</span>
                  </motion.button>
                )
              })}
            </div>
          </GlassCard>
          <GlassCard title="Case mix" className="min-h-[190px] fit:min-h-0 flex-1"
            actions={<Link href="/patent-portfolio" className="text-[11px] font-medium text-gold hover:underline">Portfolio</Link>}>
            <div className="flex h-full items-center px-3 pb-3">
              <Ring3D data={statusMix.data} total={statusMix.total} label="cases" size={124} thickness={16} />
            </div>
          </GlassCard>
        </div>

        {/* Centre: money or intake */}
        <GlassCard className="min-h-[300px] fit:min-h-0 lg:col-span-5"
          title={moneyChart ? "Billed vs collected" : "New cases"} subtitle={moneyChart ? "Last 6 months · register" : "Cases opened, last 6 months"}
          actions={moneyChart ? (
            <div className="flex items-center gap-3 text-[11px]">
              <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-blue-500" />Billed</span>
              <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-emerald-500" />Collected</span>
            </div>
          ) : undefined}>
          <div className="flex h-full flex-col px-2 pb-3">
            {moneyChart && (
              <div className="mx-2 mb-1 flex items-center gap-3 rounded-xl bg-gradient-to-r from-emerald-500/10 via-transparent to-transparent px-3 py-2">
                <div className="relative h-11 w-11 shrink-0">
                  <svg viewBox="0 0 36 36" className="h-11 w-11 -rotate-90">
                    <circle cx="18" cy="18" r="15.5" fill="none" stroke="currentColor" strokeWidth="4" className="text-muted" />
                    <motion.circle cx="18" cy="18" r="15.5" fill="none" stroke="#10b981" strokeWidth="4" strokeLinecap="round"
                      initial={{ strokeDasharray: "0 100" }} animate={{ strokeDasharray: `${Math.min(rate, 100) * 0.974} 100` }} transition={{ duration: 1.1, ease: "easeOut" }} />
                  </svg>
                </div>
                <div>
                  <AnimatedNumber value={rate} format="percent" decimals={1} className="font-display text-lg font-semibold leading-none" />
                  <p className="text-[10px] text-muted-foreground">of everything billed has been collected</p>
                </div>
              </div>
            )}
            <div className="min-h-[200px] fit:min-h-0 flex-1">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chart} margin={{ top: 14, right: 12, left: 0, bottom: 0 }} barGap={4}>
                  <Bar3DDefs />
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 10 }} tickLine={false} axisLine={false} width={moneyChart ? 54 : 30} stroke="var(--muted-foreground)"
                    tickFormatter={(v) => (moneyChart ? formatValue(Number(v), "lakh") : String(v))} />
                  <Tooltip cursor={{ fill: "var(--muted)", opacity: 0.4 }} contentStyle={{ fontSize: 12, borderRadius: 10 }}
                    formatter={(v: number) => (moneyChart ? formatValue(Number(v), "inr") : v)} />
                  {/* recharts ignores bars wrapped in a fragment — keep each one a direct child */}
                  {moneyChart && <Bar dataKey="billed" name="Billed" fill="#3b82f6" shape={<Bar3DShape />} maxBarSize={30} animationDuration={900} />}
                  {moneyChart && <Bar dataKey="collected" name="Collected" fill="#10b981" shape={<Bar3DShape />} maxBarSize={30} animationDuration={900} />}
                  {!moneyChart && <Bar dataKey="opened" name="Opened" fill="#d4a017" shape={<Bar3DShape />} maxBarSize={36} animationDuration={900} />}
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>
        </GlassCard>

        {/* Right: everything needing attention */}
        <GlassCard className="min-h-[300px] fit:min-h-0 lg:col-span-4" scroll bodyClassName="px-3 pb-3"
          title="Needs attention"
          actions={<SegmentTabs size="sm" value={focus} onChange={setFocus} options={[
            { value: "actions", label: "Actions", badge: actions.length },
            { value: "cases", label: "Cases" },
            { value: "tasks", label: "Tasks", badge: tasks.length },
          ]} />}>
          {focus === "actions" && (actions.length === 0
            ? <p className="py-10 text-center text-sm text-muted-foreground">Nothing waiting. 🎉</p>
            : <div className="space-y-2">{actions.map((item) => <ActionItemRow key={item.id} item={item} onRenewClick={onRenew} showOwnerBadge />)}</div>)}
          {focus === "cases" && (
            <div className="divide-y divide-border/60">
              {projects.slice(0, 60).map((p) => {
                const stage = p.stages?.find((s: any) => s.status === "In Progress")?.stage_name ?? "—"
                const late = p.hard_deadline && new Date(p.hard_deadline) < new Date()
                return (
                  <button key={p.id} onClick={() => router.visit(`/projects/${p.id}`)} className="flex w-full items-center justify-between gap-2 rounded-lg px-1.5 py-2 text-left hover:bg-muted/50">
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-medium">{p.project_name}</p>
                      <p className="truncate text-[11px] text-muted-foreground"><span className="font-mono">{p.docket_number ?? p.project_code}</span> · {p.client?.company_name ?? ""} · {stage}</p>
                    </div>
                    <span className={`shrink-0 font-mono text-[11px] ${late ? "font-semibold text-rose-600" : "text-muted-foreground"}`}>{p.hard_deadline ? fmtDate(p.hard_deadline) : ""}</span>
                  </button>
                )
              })}
              {projects.length > 60 && <Link href="/projects" className="flex items-center justify-center gap-1 py-2 text-xs font-medium text-gold">All {projects.length} cases <ArrowUpRight className="h-3 w-3" /></Link>}
              {projects.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">No cases yet.</p>}
            </div>
          )}
          {focus === "tasks" && (
            <div className="space-y-1.5">
              {tasks.map((t) => (
                <button key={t.id} onClick={() => router.visit(`/tasks?open=${t.id}`)} className="flex w-full items-start gap-2.5 rounded-lg border border-border/50 bg-background/50 p-2.5 text-left hover:border-gold/40">
                  <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gold" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium">{t.title}</p>
                    <p className="text-[11px] text-muted-foreground"><span className="font-mono">{t.project?.project_code}</span>{t.due_date ? ` · ${fmtDate(t.due_date)}` : ""}</p>
                  </div>
                  <Badge variant={statusColor(t.status)} className="shrink-0 text-[10px]">{t.status}</Badge>
                </button>
              ))}
              {tasks.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">No open tasks.</p>}
            </div>
          )}
        </GlassCard>
      </div>

      <ActivityTicker />
    </motion.div>
  )
}
