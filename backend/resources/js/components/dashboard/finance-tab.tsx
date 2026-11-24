import { useEffect, useState } from "react"
import { router, Link } from "@inertiajs/react"
import { motion } from "framer-motion"
import { ArrowUpRight, BadgePercent, CalendarClock, Filter, Landmark, Loader2, Receipt, TrendingUp, Wallet } from "lucide-react"
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { api, type DashboardFinance } from "@/lib/api-client"
import { GlassCard, staggerParent } from "@/components/modern/glass-card"
import { KpiTile } from "@/components/modern/kpi-tile"
import { Ring3D } from "@/components/modern/ring-3d"
import { Bar3DDefs, Bar3DShape, HBar3D } from "@/components/modern/bar-3d"
import { SegmentTabs } from "@/components/modern/segment-tabs"
import { formatValue } from "@/components/modern/animated-number"

const inr = (v: number) => formatValue(v, "inr")
const lakh = (v: number) => formatValue(v, "lakh")

const AGEING_COLOR: Record<string, string> = { current: "#10b981", "1_30": "#f59e0b", "31_60": "#f97316", "61_90": "#ef4444", "90_plus": "#b91c1c" }

/**
 * Dashboard → Financial on one screen: money KPIs; 12-month billed vs collected (or year
 * over year); outstanding by age as a ring; 3-month expected collections; then recent
 * invoices / top clients / unbilled work, the quote funnel and billing by service.
 * Every figure still opens where it can be acted on.
 */
