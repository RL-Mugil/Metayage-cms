import { useEffect, useState } from "react"
import { Link } from "@inertiajs/react"
import { FileDown, Loader2 } from "lucide-react"
import { KpiStrip } from "@/components/modern/kpi-strip"
import { TableCard } from "@/components/modern/table-card"
import { Donut3D, inrShort } from "@/components/three/chart-3d"
import { api } from "@/lib/api-client"

export interface ClientBilling {
  client_id: number
  client_code: string
  summary: { billed: number; received: number; tds: number; balance: number; quoted: number; total_receivable: number; unpaid_invoices: number; unpaid_count: number; overdue: number; advance: number }
  unpaid: { id: number; number: string; docket: string | null; title: string | null; date: string | null; due_date: string | null; days_overdue: number; amount: number; balance: number; currency: string; balance_inr: number; status: string }[]
  proformas: { id: number; number: string; date: string | null; status: string; amount: number; net_due: number; title: string | null }[]
}

const money = (v: number, c = "INR") => `${c === "INR" ? "₹" : `${c} `}${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`

export function useClientBilling() {
  const [data, setData] = useState<ClientBilling | null>(null)
  const [error, setError] = useState("")
  useEffect(() => { api.request<ClientBilling>("/my-billing").then(setData).catch((e) => setError((e as Error).message)) }, [])
  return { data, error }
}

/**
 * The client's billing from the portal's own register (native invoices, proformas, payments):
 * total payable, unpaid invoices and proformas, and the statement. Shown first on the client's
 * Invoices & Payments page.
 */
export function ClientBillingPanel() {
  const { data, error } = useClientBilling()
  const [downloading, setDownloading] = useState<string | null>(null)

  if (error) return <p className="text-sm text-red-600">{error}</p>
  if (!data) return <div className="flex h-32 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
  const s = data.summary
  const net = Number(s.total_receivable)

  const download = async (format: "pdf" | "xlsx") => {
    setDownloading(format)
    try { await api.downloadClientStatement(data.client_id, format, "client") } finally { setDownloading(null) }
  }

  return (
    <div className="space-y-3">
      <KpiStrip items={[
        { key: "payable", label: net < -0.009 ? "Balance credit with us" : "Total payable", value: Math.abs(net), format: "inr", color: net < -0.009 ? "#16a34a" : "#dc2626" },
        { key: "invoices", label: `Unpaid invoices (${s.unpaid_count})`, value: s.unpaid_invoices, format: "inr", color: "#3b82f6" },
        { key: "proformas", label: `Proformas to pay (${data.proformas.length})`, value: s.quoted, format: "inr", color: "#8b5cf6" },
        { key: "overdue", label: "Overdue", value: s.overdue, format: "inr", color: "#f97316" },
        { key: "credit", label: "Balance credit", value: Math.max(0, -Number(s.balance)), format: "inr", color: "#16a34a" },
        { key: "paid", label: "Paid to date", value: s.received, format: "inr", color: "#22c55e" },
      ]} />

      <div className="grid gap-3 lg:grid-cols-[300px_minmax(0,1fr)]">
        <div className="glass card-3d rounded-2xl p-4">
          <p className="mb-2 text-xs font-semibold">What you owe</p>
          <Donut3D label={net < -0.009 ? "Balance credit" : "Total payable"} total={Math.abs(net)} format={inrShort} size={190}
            data={[{ code: "inv", name: "Unpaid invoices", value: s.unpaid_invoices, color: "#3b82f6" }, { code: "pro", name: "Proformas", value: s.quoted, color: "#8b5cf6" }]} />
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <span className="text-[11px] text-muted-foreground">Statement</span>
            {(["pdf", "xlsx"] as const).map((f) => (
              <button key={f} onClick={() => download(f)} disabled={downloading !== null}
                className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-[11px] hover:bg-muted disabled:opacity-50">
                {downloading === f ? <Loader2 className="h-3 w-3 animate-spin" /> : <FileDown className="h-3 w-3" />}{f === "pdf" ? "PDF" : "Excel"}
              </button>
            ))}
            <Link href="/pending-payments" className="ml-auto text-[11px] font-medium text-gold hover:underline">Pay / upload proof →</Link>
          </div>
        </div>

        <TableCard className="min-h-0" header={<p className="text-xs font-semibold">To pay: unpaid invoices and proformas</p>}>
          <table className="w-full text-xs">
            <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
              <tr><th className="px-3 py-2 text-left">Document</th><th className="px-3 py-2 text-left">Case</th><th className="px-3 py-2 text-left">Due</th><th className="px-3 py-2 text-right">Amount</th><th className="px-3 py-2 text-right">To pay</th></tr>
            </thead>
            <tbody className="table-rows-3d">
              {data.unpaid.length === 0 && data.proformas.length === 0 && <tr><td colSpan={5} className="py-8 text-center text-muted-foreground">Nothing to pay. Thank you.</td></tr>}
              {data.unpaid.map((i) => (
                <tr key={`i-${i.id}`} className="border-t border-border/60">
                  <td className="px-3 py-2"><span className="font-mono font-semibold text-gold">{i.number}</span><span className="ml-1.5 rounded bg-blue-500/10 px-1 text-[10px] text-blue-600">Invoice</span></td>
                  <td className="max-w-[220px] truncate px-3 py-2">{i.docket ?? "—"} <span className="text-muted-foreground">{i.title ?? ""}</span></td>
                  <td className="px-3 py-2 font-mono">{i.due_date ?? "—"}{i.days_overdue > 0 && <span className="ml-1 rounded-full bg-red-500/15 px-1.5 text-[10px] text-red-600">{i.days_overdue} d late</span>}</td>
                  <td className="px-3 py-2 text-right font-mono">{money(i.amount, i.currency)}</td>
                  <td className="px-3 py-2 text-right font-mono font-semibold">{money(i.balance, i.currency)}</td>
                </tr>
              ))}
              {data.proformas.map((q) => (
                <tr key={`q-${q.id}`} className="border-t border-border/60">
                  <td className="px-3 py-2"><span className="font-mono font-semibold text-gold">{q.number}</span><span className="ml-1.5 rounded bg-violet-500/10 px-1 text-[10px] text-violet-600">Proforma</span></td>
                  <td className="max-w-[220px] truncate px-3 py-2 text-muted-foreground">{q.title ?? "—"}</td>
                  <td className="px-3 py-2 font-mono">{q.date ?? "—"}</td>
                  <td className="px-3 py-2 text-right font-mono">{money(q.amount)}</td>
                  <td className="px-3 py-2 text-right font-mono font-semibold">{money(q.net_due)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableCard>
      </div>
    </div>
  )
}
