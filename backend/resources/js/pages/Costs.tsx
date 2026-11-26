import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Loader2, Pencil, Plus, Search, Trash2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { TableCard } from "@/components/modern/table-card";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ProjectCombobox } from "@/components/project-combobox";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

interface Cost {
  id: number; kind: "official_fee" | "associate" | "other"; kind_label: string; vendor_name: string | null; vendor_reference: string | null; description: string | null;
  bill_date: string; paid_on: string | null; currency: string; amount: number; exchange_rate: number; amount_bcy: number; recoverable: boolean; needs_review: boolean; source: string; notes: string | null;
  project: { id: number; docket: string; name: string } | null; client: { id: number; code: string; name: string } | null;
  billed_invoice: { id: number; uin: string } | null; document: { file_name: string; storage_path: string } | null; created_by: string | null;
}
interface Data { data: Cost[]; totals: Record<string, number>; unassigned: number; unbilled: number; to_review: number; can: { edit: boolean; rates: boolean } }

const inr = (v: number) => `₹${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
const KINDS = [["official_fee", "Official fee"], ["associate", "Foreign associate"], ["other", "Other cost"]] as const;

/** Finance → Matter costs: official fees, foreign associate bills and other costs paid on matters. */
export default function Costs() {
  const [data, setData] = useState<Data | null>(null);
  const [kind, setKind] = useState("");
  const [search, setSearch] = useState("");
  const [view, setView] = useState<"all" | "unbilled" | "unassigned" | "review">("all");
  const [loading, setLoading] = useState(true);
  const [edit, setEdit] = useState<Cost | "new" | null>(null);

  const load = () => {
    setLoading(true);
    const p = new URLSearchParams();
    if (kind) p.set("kind", kind);
    if (search.trim()) p.set("search", search.trim());
    if (view === "unbilled") p.set("unbilled", "1");
    if (view === "unassigned") p.set("unassigned", "1");
    if (view === "review") p.set("review", "1");
    api.request<Data>(`/finance/costs?${p}`).then(setData).catch((e: any) => toast.error(e?.message ?? "Could not load costs.")).finally(() => setLoading(false));
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { const t = window.setTimeout(load, 250); return () => window.clearTimeout(t); }, [kind, search, view]);

  const confirm = async (c: Cost, recoverable: boolean) => {
    try { await api.request(`/finance/costs/${c.id}/confirm`, { method: "POST", body: JSON.stringify({ recoverable }) }); toast.success(recoverable ? "Confirmed: bill to client" : "Confirmed: absorbed by the firm"); load(); }
    catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  const remove = async (c: Cost) => {
    if (!window.confirm(`Delete this ${c.kind_label.toLowerCase()} of ${inr(c.amount_bcy)}?`)) return;
    try { await api.request(`/finance/costs/${c.id}`, { method: "DELETE" }); toast.success("Deleted"); load(); } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  return (
    <AppLayout>
      <Head title="Matter Costs" />
      <FitPage eyebrow="Billing" title="Matter Costs"
        summary="What the firm pays out on each matter: Patent Office fees, foreign associate bills and other costs. Recoverable costs should be billed to the client."
        actions={data?.can.edit && <Button size="sm" className="bg-gold text-black hover:bg-gold/90" onClick={() => setEdit("new")}><Plus className="mr-1.5 h-4 w-4" />Record a cost</Button>}>
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          <div className="flex shrink-0 flex-wrap gap-2">
            {[
              { label: "Official fees paid", value: inr(data?.totals.official_fee ?? 0), color: "#3b82f6" },
              { label: "Foreign associates", value: inr(data?.totals.associate ?? 0), color: "#8b5cf6" },
              { label: "Other costs", value: inr(data?.totals.other ?? 0), color: "#64748b" },
              { label: "Recoverable, not billed", value: inr(data?.unbilled ?? 0), color: "#ef4444", onClick: () => setView(view === "unbilled" ? "all" : "unbilled"), active: view === "unbilled" },
              { label: "Not tied to a case", value: String(data?.unassigned ?? 0), color: "#f59e0b", onClick: () => setView(view === "unassigned" ? "all" : "unassigned"), active: view === "unassigned" },
              { label: "To review (imported)", value: String(data?.to_review ?? 0), color: "#8b5cf6", onClick: () => setView(view === "review" ? "all" : "review"), active: view === "review" },
            ].map((c) => (
              <button key={c.label} type="button" onClick={c.onClick} disabled={!c.onClick}
                className={`glass flex min-w-[130px] flex-1 items-center gap-2 rounded-xl px-2.5 py-1 text-left ${c.onClick ? "cursor-pointer hover:-translate-y-0.5" : "cursor-default"} ${c.active ? "ring-2 ring-gold/60" : ""} transition-transform`}>
                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: c.color, boxShadow: `0 0 8px ${c.color}` }} />
                <span className="min-w-0"><span className="block truncate font-display text-sm font-semibold leading-tight xl:text-base">{c.value}</span>
                  <span className="block truncate text-[9px] uppercase tracking-wider text-muted-foreground">{c.label}</span></span>
              </button>
            ))}
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <div className="relative min-w-[200px] flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search vendor, bill number, docket…"
                className="h-8 w-full rounded-md border border-border bg-background/70 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold/25" />
            </div>
            <select value={kind} onChange={(e) => setKind(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All kinds</option>{KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <TableCard>
            <table className="w-full min-w-[900px] text-xs">
              <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                <tr><th className="px-3 py-2 text-left">Date</th><th className="px-3 py-2 text-left">Case</th><th className="px-3 py-2 text-left">Kind · vendor</th>
                  <th className="px-3 py-2 text-right">Amount</th><th className="px-3 py-2 text-left">Billed to client</th><th className="px-3 py-2" /></tr>
              </thead>
              <tbody className="table-rows-3d">
                {loading && !data && <tr><td colSpan={6} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                {data && data.data.length === 0 && <tr><td colSpan={6} className="py-12 text-center text-sm text-muted-foreground">No costs recorded{view !== "all" ? " in this view" : " yet"}.</td></tr>}
                {data?.data.map((c) => (
                  <tr key={c.id} className="border-t border-border/60">
                    <td className="whitespace-nowrap px-3 py-2 font-mono text-[11px]">{fmtDate(c.bill_date)}{c.paid_on && <div className="text-[10px] text-muted-foreground">paid {fmtDate(c.paid_on)}</div>}</td>
                    <td className="max-w-[220px] px-3 py-2">{c.project ? <><div className="font-mono text-[11px]">{c.project.docket}</div><div className="truncate text-[10px] text-muted-foreground">{c.client?.code} · {c.project.name}</div></>
                      : <span className="text-amber-600">Not tied to a case</span>}</td>
                    <td className="px-3 py-2"><div className="font-medium">{c.kind_label}</div><div className="text-[10px] text-muted-foreground">{[c.vendor_name, c.vendor_reference].filter(Boolean).join(" · ")}{c.source !== "portal" ? " · from Zoho" : ""}</div></td>
                    <td className="whitespace-nowrap px-3 py-2 text-right font-semibold">{inr(c.amount_bcy)}{c.currency !== "INR" && <div className="text-[10px] font-normal text-muted-foreground">{c.currency} {c.amount.toLocaleString("en-IN")}</div>}</td>
                    <td className="px-3 py-2">{c.needs_review ? (
                      data.can.edit ? <span className="flex flex-wrap gap-1"><span className="text-violet-600">To review:</span>
                        <button className="rounded border border-border px-1.5 text-[10px] hover:bg-muted" onClick={() => confirm(c, true)}>Bill to client</button>
                        <button className="rounded border border-border px-1.5 text-[10px] hover:bg-muted" onClick={() => confirm(c, false)}>Firm absorbs</button></span>
                        : <span className="text-violet-600">To review</span>
                    ) : !c.recoverable ? <span className="text-muted-foreground">Not recoverable</span> : c.billed_invoice ? <span className="font-mono text-emerald-600">{c.billed_invoice.uin}</span> : <span className="text-red-600">Not billed yet</span>}</td>
                    <td className="whitespace-nowrap px-3 py-2 text-right">
                      {c.document && <button className="mr-1 text-[10px] underline" onClick={() => api.downloadDocument(c.document!.storage_path, c.document!.file_name)}>Bill</button>}
                      {data.can.edit && <button className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={() => setEdit(c)}><Pencil className="h-3.5 w-3.5" /></button>}
                      {data.can.edit && c.source === "portal" && <button className="rounded p-1 text-muted-foreground hover:text-red-600" onClick={() => remove(c)}><Trash2 className="h-3.5 w-3.5" /></button>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableCard>
        </div>
      </FitPage>
      {edit && <CostDialog cost={edit === "new" ? null : edit} onClose={() => setEdit(null)} onDone={() => { setEdit(null); load(); }} />}
    </AppLayout>
  );
}

function CostDialog({ cost, onClose, onDone }: { cost: Cost | null; onClose: () => void; onDone: () => void }) {
  const [projects, setProjects] = useState<any[]>([]);
  const [invoices, setInvoices] = useState<{ id: number; invoice_uin: string }[]>([]);
  const [f, setF] = useState({
    project_id: cost?.project ? String(cost.project.id) : "", kind: cost?.kind ?? "official_fee", vendor_name: cost?.vendor_name ?? "", vendor_reference: cost?.vendor_reference ?? "",
    description: cost?.description ?? "", bill_date: cost?.bill_date ?? new Date().toISOString().slice(0, 10), paid_on: cost?.paid_on ?? "", currency: cost?.currency ?? "INR",
    amount: cost ? String(cost.amount) : "", exchange_rate: cost && cost.currency !== "INR" ? String(cost.exchange_rate) : "", recoverable: cost?.recoverable ?? true,
    billed_invoice_id: cost?.billed_invoice ? String(cost.billed_invoice.id) : "", notes: cost?.notes ?? "",
  });
  const [file, setFile] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const fromZoho = cost && cost.source !== "portal";
  useEffect(() => { api.getProjects().then((p: any) => setProjects(p ?? [])).catch(() => setProjects([])); }, []);
  useEffect(() => {
    if (!f.project_id) { setInvoices([]); return; }
    api.request<any>(`/patent-invoices/in?project_id=${f.project_id}&per_page=100`).then((r) => setInvoices((r?.data ?? []).filter((i: any) => i.type === "invoice"))).catch(() => setInvoices([]));
  }, [f.project_id]);
  const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));

  const save = async () => {
    setSaving(true);
    try {
      let documentId: number | undefined;
      if (file) {
        const project = projects.find((p) => String(p.id) === f.project_id);
        const doc: any = await api.uploadDocument(file, "Financial", project?.client_id ?? null, f.project_id ? Number(f.project_id) : null);
        documentId = doc?.id;
      }
      const body = { ...f, project_id: f.project_id ? Number(f.project_id) : null, amount: Number(f.amount), exchange_rate: f.currency === "INR" ? null : Number(f.exchange_rate),
        paid_on: f.paid_on || null, billed_invoice_id: f.billed_invoice_id ? Number(f.billed_invoice_id) : null, ...(documentId ? { document_id: documentId } : {}) };
      await api.request(cost ? `/finance/costs/${cost.id}` : "/finance/costs", { method: cost ? "PUT" : "POST", body: JSON.stringify(body) });
      toast.success(cost ? "Cost updated" : "Cost recorded");
      onDone();
    } catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };

  const input = "mt-1 h-8 w-full rounded-md border border-border bg-background px-2 text-sm";
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-xl">
        <DialogHeader><DialogTitle>{cost ? "Edit cost" : "Record a cost"}</DialogTitle></DialogHeader>
        {fromZoho && <p className="text-[11px] text-muted-foreground">Imported from Zoho: amounts and dates stay as Zoho has them; you can set the case, kind, billing and notes.</p>}
        <div className="grid grid-cols-2 gap-3 text-xs">
          <label className="col-span-2">Case<div className="mt-1"><ProjectCombobox value={f.project_id} projects={projects} onSelect={(id) => set("project_id", id)} /></div></label>
          <label>Kind<select value={f.kind} onChange={(e) => set("kind", e.target.value)} className={input}>{KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
          <label>Vendor (Patent Office / associate)<input value={f.vendor_name} onChange={(e) => set("vendor_name", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          <label>Bill / challan number<input value={f.vendor_reference} onChange={(e) => set("vendor_reference", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          <label>Bill date<input type="date" value={f.bill_date} onChange={(e) => set("bill_date", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          <label>Currency<input value={f.currency} maxLength={3} onChange={(e) => set("currency", e.target.value.toUpperCase())} disabled={!!fromZoho} className={input} /></label>
          <label>Amount<input type="number" min="0.01" step="0.01" value={f.amount} onChange={(e) => set("amount", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          {f.currency !== "INR" && <label>Rate to INR<input type="number" min="0" step="0.0001" value={f.exchange_rate} onChange={(e) => set("exchange_rate", e.target.value)} disabled={!!fromZoho} className={input} /></label>}
          <label>Paid on<input type="date" value={f.paid_on} onChange={(e) => set("paid_on", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          <label className="col-span-2">Description<input value={f.description} onChange={(e) => set("description", e.target.value)} disabled={!!fromZoho} className={input} /></label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={f.recoverable} onChange={(e) => set("recoverable", e.target.checked)} />Recoverable (bill to the client)</label>
          {f.recoverable && <label>Billed on invoice<select value={f.billed_invoice_id} onChange={(e) => set("billed_invoice_id", e.target.value)} className={input}>
            <option value="">Not billed yet</option>{invoices.map((i) => <option key={i.id} value={i.id}>{i.invoice_uin}</option>)}</select></label>}
          <label className="col-span-2">Notes<input value={f.notes} onChange={(e) => set("notes", e.target.value)} maxLength={500} className={input} /></label>
          <label className="col-span-2">Bill PDF (optional)<input type="file" accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="mt-1 block w-full text-xs" /></label>
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
          <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={saving || !f.amount || !f.bill_date} onClick={save}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save"}</Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
