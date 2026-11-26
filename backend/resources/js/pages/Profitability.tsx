import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { Download, Loader2, Settings2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { TableCard } from "@/components/modern/table-card";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { api } from "@/lib/api-client";
import { Bars3D, inrShort } from "@/components/three/chart-3d";

type Row = Record<string, any>;
const inr = (v: number) => `₹${Math.round(Number(v)).toLocaleString("en-IN")}`;
const GROUPS = [["matter", "Matter"], ["client", "Client"], ["service", "Service"], ["office", "Office"], ["manager", "Client Manager"]] as const;

/** Finance → Profitability: fees billed (without GST) less official fees, associate and other costs and staff time. */
export default function Profitability() {
  const { props } = usePage() as any;
  const isAccounts = !!props.auth?.user?.access?.accounts;
  const [by, setBy] = useState<(typeof GROUPS)[number][0]>("matter");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [lowOnly, setLowOnly] = useState(false);
  const [sort, setSort] = useState<{ key: string; dir: 1 | -1 }>({ key: "billed", dir: -1 });
  const [data, setData] = useState<{ rows: Row[]; total: Row; rates_set: boolean } | null>(null);
  const [loading, setLoading] = useState(true);
  const [ratesOpen, setRatesOpen] = useState(false);
  const [showChart, setShowChart] = useState(true);
  const [focus, setFocus] = useState<string | null>(null);

  const query = () => { const p = new URLSearchParams({ by }); if (from) p.set("from", from); if (to) p.set("to", to); return p; };
  const load = () => {
    setLoading(true);
    api.request<any>(`/finance/profitability?${query()}`).then(setData).catch((e: any) => toast.error(e?.message ?? "Could not load.")).finally(() => setLoading(false));
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [by, from, to]);

  const download = async () => {
    const p = query(); p.set("export", "xlsx");
    const res = await fetch(`/api/finance/profitability?${p}`, { credentials: "same-origin" });
    if (!res.ok) { toast.error("Export failed."); return; }
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a"); a.href = url; a.download = `Profitability-${by}.xlsx`; a.click(); URL.revokeObjectURL(url);
  };

  const rows = useMemo(() => (data?.rows ?? [])
    .filter((r) => !lowOnly || (r.margin_pct !== null && r.margin_pct < 20))
    .filter((r) => !focus || String(by === "matter" ? r.docket : r.group) === focus)
    .sort((a, b) => ((a[sort.key] ?? -Infinity) > (b[sort.key] ?? -Infinity) ? 1 : -1) * sort.dir), [data, lowOnly, sort, focus, by]);
  const t = data?.total ?? {};
  const totalPct = t.billed > 0 ? Math.round((t.margin / t.billed) * 1000) / 10 : null;
  const head = (key: string, label: string, right = true) => (
    <th className={`cursor-pointer px-3 py-2 ${right ? "text-right" : "text-left"} hover:text-foreground`} onClick={() => setSort((s) => ({ key, dir: s.key === key ? (s.dir === 1 ? -1 : 1) : -1 }))}>
      {label}{sort.key === key ? (sort.dir === 1 ? " ↑" : " ↓") : ""}
    </th>
  );

  return (
    <AppLayout>
      <Head title="Profitability" />
      <FitPage eyebrow="Finance" title="Profitability"
        summary="Fees billed (without GST) less Patent Office fees paid, foreign associate and other costs, and staff time at internal cost rates. INR."
        actions={<div className="flex gap-2">
          {isAccounts && <Button size="sm" variant="outline" onClick={() => setRatesOpen(true)}><Settings2 className="mr-1.5 h-4 w-4" />Time cost rates</Button>}
          <Button size="sm" variant="outline" onClick={download}><Download className="mr-1.5 h-4 w-4" />Excel</Button>
        </div>}>
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          <div className="flex shrink-0 flex-wrap gap-2">
            {[
              ["Billed (ex GST)", inr(t.billed ?? 0), "#3b82f6"], ["Official fees paid", inr(t.official_paid ?? 0), "#06b6d4"], ["Associates", inr(t.associate ?? 0), "#8b5cf6"],
              ["Time cost", `${inr(t.time_cost ?? 0)} · ${Math.round(t.hours ?? 0)} h`, "#f59e0b"], ["Margin", `${inr(t.margin ?? 0)}${totalPct !== null ? ` · ${totalPct}%` : ""}`, (t.margin ?? 0) < 0 ? "#ef4444" : "#16a34a"],
              ["Official fees not billed", inr(t.leakage ?? 0), "#ef4444"],
            ].map(([label, value, color]) => (
              <div key={label} className="card-3d glass flex min-w-[130px] flex-1 items-center gap-2 rounded-xl px-2.5 py-1">
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: color, boxShadow: `0 0 8px ${color}` }} />
                <span className="min-w-0"><span className="block truncate font-display text-sm font-semibold leading-tight xl:text-base">{value}</span>
                  <span className="block truncate text-[9px] uppercase tracking-wider text-muted-foreground">{label}</span></span>
              </div>
            ))}
          </div>
          {data && !data.rates_set && <p className="shrink-0 text-[11px] text-amber-600">Time cost rates are not set, so time counts as ₹0.{isAccounts ? " Set them with \"Time cost rates\"." : " Ask Accounts to set them."}</p>}
          <div className="flex shrink-0 flex-wrap items-center gap-2 text-xs">
            <div className="inline-flex rounded-md border border-border p-0.5">
              {GROUPS.map(([v, l]) => <button key={v} onClick={() => setBy(v)} className={`rounded px-2.5 py-1 ${by === v ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}>{l}</button>)}
            </div>
            <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="From" />
            <span className="text-muted-foreground">–</span>
            <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-1.5" title="To" />
            <label className="flex items-center gap-1.5 text-muted-foreground"><input type="checkbox" checked={lowOnly} onChange={(e) => setLowOnly(e.target.checked)} />Margin under 20%</label>
            <button className="text-[11px] text-muted-foreground hover:text-foreground" onClick={() => setShowChart((v) => !v)}>{showChart ? "Hide" : "Show"} 3D chart</button>
            {focus && <button className="rounded-full border border-gold/40 bg-gold/10 px-2 py-0.5 text-[11px] text-gold" onClick={() => setFocus(null)}>Showing {focus} ✕</button>}
          </div>
          {showChart && (data?.rows.length ?? 0) > 0 && (
            <div className="shrink-0">
              {/* The 12 largest by fees billed: billed, costs (official + associate + other + time) and margin. */}
              <Bars3D height={200} format={inrShort} activeLabel={focus}
                data={[...(data?.rows ?? [])].filter((r) => !lowOnly || (r.margin_pct !== null && r.margin_pct < 20)).sort((a, b) => (b.billed ?? 0) - (a.billed ?? 0)).slice(0, 12).map((r) => ({
                  label: String(by === "matter" ? r.docket : r.group ?? "—"),
                  values: { billed: r.billed ?? 0, costs: (r.official_paid ?? 0) + (r.associate ?? 0) + (r.other ?? 0) + (r.time_cost ?? 0), margin: r.margin ?? 0 } }))}
                series={[{ key: "billed", label: "Billed (ex GST)", color: "#3b82f6" }, { key: "costs", label: "Costs + time", color: "#f59e0b" }, { key: "margin", label: "Margin", color: "#16a34a" }]}
                onSelect={(label) => setFocus(focus === label ? null : label)} />
            </div>
          )}
          <TableCard>
            <table className="w-full min-w-[1000px] text-xs">
              <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  {by === "matter" ? <><th className="px-3 py-2 text-left">Matter</th><th className="px-3 py-2 text-left">Client</th></> : head("group", GROUPS.find((g) => g[0] === by)![1], false)}
                  {by !== "matter" && head("matters", "Matters")}
                  {head("billed", "Billed")}{head("collected", "Collected")}{head("official_paid", "Official fees")}{head("associate", "Associates")}
                  {head("other", "Other")}{head("time_cost", "Time")}{head("margin", "Margin")}{head("margin_pct", "%")}{head("leakage", "Not billed")}
                </tr>
              </thead>
              <tbody className="table-rows-3d">
                {loading && !data && <tr><td colSpan={12} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                {data && rows.length === 0 && <tr><td colSpan={12} className="py-12 text-center text-sm text-muted-foreground">Nothing to show for this view.</td></tr>}
                {rows.map((r, i) => (
                  <tr key={r.project_id ?? r.group ?? i} className="border-t border-border/60">
                    {by === "matter" ? <>
                      <td className="max-w-[220px] px-3 py-2"><div className="font-mono text-[11px]">{r.docket}</div><div className="truncate text-[10px] text-muted-foreground">{r.name}</div></td>
                      <td className="max-w-[180px] truncate px-3 py-2">{r.client?.code} · {r.client?.name}</td>
                    </> : <td className="max-w-[260px] truncate px-3 py-2 font-medium">{r.group}</td>}
                    {by !== "matter" && <td className="px-3 py-2 text-right">{r.matters}</td>}
                    <td className="px-3 py-2 text-right font-mono">{inr(r.billed)}</td>
                    <td className="px-3 py-2 text-right font-mono text-muted-foreground">{inr(r.collected)}</td>
                    <td className="px-3 py-2 text-right font-mono">{inr(r.official_paid)}</td>
                    <td className="px-3 py-2 text-right font-mono">{inr(r.associate)}</td>
                    <td className="px-3 py-2 text-right font-mono">{inr(r.other)}</td>
                    <td className="px-3 py-2 text-right font-mono" title={`${r.hours} hours`}>{inr(r.time_cost)}</td>
                    <td className={`px-3 py-2 text-right font-mono font-semibold ${r.margin < 0 ? "text-red-600" : "text-emerald-600"}`}>{inr(r.margin)}</td>
                    <td className={`px-3 py-2 text-right ${r.margin_pct !== null && r.margin_pct < 20 ? "text-amber-600" : ""}`}>{r.margin_pct === null ? "—" : `${r.margin_pct}%`}</td>
                    <td className={`px-3 py-2 text-right font-mono ${r.leakage > 0 ? "text-red-600" : "text-muted-foreground"}`}>{r.leakage > 0 ? inr(r.leakage) : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableCard>
        </div>
      </FitPage>
      {ratesOpen && <RatesDialog onClose={() => setRatesOpen(false)} onSaved={() => { setRatesOpen(false); load(); }} />}
    </AppLayout>
  );
}

function RatesDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [data, setData] = useState<{ default: number; designations: Record<string, number>; designation_list: { id: number; title: string }[] } | null>(null);
  const [def, setDef] = useState("");
  const [rates, setRates] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    api.request<any>("/finance/time-cost-rates").then((d) => {
      setData(d); setDef(String(d.default ?? 0));
      setRates(Object.fromEntries(Object.entries(d.designations ?? {}).map(([k, v]) => [k, String(v)])));
    }).catch((e: any) => toast.error(e?.message ?? "Could not load rates."));
  }, []);
  const save = async () => {
    setSaving(true);
    try {
      await api.request("/finance/time-cost-rates", { method: "PUT", body: JSON.stringify({ default: Number(def || 0),
        designations: Object.fromEntries(Object.entries(rates).filter(([, v]) => v !== "").map(([k, v]) => [k, Number(v)])) }) });
      toast.success("Rates saved"); onSaved();
    } catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent flip3d className="max-w-md">
        <DialogHeader><DialogTitle>Internal time cost rates (₹ per hour)</DialogTitle></DialogHeader>
        <p className="text-[11px] text-muted-foreground">What an hour of each designation costs the firm. Used only for profitability, seen only by Accounts; salaries are never read.</p>
        {!data ? <Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /> : (
          <div className="max-h-[50vh] space-y-1.5 overflow-auto text-xs">
            <label className="flex items-center justify-between gap-2 font-medium">Default (no designation)
              <input type="number" min="0" value={def} onChange={(e) => setDef(e.target.value)} className="h-7 w-28 rounded-md border border-border bg-background px-2 text-right" /></label>
            {data.designation_list.map((d) => (
              <label key={d.id} className="flex items-center justify-between gap-2">{d.title}
                <input type="number" min="0" placeholder={def || "0"} value={rates[String(d.id)] ?? ""} onChange={(e) => setRates((r) => ({ ...r, [String(d.id)]: e.target.value }))}
                  className="h-7 w-28 rounded-md border border-border bg-background px-2 text-right" /></label>
            ))}
          </div>
        )}
        <div className="flex justify-end gap-2"><Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={saving || !data} onClick={save}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save"}</Button></div>
      </DialogContent>
    </Dialog>
  );
}
