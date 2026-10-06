import { useEffect, useState } from "react";
import { Loader2, ExternalLink } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import type { ZohoClientSummary, ZohoBooksRecord } from "@/lib/api-client";

function ZohoRecordsTable({ title, records }: { title: string; records: ZohoBooksRecord[] }) {
  if (records.length === 0) {
    return <p className="text-xs text-muted-foreground py-2">No {title.toLowerCase()} synced from Zoho Books yet.</p>;
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-xs">
        <thead className="text-muted-foreground uppercase tracking-wider">
          <tr className="text-left">
            <th className="py-1.5 pr-3">{title.slice(0, -1)} #</th>
            <th className="py-1.5 pr-3">Case</th>
            <th className="py-1.5 pr-3">Date</th>
            <th className="py-1.5 pr-3">Status</th>
            <th className="py-1.5 pr-3 text-right">Total</th>
            <th className="py-1.5 pr-3 text-right">Balance</th>
            <th className="py-1.5"></th>
          </tr>
        </thead>
        <tbody>
          {records.map((r, i) => (
            <tr key={i} className="border-t border-border">
              <td className="py-1.5 pr-3 font-mono">{r.number || "—"}</td>
              <td className="py-1.5 pr-3">
                {r.case ? <span className="font-mono">{r.case.docket_number}</span> : <span className="text-muted-foreground italic">unmatched</span>}
              </td>
              <td className="py-1.5 pr-3 text-muted-foreground">{r.date || "—"}</td>
              <td className="py-1.5 pr-3"><Badge variant="outline" className="text-[10px] capitalize">{r.status || "—"}</Badge></td>
              <td className="py-1.5 pr-3 text-right">₹{(r.total ?? 0).toLocaleString()}</td>
              <td className="py-1.5 pr-3 text-right">{r.balance != null ? `₹${r.balance.toLocaleString()}` : "—"}</td>
              <td className="py-1.5 text-right">
                {r.url && <a href={r.url} target="_blank" rel="noreferrer" className="text-muted-foreground hover:text-foreground"><ExternalLink className="h-3 w-3" /></a>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Reusable Zoho Books drill-down: outstanding balance + invoice/quote tables, each
 * linked back to a portal case by UIN. Sourced entirely from the local zoho_invoices
 * mirror (kept fresh by the zoho:sync command) — no live Zoho call happens on render.
 * Used by ClientShow.tsx (whole client), matter-workspace.tsx costs tab (one case),
 * and Financial.tsx (a client's own billing view).
 */
export function ZohoBooksPanel({ title = "Zoho Books — Quotes & Invoices", fetchSummary }: { title?: string; fetchSummary: () => Promise<ZohoClientSummary> }) {
  const [summary, setSummary] = useState<ZohoClientSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    fetchSummary()
      .then((data) => { if (!cancelled) setSummary(data); })
      .catch((e) => { if (!cancelled) setError(e instanceof Error ? e.message : "Failed to load Zoho Books data."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Card className="border-border">
      <CardHeader><CardTitle className="font-display">{title}</CardTitle></CardHeader>
      <CardContent>
        {loading ? (
          <div className="flex items-center justify-center py-6"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
        ) : error ? (
          <p className="text-xs text-muted-foreground py-2">{error}</p>
        ) : (
          <div className="space-y-4">
            <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-3"><div>Invoice outstanding: <span className="font-semibold text-foreground">₹{(summary?.outstanding_balance ?? 0).toLocaleString()}</span></div><div>Opening balances: <span className="font-semibold text-amber-600">₹{(summary?.opening_balance ?? 0).toLocaleString()}</span></div><div>Total receivables: <span className="font-semibold text-foreground">₹{(summary?.total_receivables ?? 0).toLocaleString()}</span></div></div>
            {(summary?.opening_balances?.length ?? 0) > 0 && <div><h4 className="text-xs font-semibold text-muted-foreground mb-1.5">Opening balance receivables</h4><div className="overflow-x-auto"><table className="w-full text-xs"><thead className="text-muted-foreground uppercase tracking-wider"><tr className="text-left"><th className="py-1.5 pr-3">Customer</th><th className="py-1.5 pr-3">Date</th><th className="py-1.5 text-right">Balance</th></tr></thead><tbody>{summary?.opening_balances.map((row) => <tr key={row.id} className="border-t border-border"><td className="py-1.5 pr-3">{row.customer_name || '—'}</td><td className="py-1.5 pr-3">{row.balance_date || '—'}</td><td className="py-1.5 text-right">₹{row.balance.toLocaleString()}</td></tr>)}</tbody></table></div></div>}
            <div>
              <h4 className="text-xs font-semibold text-muted-foreground mb-1.5">Invoices</h4>
              <ZohoRecordsTable title="Invoices" records={summary?.invoices ?? []} />
            </div>
            <div>
              <h4 className="text-xs font-semibold text-muted-foreground mb-1.5">Quotes</h4>
              <ZohoRecordsTable title="Quotes" records={summary?.estimates ?? []} />
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
