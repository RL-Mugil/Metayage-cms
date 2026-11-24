import { useEffect, useState } from "react"
import { router, Link } from "@inertiajs/react"
import { ArrowUpRight, Loader2, TrendingUp, Users, FileText, CalendarClock, Filter, Receipt } from "lucide-react"
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { api, type DashboardFinance } from "@/lib/api-client"

const inr = (value: number) => `₹${Math.round(value).toLocaleString("en-IN")}`
const lakh = (value: number) => (Math.abs(value) >= 100000 ? `₹${(value / 100000).toFixed(1)}L` : inr(value))

/** Ageing colours: not yet due → 90+ days late. */
const AGEING_COLOR: Record<string, string> = {
  current: "bg-emerald-500", "1_30": "bg-amber-400", "31_60": "bg-orange-500", "61_90": "bg-red-500", "90_plus": "bg-red-700",
}

/**
 * Dashboard → Financial: collection rate with an ageing bar, 12-month billed vs collected,
 * recent invoices and the clients owing the most. All from the portal register; every
 * figure links to where it can be acted on (Pending Payments).
 */
export function FinanceTab() {
  const [data, setData] = useState<DashboardFinance | null>(null)
  const [error, setError] = useState("")
  const [yoy, setYoy] = useState(false)

  useEffect(() => {
    api.getDashboardFinance().then(setData).catch((e: Error) => setError(e.message))
  }, [])

  if (error) return <p className="py-10 text-center text-sm text-destructive">{error}</p>
  if (!data) return <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>

  const outstanding = data.ageing.reduce((sum, b) => sum + b.amount, 0)
  const go = (url: string) => router.visit(url)

  return (
    <div className="space-y-6">
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Collection rate + ageing */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><TrendingUp className="h-4 w-4 text-gold" /> Collection rate</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-end gap-3">
              <div className="font-display text-4xl font-semibold">{data.rate}%</div>
              <p className="pb-1 text-xs text-muted-foreground">of everything billed has been received</p>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div className="h-full rounded-full bg-gradient-to-r from-primary to-gold" style={{ width: `${Math.min(data.rate, 100)}%` }} />
            </div>
            <div className="grid grid-cols-3 gap-2 text-center">
              <div className="rounded-md bg-muted/40 p-2"><p className="text-[10px] uppercase tracking-wider text-muted-foreground">Billed</p><p className="text-sm font-semibold">{lakh(data.billed)}</p></div>
              <div className="rounded-md bg-emerald-500/10 p-2"><p className="text-[10px] uppercase tracking-wider text-muted-foreground">Collected</p><p className="text-sm font-semibold text-emerald-600">{lakh(data.collected)}</p></div>
              <button onClick={() => go("/pending-payments")} className="rounded-md bg-amber-500/10 p-2 hover:bg-amber-500/20">
                <p className="text-[10px] uppercase tracking-wider text-muted-foreground">Outstanding</p><p className="text-sm font-semibold text-amber-600">{lakh(data.outstanding)}</p>
              </button>
            </div>
            <div>
              <p className="mb-1.5 text-xs font-medium text-muted-foreground">Outstanding by days overdue</p>
              <div className="flex h-3 w-full overflow-hidden rounded-full bg-muted">
                {outstanding > 0 && data.ageing.map((b) => b.amount > 0 && (
                  <button key={b.key} title={`${b.label}: ${inr(b.amount)} · ${b.count} invoices`}
                    onClick={() => go(b.key === "current" ? "/pending-payments" : "/pending-payments?overdue=1")}
                    className={`${AGEING_COLOR[b.key]} h-full transition-opacity hover:opacity-80`} style={{ width: `${(b.amount / outstanding) * 100}%` }} />
                ))}
              </div>
              <div className="mt-2 grid grid-cols-1 gap-1">
                {data.ageing.map((b) => (
                  <button key={b.key} onClick={() => go(b.key === "current" ? "/pending-payments" : "/pending-payments?overdue=1")}
                    className="flex items-center justify-between rounded px-1 py-0.5 text-xs hover:bg-muted/40">
                    <span className="flex items-center gap-1.5"><span className={`h-2 w-2 rounded-full ${AGEING_COLOR[b.key]}`} />{b.label}</span>
                    <span className="font-mono">{inr(b.amount)} <span className="text-muted-foreground">({b.count})</span></span>
                  </button>
                ))}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 12 months billed vs collected */}
        <Card className="border-border lg:col-span-2">
          <CardHeader>
            <div className="flex items-start justify-between gap-2">
              <div>
                <CardTitle className="font-display">{yoy ? "Billed: this year vs last year" : "Billed vs collected · last 12 months"}</CardTitle>
                <p className="text-xs text-muted-foreground">{yoy ? "Each month against the same month a year earlier" : "Billed by invoice date, collected by payment date"}</p>
              </div>
              <div className="flex rounded-md border border-border p-0.5 text-[11px]">
                <button onClick={() => setYoy(false)} className={`rounded px-2 py-0.5 ${!yoy ? "bg-muted font-medium" : "text-muted-foreground"}`}>Billed vs collected</button>
                <button onClick={() => setYoy(true)} className={`rounded px-2 py-0.5 ${yoy ? "bg-muted font-medium" : "text-muted-foreground"}`}>Year over year</button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.monthly} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" />
                <YAxis tickFormatter={(v) => lakh(Number(v))} tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" width={64} />
                <Tooltip formatter={(v: number) => inr(Number(v))} contentStyle={{ fontSize: 12 }} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                {yoy && <Bar dataKey="prev_billed" name="Same month last year" fill="#94a3b8" radius={[3, 3, 0, 0]} />}
                <Bar dataKey="billed" name={yoy ? "This year" : "Billed"} fill="#3b82f6" radius={[3, 3, 0, 0]} />
                {!yoy && <Bar dataKey="collected" name="Collected" fill="#10b981" radius={[3, 3, 0, 0]} />}
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Recent invoices */}
        <Card className="border-border lg:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="font-display flex items-center gap-2"><FileText className="h-4 w-4 text-gold" /> Recent invoices</CardTitle>
            <Button asChild variant="ghost" size="sm"><Link href="/financial">View all <ArrowUpRight className="ml-1 h-3 w-3" /></Link></Button>
          </CardHeader>
          <CardContent className="p-0">
            {data.recent.length === 0 ? <p className="px-4 py-6 text-center text-sm text-muted-foreground">No invoices yet</p> : (
              <table className="w-full text-sm">
                <tbody>
                  {data.recent.map((i) => (
                    <tr key={`${i.source}-${i.id}`} onClick={() => go(i.outstanding > 0 ? `/pending-payments?search=${encodeURIComponent(i.number ?? "")}` : "/financial")}
                      className="cursor-pointer border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs text-gold">{i.number}</td>
                      <td className="max-w-[220px] truncate px-4 py-3">{i.client ?? "—"}</td>
                      <td className="px-4 py-3 text-right font-medium">{inr(i.amount)}</td>
                      <td className="px-4 py-3 text-right">
                        <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${i.outstanding <= 0 ? "bg-emerald-500/15 text-emerald-600" : i.overdue ? "bg-red-500/15 text-red-600" : "bg-amber-500/15 text-amber-600"}`}>{i.state}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        {/* Clients owing the most */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><Users className="h-4 w-4 text-gold" /> Top clients by outstanding</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2.5">
            {data.top_clients.length === 0 ? <p className="py-4 text-center text-sm text-muted-foreground">Nothing outstanding.</p> : data.top_clients.map((c) => (
              <button key={c.client_id} onClick={() => go(`/pending-payments?search=${encodeURIComponent(c.client_code ?? c.name)}`)}
                className="block w-full rounded-md p-1.5 text-left hover:bg-muted/40">
                <div className="flex items-center justify-between gap-2 text-xs">
                  <span className="truncate font-medium">{c.name}</span>
                  <span className="font-mono">{inr(c.outstanding)}</span>
                </div>
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                  <div className="h-full rounded-full bg-amber-500" style={{ width: `${(c.outstanding / (data.top_clients[0]?.outstanding || 1)) * 100}%` }} />
                </div>
                <p className="mt-0.5 text-[10px] text-muted-foreground">{c.client_code} · {c.invoices} unpaid invoice{c.invoices === 1 ? "" : "s"}</p>
              </button>
            ))}
          </CardContent>
        </Card>
      </div>
      <div className="grid gap-6 lg:grid-cols-3">
        {/* Expected collections */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><CalendarClock className="h-4 w-4 text-gold" /> Expected collections · next 3 months</CardTitle>
            <p className="text-xs text-muted-foreground">Unpaid invoices by due date (overdue counted now) plus renewal fee estimates</p>
          </CardHeader>
          <CardContent className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.forecast} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" />
                <YAxis tickFormatter={(v) => lakh(Number(v))} tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" width={60} />
                <Tooltip formatter={(v: number) => inr(Number(v))} contentStyle={{ fontSize: 12 }} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="invoices" name="Invoices due" stackId="f" fill="#f59e0b" />
                <Bar dataKey="renewals" name="Renewal fees" stackId="f" fill="#8b5cf6" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        {/* Quote funnel */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><Filter className="h-4 w-4 text-gold" /> Quote to payment</CardTitle>
            <p className="text-xs text-muted-foreground">How many quotes become paid work</p>
          </CardHeader>
          <CardContent className="space-y-2">
            {data.funnel.map((step, i) => {
              const top = data.funnel[0]?.count || 1
              const prev = i > 0 ? data.funnel[i - 1].count : 0
              return (
                <div key={step.stage}>
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-medium">{step.stage}</span>
                    <span className="font-mono">{step.count} · {lakh(step.amount)}</span>
                  </div>
                  <div className="mt-1 h-5 w-full rounded bg-muted">
                    <div className="h-full rounded bg-gradient-to-r from-primary/80 to-gold/80" style={{ width: `${Math.max(4, Math.min(100, (step.count / top) * 100))}%` }} />
                  </div>
                  {i > 0 && i < 3 && prev > 0 && <p className="mt-0.5 text-[10px] text-muted-foreground">{Math.round((step.count / prev) * 100)}% of the step before</p>}
                </div>
              )
            })}
          </CardContent>
        </Card>

        {/* Billed by service and office */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">Billed by service and office</CardTitle>
            <p className="text-xs text-muted-foreground">All invoices in the register</p>
          </CardHeader>
          <CardContent className="space-y-4">
            {(["services", "offices"] as const).map((group) => {
              const rows = data.breakdown[group]
              const max = rows[0]?.billed || 1
              return (
                <div key={group}>
                  <p className="mb-1 text-[10px] uppercase tracking-wider text-muted-foreground">{group === "services" ? "Service code" : "Patent office"}</p>
                  <div className="space-y-1">
                    {rows.slice(0, group === "services" ? 6 : 4).map((r) => (
                      <div key={r.code} className="flex items-center gap-2 text-xs">
                        <span className="w-10 shrink-0 font-mono">{r.code}</span>
                        <div className="h-2 flex-1 rounded bg-muted"><div className={`h-full rounded ${group === "services" ? "bg-blue-500" : "bg-emerald-500"}`} style={{ width: `${(r.billed / max) * 100}%` }} /></div>
                        <span className="w-16 shrink-0 text-right font-mono">{lakh(r.billed)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )
            })}
          </CardContent>
        </Card>
      </div>

      {data.unbilled.count > 0 && (
        <Card className="border-amber-500/40">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2"><Receipt className="h-4 w-4 text-amber-500" /> Work done, not invoiced · {data.unbilled.count} cases</CardTitle>
            <p className="text-xs text-muted-foreground">Filed or granted cases with no invoice in the register for their docket. Click one to open the case.</p>
          </CardHeader>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <tbody>
                {data.unbilled.items.map((u) => (
                  <tr key={u.id} onClick={() => go(`/projects/${u.id}`)} className="cursor-pointer border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-2.5 font-mono text-xs text-gold">{u.docket_number}</td>
                    <td className="max-w-[280px] truncate px-4 py-2.5">{u.project_name ?? "—"}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{u.client ?? "—"}</td>
                    <td className="px-4 py-2.5 text-right text-xs">{u.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
