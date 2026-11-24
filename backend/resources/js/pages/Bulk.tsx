import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { Building2, CheckCircle2, CheckSquare, ClipboardList, Download, FileText, FolderOpen, Layers, Loader2, Search, XCircle } from "lucide-react";
import { api, downloadCSV, type BulkResult } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type Entity = "cases" | "tasks" | "clients" | "invoices";

interface Row { id: number; cells: string[]; stageNames?: string[] }

const CASE_STATUSES = ["Open", "In Progress", "On Hold", "Closed", "Completed", "Granted", "Refused", "Abandoned"];
const US_CASE_STATUSES = ["Filed", "Missing Parts Pending", "Awaiting Publication", "Awaiting Examination", "Non-Final Action Received", "Final Action Received", "After-Final Strategy", "Appeal Pending", "Allowed", "Issue Fee Pending", "Issued", "Revival Pending", "Expired"];
const TASK_STATUSES = ["Pending", "In Progress", "Review", "Completed"];
const CLIENT_STATUSES = ["Active", "Inactive", "Prospect", "On Hold"];
const EVENT_STATUSES = new Set(["Granted", "Refused", "Abandoned"]);

const ENTITIES: { key: Entity; label: string; icon: React.ComponentType<{ className?: string }>; headers: string[] }[] = [
  { key: "cases", label: "Cases", icon: FolderOpen, headers: ["UIN", "Title", "Client", "Status", "Stage", "Client Manager", "Circle"] },
  { key: "tasks", label: "Tasks", icon: ClipboardList, headers: ["Task", "Case", "Assignee", "Due", "Status"] },
  { key: "clients", label: "Clients", icon: Building2, headers: ["Code", "Client", "Account manager", "Circle", "Status"] },
  { key: "invoices", label: "Invoices", icon: FileText, headers: ["Invoice", "Client", "Status", "Payment", "Balance"] },
];

const ACTIONS: Record<Entity, { key: string; label: string }[]> = {
  cases: [
    { key: "status", label: "Change status" }, { key: "stage", label: "Move workflow stage" },
    { key: "assign", label: "Reassign CM / SCM / Patent Engineer" }, { key: "circle", label: "Change circle" },
    { key: "deadline", label: "Set or shift deadline" }, { key: "notify", label: "Send a message" },
    { key: "export", label: "Export CSV" }, { key: "delete", label: "Delete (System Admin)" },
  ],
  tasks: [{ key: "status", label: "Change status" }, { key: "assign", label: "Reassign" }, { key: "due_date", label: "Set due date" }, { key: "export", label: "Export CSV" }],
  clients: [{ key: "status", label: "Change status" }, { key: "account_manager", label: "Change account manager" }, { key: "circle", label: "Change circle" }, { key: "export", label: "Export CSV" }],
  invoices: [{ key: "remind_client", label: "Payment reminder to client" }, { key: "status_note", label: "Add follow-up note" }, { key: "mark_sent", label: "Mark sent" }, { key: "export", label: "Export CSV" }],
};