export function FinanceTab() {
  const [data, setData] = useState<DashboardFinance | null>(null)
  const [error, setError] = useState("")
  const [yoy, setYoy] = useState(false)
  const [list, setList] = useState<"recent" | "clients" | "unbilled">("recent")
  const [group, setGroup] = useState<"services" | "offices">("services")

  useEffect(() => { api.getDashboardFinance().then(setData).catch((e: Error) => setError(e.message)) }, [])

  if (error) return <p className="py-10 text-center text-sm text-destructive">{error}</p>
  if (!data) return <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>

  const go = (url: string) => router.visit(url)
  const ageing = data.ageing.map((b) => ({ code: b.key, name: `${b.label} (${b.count})`, value: Math.round(b.amount), color: AGEING_COLOR[b.key] }))
  const breakdown = data.breakdown[group]
  const axis = { tick: { fontSize: 10 }, tickLine: false, axisLine: false, stroke: "var(--muted-foreground)" } as const
  const tip = { contentStyle: { fontSize: 12, borderRadius: 10 }, cursor: { fill: "var(--muted)", opacity: 0.4 }, formatter: (v: number) => inr(Number(v)) }

  return (
    <motion.div variants={staggerParent} initial="hidden" animate="show"
      className="flex flex-col gap-3 fit:grid fit:h-full fit:grid-rows-[auto_minmax(0,1.15fr)_minmax(0,1fr)]">
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <KpiTile label="Billed (all time)" value={data.billed} format="lakh" icon={Receipt} accent="blue" spark={data.monthly.map((m) => m.billed)} />
        <KpiTile label="Collected" value={data.collected} format="lakh" icon={Wallet} accent="emerald" spark={data.monthly.map((m) => m.collected)} />
        <KpiTile label="Outstanding" value={data.outstanding} format="lakh" icon={Landmark} accent="rose" onClick={() => go("/pending-payments")}
          subtitle={`${data.ageing.filter((b) => b.key !== "current").reduce((s, b) => s + b.count, 0)} invoices overdue`} />
        <KpiTile label="Collection rate" value={data.rate} format="percent" icon={BadgePercent} accent="gold" subtitle="of everything billed is received" />
      </div>

      <div className="grid min-h-0 gap-3 lg:grid-cols-12">
        <GlassCard className="min-h-[280px] lg:col-span-6" title={yoy ? "Billed: this year vs last" : "Billed vs collected"} icon={<TrendingUp className="h-4 w-4 text-gold" />}
          subtitle={yoy ? "Each month against the same month a year earlier" : "12 months · billed by invoice date, collected by payment date"}
          actions={<SegmentTabs size="sm" value={yoy ? "yoy" : "bc"} onChange={(v) => setYoy(v === "yoy")} options={[{ value: "bc", label: "Billed vs collected" }, { value: "yoy", label: "Year over year" }]} />}>
          <div className="h-full min-h-[220px] px-2 pb-2">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.monthly} margin={{ top: 14, right: 12, left: 0, bottom: 0 }} barGap={3}>
                <Bar3DDefs />
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" {...axis} />
                <YAxis {...axis} width={54} tickFormatter={(v) => lakh(Number(v))} />
                <Tooltip {...tip} />
                {yoy && <Bar dataKey="prev_billed" name="Same month last year" fill="#94a3b8" shape={<Bar3DShape />} maxBarSize={22} />}
                <Bar dataKey="billed" name={yoy ? "This year" : "Billed"} fill="#3b82f6" shape={<Bar3DShape />} maxBarSize={22} />
                {!yoy && <Bar dataKey="collected" name="Collected" fill="#10b981" shape={<Bar3DShape />} maxBarSize={22} />}
              </BarChart>
            </ResponsiveContainer>
          </div>
        </GlassCard>

        <GlassCard className="min-h-[280px] lg:col-span-3" title="Outstanding by age" subtitle="Click a slice for the list" scroll bodyClassName="px-3 pb-3">
          <Ring3D data={ageing} total={data.outstanding} format="lakh" label="outstanding" size={138} thickness={18}
            onSelect={(code) => go(code === "current" ? "/pending-payments" : "/pending-payments?overdue=1")} />
        </GlassCard>

        <GlassCard className="min-h-[280px] lg:col-span-3" title="Expected collections" icon={<CalendarClock className="h-4 w-4 text-gold" />} subtitle="Next 3 months · invoices + renewal estimates">
          <div className="h-full min-h-[200px] px-1 pb-2">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.forecast} margin={{ top: 14, right: 10, left: 0, bottom: 0 }}>
                <Bar3DDefs />
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" {...axis} />
                <YAxis {...axis} width={50} tickFormatter={(v) => lakh(Number(v))} />
                <Tooltip {...tip} />
                <Bar dataKey="invoices" name="Invoices due" stackId="f" fill="#f59e0b" shape={<Bar3DShape />} maxBarSize={34} />
                <Bar dataKey="renewals" name="Renewal fees" stackId="f" fill="#8b5cf6" shape={<Bar3DShape />} maxBarSize={34} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </GlassCard>
      </div>

      <div className="grid min-h-0 gap-3 lg:grid-cols-12">
        <GlassCard className="min-h-[240px] lg:col-span-6" scroll bodyClassName="px-2 pb-2"
          title={list === "recent" ? "Recent invoices" : list === "clients" ? "Clients owing the most" : `Work done, not invoiced · ${data.unbilled.count}`}
          actions={<>
            <SegmentTabs size="sm" value={list} onChange={setList} options={[
              { value: "recent", label: "Recent" }, { value: "clients", label: "Top clients" },
              { value: "unbilled", label: "Not invoiced", badge: data.unbilled.count },
            ]} />
            <Link href="/financial" className="hidden text-muted-foreground hover:text-gold sm:block" title="Financial Suite"><ArrowUpRight className="h-4 w-4" /></Link>
          </>}>
          {list === "recent" && (data.recent.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">No invoices yet</p> : data.recent.map((i) => (
            <button key={`${i.source}-${i.id}`} onClick={() => go(i.outstanding > 0 ? `/pending-payments?search=${encodeURIComponent(i.number ?? "")}` : "/financial")}
              className="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left hover:bg-muted/50">
              <div className="min-w-0"><p className="font-mono text-xs font-semibold text-gold">{i.number}</p><p className="truncate text-[11px] text-muted-foreground">{i.client ?? "—"}</p></div>
              <div className="flex shrink-0 items-center gap-2">
                <span className="text-sm font-medium">{inr(i.amount)}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${i.outstanding <= 0 ? "bg-emerald-500/15 text-emerald-600" : i.overdue ? "bg-rose-500/15 text-rose-600" : "bg-amber-500/15 text-amber-600"}`}>{i.state}</span>
              </div>
            </button>
          )))}
          {list === "clients" && (data.top_clients.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">Nothing outstanding.</p> : data.top_clients.map((c) => (
            <HBar3D key={c.client_id} label={`${c.name} · ${c.invoices} unpaid`} value={c.outstanding} max={data.top_clients[0]?.outstanding || 1} color="#f59e0b"
              right={inr(c.outstanding)} onClick={() => go(`/pending-payments?search=${encodeURIComponent(c.client_code ?? c.name)}`)} />
          )))}
          {list === "unbilled" && (data.unbilled.items.length === 0 ? <p className="py-8 text-center text-sm text-muted-foreground">Every filed case has an invoice.</p> : data.unbilled.items.map((u) => (
            <button key={u.id} onClick={() => go(`/projects/${u.id}`)} className="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left hover:bg-muted/50">
              <div className="min-w-0"><p className="font-mono text-xs font-semibold text-gold">{u.docket_number}</p><p className="truncate text-[11px] text-muted-foreground">{u.project_name ?? "—"} · {u.client ?? ""}</p></div>
              <span className="shrink-0 text-[11px] text-muted-foreground">{u.status}</span>
            </button>
          )))}
        </GlassCard>

        <GlassCard className="min-h-[240px] lg:col-span-3" title="Quote to payment" icon={<Filter className="h-4 w-4 text-gold" />} scroll bodyClassName="space-y-2 px-3 pb-3">
          {data.funnel.map((step, i) => {
            const top = data.funnel[0]?.count || 1
            const prev = i > 0 ? data.funnel[i - 1].count : 0
            const width = Math.max(18, Math.min(100, (step.count / top) * 100))
            return (
              <div key={step.stage}>
                <div className="mx-auto flex h-8 items-center justify-between rounded-lg px-2.5 text-[11px] font-medium text-white shadow-3d transition-all"
                  style={{ width: `${width}%`, background: `linear-gradient(135deg, hsl(${220 - i * 35} 75% 55%), hsl(${200 - i * 35} 75% 45%))` }}>
                  <span className="truncate">{step.stage}</span><span className="font-mono">{step.count}</span>
                </div>
                <p className="mt-0.5 text-center text-[10px] text-muted-foreground">{lakh(step.amount)}{i > 0 && i < 3 && prev > 0 ? ` · ${Math.round((step.count / prev) * 100)}% of previous` : ""}</p>
              </div>
            )
          })}
        </GlassCard>

        <GlassCard className="min-h-[240px] lg:col-span-3" title="Billed by" scroll bodyClassName="px-2 pb-3"
          actions={<SegmentTabs size="sm" value={group} onChange={setGroup} options={[{ value: "services", label: "Service" }, { value: "offices", label: "Office" }]} />}>
          {breakdown.map((r) => <HBar3D key={r.code} label={r.code} value={r.billed} max={breakdown[0]?.billed || 1} right={lakh(r.billed)} color={group === "services" ? "#3b82f6" : "#10b981"} />)}
        </GlassCard>
      </div>
    </motion.div>
  )
}
