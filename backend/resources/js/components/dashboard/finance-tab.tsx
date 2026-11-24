import { useEffect, useState } from "react"
import { router, Link } from "@inertiajs/react"
import { ArrowUpRight, Loader2, TrendingUp, Users, FileText } from "lucide-react"
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
            <CardTitle className="font-display">Billed vs collected · last 12 months</CardTitle>
            <p className="text-xs text-muted-foreground">Billed by invoice date, collected by payment date</p>
          </CardHeader>
          <CardContent className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.monthly} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" />
                <YAxis tickFormatter={(v) => lakh(Number(v))} tick={{ fontSize: 11 }} stroke="hsl(var(--muted-foreground))" width={64} />
                <Tooltip formatter={(v: number) => inr(Number(v))} contentStyle={{ fontSize: 12 }} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="billed" name="Billed" fill="#3b82f6" radius={[3, 3, 0, 0]} />
                <Bar dataKey="collected" name="Collected" fill="#10b981" radius={[3, 3, 0, 0]} />
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
    </div>
  )
}