const money = (v: unknown, cur = "INR") => `${cur} ${Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;

async function loadPage(entity: Entity, search: string, status: string, page: number): Promise<{ rows: Row[]; lastPage: number; total: number }> {
  const params = new URLSearchParams({ page: String(page), per_page: "50" });
  if (search.trim()) params.set("search", search.trim());
  if (status) params.set("status", status);
  if (entity === "cases") {
    const res = await api.getProjectsPaged(params);
    return {
      lastPage: res.last_page, total: res.total,
      rows: res.data.map((p: any) => ({
        id: p.id, stageNames: p.stage_names ?? [],
        cells: [p.docket_number ?? p.project_code ?? "—", p.project_name ?? "—", p.client?.company_name ?? p.client?.legal_name ?? "—", p.status ?? "—",
          p.current_stage ?? "—", p.manager?.name ?? "—", p.circle ?? "—"],
      })),
    };
  }
  if (entity === "tasks") {
    const res = await api.getTasksPaged(params);
    return {
      lastPage: res.last_page, total: res.total,
      rows: res.data.map((t: any) => ({ id: t.id, cells: [t.title ?? "—", t.project?.docket_number ?? t.project?.project_code ?? "—", t.assignee?.name ?? "—", t.due_date ? String(t.due_date).slice(0, 10) : "—", t.status ?? "—"] })),
    };
  }
  if (entity === "clients") {
    const res = await api.getClients(params);
    return {
      lastPage: res.last_page, total: res.total,
      rows: res.data.map((c: any) => ({ id: c.id, cells: [c.client_code ?? "—", c.legal_name ?? c.company_name ?? "—", c.account_manager?.name ?? "—", c.circle ?? "—", c.status ?? "—"] })),
    };
  }
  params.set("type", "invoice");
  const res = await api.getPatentInvoicesIn(params);
  return {
    lastPage: res.last_page, total: res.total,
    rows: res.data.map((i: any) => ({ id: i.id, cells: [i.invoice_uin ?? "—", i.client_name ?? i.client?.company_name ?? "—", i.status ?? "—", i.payment_status ?? "—", money(i.balance_due ?? i.invoice_amount, i.currency ?? "INR")] })),
  };
}

export default function Bulk() {
  const [entity, setEntity] = useState<Entity>("cases");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{ rows: Row[]; lastPage: number; total: number }>({ rows: [], lastPage: 1, total: 0 });
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<Map<number, Row>>(new Map());
  const [action, setAction] = useState("status");
  const [params, setParams] = useState<Record<string, string | boolean>>({});
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [running, setRunning] = useState(false);
  const [results, setResults] = useState<BulkResult | null>(null);
  const meta = ENTITIES.find((e) => e.key === entity)!;

  useEffect(() => { api.getStaffPickerUsers().then((u) => setStaff(u.map((x) => ({ id: x.id, name: x.name })))).catch(() => {}); }, []);
  useEffect(() => { setSelected(new Map()); setAction(ACTIONS[entity][0].key); setParams({}); setStatus(""); setPage(1); setResults(null); }, [entity]);
  useEffect(() => {
    setLoading(true);
    const t = window.setTimeout(() => {
      loadPage(entity, search, status, page).then(setData).catch((e) => toast.error((e as Error).message)).finally(() => setLoading(false));
    }, 250);
    return () => window.clearTimeout(t);
  }, [entity, search, status, page]);

  const statusFilter = entity === "cases" ? [...CASE_STATUSES, ...US_CASE_STATUSES] : entity === "tasks" ? TASK_STATUSES : entity === "clients" ? CLIENT_STATUSES : ["Draft", "Sent", "Paid", "Partially Paid", "Overdue", "Cancelled"];
  const allOnPage = data.rows.length > 0 && data.rows.every((r) => selected.has(r.id));
  const toggle = (row: Row) => setSelected((m) => { const n = new Map(m); n.has(row.id) ? n.delete(row.id) : n.set(row.id, row); return n; });
  const togglePage = () => setSelected((m) => { const n = new Map(m); data.rows.forEach((r) => (allOnPage ? n.delete(r.id) : n.set(r.id, r))); return n; });
  const stageOptions = useMemo(() => Array.from(new Set([...selected.values()].flatMap((r) => r.stageNames ?? []))), [selected]);
  const set = (k: string, v: string | boolean) => setParams((p) => ({ ...p, [k]: v }));

  const ready = (() => {
    if (selected.size === 0) return false;
    switch (`${entity}:${action}`) {
      case "cases:status": case "tasks:status": case "clients:status": return !!params.status && (!EVENT_STATUSES.has(String(params.status)) || !!params.status_event_date);
      case "cases:stage": return !!params.stage_name;
      case "cases:assign": return !!(params.assigned_manager_id || params.secondary_manager_id || params.patent_engineer_id);
      case "cases:circle": case "clients:circle": return !!params.circle;
      case "cases:deadline": return !!params.hard_deadline || !!Number(params.shift_days);
      case "cases:notify": return !!params.title && !!params.message;
      case "tasks:assign": return !!params.assignee_id;
      case "tasks:due_date": return !!params.due_date;
      case "clients:account_manager": return !!params.account_manager_id;
      case "invoices:status_note": return !!params.status_note;
      default: return true;
    }
  })();

  const exportSelected = () => {
    const rows = [...selected.values()].map((r) => Object.fromEntries(meta.headers.map((h, i) => [h, r.cells[i] ?? ""])));
    downloadCSV(`${entity}-${new Date().toISOString().slice(0, 10)}.csv`, rows);
  };

  const run = async () => {
    setRunning(true);
    try {
      const res = await api.bulkExecute({ entity, ids: [...selected.keys()], action, params });
      setResults(res);
      toast[res.failed ? "warning" : "success"](`${res.ok} done${res.failed ? `, ${res.failed} not changed` : ""}`);
      setConfirmOpen(false);
      loadPage(entity, search, status, page).then(setData).catch(() => {});
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setRunning(false);
    }
  };

  const field = "h-9 w-full rounded-lg border border-border bg-background px-2.5 text-sm outline-none focus:border-gold/60";
  const staffSelect = (k: string, placeholder: string) => (
    <select value={String(params[k] ?? "")} onChange={(e) => set(k, e.target.value)} className={field}>
      <option value="">{placeholder}</option>
      {staff.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
    </select>
  );

  return (
    <AppLayout>
      <Head title="Bulk Operations" />
      <PageHeader eyebrow="Operations" title="Bulk Operations" description="One change across many cases, tasks, clients or invoices — each goes through the same checks as a single edit" />
      <div className="space-y-4 px-8 py-6">
        <div className="flex flex-wrap gap-2">
          {ENTITIES.map((e) => (
            <button key={e.key} onClick={() => setEntity(e.key)}
              className={`flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-medium transition ${entity === e.key ? "shadow-3d border-gold bg-gold/10" : "border-border hover:border-gold/40"}`}>
              <e.icon className="h-4 w-4 text-gold" />{e.label}
            </button>
          ))}
        </div>

        <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
          <Card className="border-border overflow-hidden">
            <CardContent className="p-0">
              <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                <div className="relative min-w-[200px] flex-1">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder={`Search ${meta.label.toLowerCase()}…`} className={`${field} pl-8`} />
                </div>
                <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="h-9 rounded-lg border border-border bg-background px-2 text-sm">
                  <option value="">All statuses</option>
                  {statusFilter.map((s) => <option key={s}>{s}</option>)}
                </select>
                <Badge variant="outline" className="h-9 px-3">{selected.size} selected</Badge>
                {selected.size > 0 && <Button size="sm" variant="ghost" onClick={() => setSelected(new Map())}>Clear</Button>}
              </div>
              <div className="max-h-[60vh] overflow-auto">
                <table className="w-full text-sm">
                  <thead className="table-sticky-head bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="w-10 px-3 py-2.5"><input type="checkbox" checked={allOnPage} onChange={togglePage} aria-label="Select page" /></th>
                      {meta.headers.map((h) => <th key={h} className="px-3 py-2.5 text-left">{h}</th>)}
                    </tr>
                  </thead>
                  <tbody className="table-rows-3d">
                    {loading && data.rows.length === 0 ? (
                      <tr><td colSpan={meta.headers.length + 1} className="py-10 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>
                    ) : data.rows.length === 0 ? (
                      <tr><td colSpan={meta.headers.length + 1} className="py-10 text-center text-muted-foreground">Nothing matches.</td></tr>
                    ) : data.rows.map((r) => (
                      <tr key={r.id} onClick={() => toggle(r)} className={`cursor-pointer border-t border-border ${selected.has(r.id) ? "bg-gold/5" : "hover:bg-muted/30"}`}>
                        <td className="px-3 py-2"><input type="checkbox" checked={selected.has(r.id)} onChange={() => toggle(r)} onClick={(e) => e.stopPropagation()} aria-label="Select row" /></td>
                        {r.cells.map((c, i) => <td key={i} className={`px-3 py-2 ${i === 0 ? "font-mono text-xs" : "text-muted-foreground"}`}>{c}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="flex items-center justify-between border-t border-border px-3 py-2 text-xs text-muted-foreground">
                <span>{data.total.toLocaleString("en-IN")} {meta.label.toLowerCase()}</span>
                <div className="flex items-center gap-2">
                  <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Button>
                  <span>Page {page} of {Math.max(1, data.lastPage)}</span>
                  <Button size="sm" variant="outline" disabled={page >= data.lastPage} onClick={() => setPage((p) => p + 1)}>Next</Button>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-border h-fit">
            <CardContent className="space-y-3 p-4 text-sm">
              <div className="flex items-center gap-2 font-semibold"><Layers className="h-4 w-4 text-gold" />Action</div>
              <select value={action} onChange={(e) => { setAction(e.target.value); setParams({}); setResults(null); }} className={field}>
                {ACTIONS[entity].map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
              </select>

              {(action === "status") && (
                <>
                  <select value={String(params.status ?? "")} onChange={(e) => set("status", e.target.value)} className={field}>
                    <option value="">Choose status…</option>
                    {(entity === "cases" ? [...CASE_STATUSES, ...US_CASE_STATUSES] : entity === "tasks" ? TASK_STATUSES : CLIENT_STATUSES).map((s) => <option key={s}>{s}</option>)}
                  </select>
                  {entity === "cases" && EVENT_STATUSES.has(String(params.status)) && (
                    <label className="block text-xs">{String(params.status)} on (date)<input type="date" value={String(params.status_event_date ?? "")} onChange={(e) => set("status_event_date", e.target.value)} className={`${field} mt-1`} /></label>
                  )}
                </>
              )}
              {entity === "cases" && action === "stage" && (
                <>
                  <input list="bulk-stages" value={String(params.stage_name ?? "")} onChange={(e) => set("stage_name", e.target.value)} placeholder="Stage name" className={field} />
                  <datalist id="bulk-stages">{stageOptions.map((s) => <option key={s} value={s} />)}</datalist>
                  <p className="text-[11px] text-muted-foreground">Cases whose workflow has no stage with this name are left unchanged and listed.</p>
                </>
              )}
              {entity === "cases" && action === "assign" && (
                <>
                  <label className="block text-xs">Client Manager{staffSelect("assigned_manager_id", "Keep as is")}</label>
                  <label className="block text-xs">Secondary CM{staffSelect("secondary_manager_id", "Keep as is")}</label>
                  <label className="block text-xs">Patent Engineer{staffSelect("patent_engineer_id", "Keep as is")}</label>
                </>
              )}
              {(action === "circle") && (
                <select value={String(params.circle ?? "")} onChange={(e) => set("circle", e.target.value)} className={field}>
                  <option value="">Choose circle…</option><option value="A">Circle A</option><option value="B">Circle B</option>
                </select>
              )}
              {entity === "cases" && action === "deadline" && (
                <>
                  <label className="block text-xs">New deadline<input type="date" value={String(params.hard_deadline ?? "")} onChange={(e) => set("hard_deadline", e.target.value)} className={`${field} mt-1`} /></label>
                  <label className="block text-xs">— or shift each case's deadline by (days, minus for earlier)
                    <input type="number" value={String(params.shift_days ?? "")} onChange={(e) => set("shift_days", e.target.value)} disabled={!!params.hard_deadline} className={`${field} mt-1`} /></label>
                </>
              )}
              {entity === "cases" && action === "notify" && (
                <>
                  <input value={String(params.title ?? "")} onChange={(e) => set("title", e.target.value)} placeholder="Title" className={field} />
                  <textarea value={String(params.message ?? "")} onChange={(e) => set("message", e.target.value)} placeholder="Message to the CM, SCM and Patent Engineer" rows={3} className={`${field} h-auto py-2`} />
                  <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={!!params.include_client} onChange={(e) => set("include_client", e.target.checked)} />Also the client's portal users</label>
                </>
              )}
              {entity === "tasks" && action === "assign" && staffSelect("assignee_id", "Choose assignee…")}
              {entity === "tasks" && action === "due_date" && <input type="date" value={String(params.due_date ?? "")} onChange={(e) => set("due_date", e.target.value)} className={field} />}
              {entity === "clients" && action === "account_manager" && staffSelect("account_manager_id", "Choose account manager…")}
              {entity === "invoices" && action === "status_note" && <textarea value={String(params.status_note ?? "")} onChange={(e) => set("status_note", e.target.value)} placeholder="Follow-up note" rows={3} maxLength={500} className={`${field} h-auto py-2`} />}
              {entity === "invoices" && action === "remind_client" && <textarea value={String(params.message ?? "")} onChange={(e) => set("message", e.target.value)} placeholder="Optional message (default: balance and a request to pay or share proof)" rows={3} className={`${field} h-auto py-2`} />}

              {action === "export" ? (
                <Button className="w-full" variant="outline" disabled={selected.size === 0} onClick={exportSelected}><Download className="mr-1.5 h-4 w-4" />Export {selected.size} row(s)</Button>
              ) : (
                <Button className="w-full bg-gold text-black hover:bg-gold/90" disabled={!ready} onClick={() => setConfirmOpen(true)}>
                  <CheckSquare className="mr-1.5 h-4 w-4" />Apply to {selected.size} {meta.label.toLowerCase()}
                </Button>
              )}

              {results && (
                <div className="max-h-64 space-y-1 overflow-y-auto rounded-lg border border-border p-2 text-xs">
                  <div className="font-semibold">{results.ok} done · {results.failed} not changed</div>
                  {results.results.map((r) => (
                    <div key={r.id} className="flex items-start gap-1.5">
                      {r.ok ? <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-600" />}
                      <span>{r.label ?? selected.get(r.id)?.cells[0] ?? `#${r.id}`}{!r.ok && <span className="text-muted-foreground"> — {r.error}</span>}</span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      <Dialog open={confirmOpen} onOpenChange={(o) => !running && setConfirmOpen(o)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>{ACTIONS[entity].find((a) => a.key === action)?.label} — {selected.size} {meta.label.toLowerCase()}</DialogTitle>
            <DialogDescription>Each one is checked like a single edit; any that can't change are listed with the reason. Every change is in the audit log.</DialogDescription>
          </DialogHeader>
          <div className="max-h-48 overflow-y-auto rounded-lg border border-border p-2 font-mono text-xs">
            {[...selected.values()].slice(0, 50).map((r) => <div key={r.id}>{r.cells[0]} <span className="font-sans text-muted-foreground">{r.cells[1]}</span></div>)}
            {selected.size > 50 && <div className="font-sans text-muted-foreground">… and {selected.size - 50} more</div>}
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="outline" disabled={running} onClick={() => setConfirmOpen(false)}>Cancel</Button>
            <Button className={action === "delete" ? "bg-red-600 text-white hover:bg-red-700" : "bg-gold text-black hover:bg-gold/90"} disabled={running} onClick={run}>
              {running && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}{action === "delete" ? "Delete" : "Apply"}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
