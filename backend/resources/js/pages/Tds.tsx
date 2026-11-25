import { Head } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { BellRing, CheckCircle2, FileUp, Loader2, Search } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { TableCard } from "@/components/modern/table-card";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { api, getCsrfToken } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

interface Row {
  id: number; date: string; fy: string; quarter: string; amount: number; payment_number: string | null; invoice: string | null; status: "pending" | "in_26as" | "mismatch";
  client: { id: number; code: string | null; name: string | null } | null;
  entry: { date: string; tds: number; tan: string; status: string | null } | null;
  form16a: { received_on: string; certificate_no: string | null; document: { file_name: string; storage_path: string } | null } | null;
  note: string | null;
}
interface Data {
  rows: Row[]; summary: { withheld: number; count: number; in_26as: number; missing: number; no_16a: number }; fys: string[];
  clients: { id: number; code: string | null; name: string | null }[];
  unmatched_26as: { id: number; tan: string; deductor: string | null; date: string; tds: number; amount_paid: number; fy: string; quarter: string; mapped: boolean }[];
  deductors: { tan: string; name: string; client: { id: number; code: string; name: string } | null }[];
  uploaded: { fy: string; quarter: string; n: number }[];
  can: { edit: boolean };
}

const inr = (v: number) => `₹${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;

/** Finance → TDS: tax the clients withheld, matched to Form 26AS, with the Form 16A certificates received. */
export default function Tds() {
  const params0 = new URLSearchParams(window.location.search);
  const [data, setData] = useState<Data | null>(null);
  const [fy, setFy] = useState("");
  const [quarter, setQuarter] = useState("");
  const [clientId, setClientId] = useState(params0.get("client_id") ?? "");
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [form16a, setForm16a] = useState<Row | null>(null);
  const [view, setView] = useState<"lines" | "26as" | "deductors">("lines");
  const fileRef = useRef<HTMLInputElement | null>(null);

  const load = () => {
    setLoading(true);
    const p = new URLSearchParams();
    if (fy) p.set("fy", fy);
    if (quarter) p.set("quarter", quarter);
    if (clientId) p.set("client_id", clientId);
    if (status) p.set("status", status);
    if (search.trim()) p.set("search", search.trim());
    api.request<Data>(`/tds?${p}`).then(setData).catch((e: any) => toast.error(e?.message ?? "Could not load TDS.")).finally(() => setLoading(false));
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { const t = window.setTimeout(load, 250); return () => window.clearTimeout(t); }, [fy, quarter, clientId, status, search]);

  const upload = async (file: File) => {
    setUploading(true);
    try {
      const body = new FormData();
      body.append("file", file);
      const res = await fetch("/api/tds/26as", { method: "POST", body, credentials: "same-origin", headers: { Accept: "application/json", "X-XSRF-TOKEN": getCsrfToken() } });
      const json = await res.json();
      if (!res.ok) throw new Error(json?.message ?? "Upload failed.");
      toast.success(`26AS: ${json.entries} credits read, ${json.new} new, ${json.matched} TDS lines matched${json.deductors_unmapped ? `; ${json.deductors_unmapped} deductor(s) need a client` : ""}.`);
      load();
    } catch (e: any) {
      toast.error(e?.message ?? "Upload failed.");
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  };
  const chase = async (id: number) => {
    try { await api.request(`/tds/chase/${id}`, { method: "POST", body: "{}" }); toast.success("Client Manager notified"); } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };
  const mapDeductor = async (tan: string, client: string) => {
    try {
      const res = await api.request<{ matched: number }>(`/tds/deductors/${tan}`, { method: "PUT", body: JSON.stringify({ client_id: client ? Number(client) : null }) });
      toast.success(`Saved; ${res.matched} TDS line(s) matched.`);
      load();
    } catch (e: any) { toast.error(e?.message ?? "Failed."); }
  };

  const s = data?.summary;
  return (
    <AppLayout>
      <Head title="TDS Tracker" />
      <FitPage eyebrow="Billing" title="TDS Tracker"
        summary="Tax the clients withheld on our invoices: whether it shows in the firm's Form 26AS, and the Form 16A certificates received. TDS is not cash until it is in 26AS."
        actions={data?.can.edit && (
          <>
            <input ref={fileRef} type="file" accept=".txt,.csv,text/plain,text/csv" className="hidden" onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
            <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={uploading} onClick={() => fileRef.current?.click()}>
              {uploading ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <FileUp className="mr-1.5 h-4 w-4" />}Upload Form 26AS
            </Button>
          </>
        )}>
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          <div className="flex shrink-0 flex-wrap gap-2">
            {[
              { label: "TDS withheld", value: s ? inr(s.withheld) : "—", color: "#d97706" },
              { label: "Seen in 26AS", value: s ? inr(s.in_26as) : "—", color: "#16a34a", onClick: () => setStatus(status === "in_26as" ? "" : "in_26as"), active: status === "in_26as" },
              { label: "Not in 26AS yet", value: s ? inr(s.missing) : "—", color: "#ef4444", onClick: () => setStatus(status === "pending" ? "" : "pending"), active: status === "pending" },
              { label: "No Form 16A", value: s ? `${s.no_16a} of ${s.count}` : "—", color: "#8b5cf6", onClick: () => setStatus(status === "no_16a" ? "" : "no_16a"), active: status === "no_16a" },
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
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search payment, invoice or client…"
                className="h-8 w-full rounded-md border border-border bg-background/70 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold/25" />
            </div>
            <select value={fy} onChange={(e) => setFy(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All years</option>{data?.fys.map((f) => <option key={f} value={f}>FY {f}</option>)}
            </select>
            <select value={quarter} onChange={(e) => setQuarter(e.target.value)} className="h-8 rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All quarters</option>{["Q1", "Q2", "Q3", "Q4"].map((q) => <option key={q} value={q}>{q}</option>)}
            </select>
            <select value={clientId} onChange={(e) => setClientId(e.target.value)} className="h-8 max-w-[220px] rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All clients</option>{data?.clients.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select>
            {data?.can.edit && (
              <div className="ml-auto inline-flex rounded-md border border-border p-0.5 text-[11px]">
                {([["lines", "TDS lines"], ["26as", `26AS not matched (${data.unmatched_26as.length})`], ["deductors", `Deductors (${data.deductors.filter((d) => !d.client).length} to map)`]] as const).map(([v, l]) => (
                  <button key={v} onClick={() => setView(v)} className={`rounded px-2 py-1 ${view === v ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground"}`}>{l}</button>
                ))}
              </div>
            )}
          </div>
          {data?.can.edit && data.uploaded.length > 0 && (
            <p className="shrink-0 text-[11px] text-muted-foreground">26AS uploaded for: {data.uploaded.map((u) => `FY ${u.fy} ${u.quarter} (${u.n})`).join(" · ")}</p>
          )}

          <TableCard>
            {view === "lines" && (
              <table className="w-full min-w-[900px] text-xs">
                <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                  <tr><th className="px-3 py-2 text-left">Deducted</th><th className="px-3 py-2 text-left">Client</th><th className="px-3 py-2 text-left">Payment · invoice</th>
                    <th className="px-3 py-2 text-right">TDS</th><th className="px-3 py-2 text-left">Form 26AS</th><th className="px-3 py-2 text-left">Form 16A</th><th className="px-3 py-2" /></tr>
                </thead>
                <tbody className="table-rows-3d">
                  {loading && !data && <tr><td colSpan={7} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                  {data && data.rows.length === 0 && <tr><td colSpan={7} className="py-12 text-center text-sm text-muted-foreground">No TDS lines match this view.</td></tr>}
                  {data?.rows.map((r) => (
                    <tr key={r.id} className="border-t border-border/60">
                      <td className="whitespace-nowrap px-3 py-2 font-mono text-[11px]">{fmtDate(r.date)}<div className="text-[10px] text-muted-foreground">FY {r.fy} {r.quarter}</div></td>
                      <td className="max-w-[200px] px-3 py-2"><div className="truncate font-medium">{r.client?.name ?? "—"}</div><div className="text-[10px] text-muted-foreground">{r.client?.code}</div></td>
                      <td className="px-3 py-2 font-mono text-[11px]">{r.payment_number ?? "—"}<div className="text-[10px] text-muted-foreground">{r.invoice}</div></td>
                      <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-amber-700">{inr(r.amount)}</td>
                      <td className="px-3 py-2">{r.status === "in_26as" && r.entry
                        ? <span className="inline-flex items-center gap-1 text-emerald-600"><CheckCircle2 className="h-3.5 w-3.5" />{fmtDate(r.entry.date)} · {inr(r.entry.tds)}{r.entry.status && r.entry.status !== "F" ? ` (${r.entry.status})` : ""}</span>
                        : <span className="text-red-600">Not in 26AS</span>}</td>
                      <td className="px-3 py-2">{r.form16a
                        ? <span className="text-emerald-600">{fmtDate(r.form16a.received_on)}{r.form16a.certificate_no ? ` · ${r.form16a.certificate_no}` : ""}
                            {r.form16a.document && <button className="ml-1 underline" onClick={() => api.downloadDocument(r.form16a!.document!.storage_path, r.form16a!.document!.file_name)}>PDF</button>}</span>
                        : <span className="text-muted-foreground">—</span>}</td>
                      <td className="whitespace-nowrap px-3 py-2 text-right">
                        {data.can.edit && <Button size="sm" variant="outline" className="h-7 text-[11px]" onClick={() => setForm16a(r)}>{r.form16a ? "Edit 16A" : "16A received"}</Button>}
                        {data.can.edit && r.client && (r.status !== "in_26as" || !r.form16a) && (
                          <button className="ml-1 rounded p-1 text-muted-foreground hover:text-foreground" title="Notify the Client Manager to chase" onClick={() => chase(r.client!.id)}><BellRing className="h-3.5 w-3.5" /></button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            {view === "26as" && data && (
              <table className="w-full text-xs">
                <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th className="px-3 py-2 text-left">Date</th><th className="px-3 py-2 text-left">Deductor</th><th className="px-3 py-2 text-right">Amount paid</th><th className="px-3 py-2 text-right">TDS</th><th className="px-3 py-2 text-left">Why not matched</th></tr></thead>
                <tbody>{data.unmatched_26as.map((e) => (
                  <tr key={e.id} className="border-t border-border/60"><td className="px-3 py-2 font-mono">{fmtDate(e.date)} <span className="text-muted-foreground">FY {e.fy} {e.quarter}</span></td>
                    <td className="px-3 py-2">{e.deductor} <span className="font-mono text-muted-foreground">{e.tan}</span></td>
                    <td className="px-3 py-2 text-right font-mono">{inr(e.amount_paid)}</td><td className="px-3 py-2 text-right font-mono">{inr(e.tds)}</td>
                    <td className="px-3 py-2 text-muted-foreground">{e.mapped ? "No TDS line with this amount in the portal: check Zoho" : "Deductor not tied to a client (Deductors tab)"}</td></tr>
                ))}</tbody>
              </table>
            )}
            {view === "deductors" && data && (
              <table className="w-full text-xs">
                <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th className="px-3 py-2 text-left">TAN</th><th className="px-3 py-2 text-left">Name in 26AS</th><th className="px-3 py-2 text-left">Portal client</th></tr></thead>
                <tbody>{data.deductors.map((d) => (
                  <tr key={d.tan} className="border-t border-border/60"><td className="px-3 py-2 font-mono">{d.tan}</td><td className="px-3 py-2">{d.name}</td>
                    <td className="px-3 py-2"><DeductorPicker value={d.client?.id ?? null} onPick={(c) => mapDeductor(d.tan, c)} /></td></tr>
                ))}</tbody>
              </table>
            )}
          </TableCard>
        </div>
      </FitPage>
      {form16a && <Form16aDialog row={form16a} onClose={() => setForm16a(null)} onDone={() => { setForm16a(null); load(); }} />}
    </AppLayout>
  );
}

function DeductorPicker({ value, onPick }: { value: number | null; onPick: (clientId: string) => void }) {
  const [clients, setClients] = useState<{ id: number; client_code: string; legal_name: string }[] | null>(null);
  useEffect(() => { api.request<any>("/clients?per_page=1000").then((r) => setClients(r?.data ?? r ?? [])).catch(() => setClients([])); }, []);
  if (!clients) return <Loader2 className="h-3.5 w-3.5 animate-spin" />;
  return (
    <select value={value ?? ""} onChange={(e) => onPick(e.target.value)} className="h-7 max-w-[280px] rounded-md border border-border bg-background px-1.5 text-xs">
      <option value="">— not tied —</option>
      {clients.map((c) => <option key={c.id} value={c.id}>{c.client_code} · {c.legal_name}</option>)}
    </select>
  );
}

function Form16aDialog({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: () => void }) {
  const [receivedOn, setReceivedOn] = useState(row.form16a?.received_on ?? new Date().toISOString().slice(0, 10));
  const [certificate, setCertificate] = useState(row.form16a?.certificate_no ?? "");
  const [file, setFile] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const save = async (clear = false) => {
    setSaving(true);
    try {
      let documentId: number | null = null;
      if (file && !clear) {
        const doc: any = await api.uploadDocument(file, "Financial", row.client?.id ?? null, null);
        documentId = doc?.id ?? null;
      }
      await api.request(`/tds/${row.id}/form16a`, { method: "PUT", body: JSON.stringify(clear ? { received_on: null }
        : { received_on: receivedOn, certificate_no: certificate || null, document_id: documentId }) });
      toast.success(clear ? "Form 16A cleared" : "Form 16A recorded");
      onDone();
    } catch (e: any) { toast.error(e?.message ?? "Failed."); } finally { setSaving(false); }
  };
  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader><DialogTitle>Form 16A · {row.client?.code} · {inr(row.amount)}</DialogTitle></DialogHeader>
        <div className="space-y-3 text-sm">
          <label className="block text-xs">Received on<input type="date" value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2" /></label>
          <label className="block text-xs">Certificate number<input value={certificate} onChange={(e) => setCertificate(e.target.value)} maxLength={60} className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2" /></label>
          <label className="block text-xs">Certificate PDF (optional)<input type="file" accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="mt-1 block w-full text-xs" /></label>
          <div className="flex justify-between gap-2">
            {row.form16a ? <Button variant="outline" size="sm" disabled={saving} onClick={() => save(true)}>Clear</Button> : <span />}
            <Button size="sm" className="bg-gold text-black hover:bg-gold/90" disabled={saving || !receivedOn} onClick={() => save()}>{saving ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save"}</Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
