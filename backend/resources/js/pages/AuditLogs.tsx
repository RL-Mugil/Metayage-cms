import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState, useCallback } from "react";
import { ShieldCheck, Search, ChevronLeft, ChevronRight, Loader2, RefreshCw, AlertTriangle, CheckCircle2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { api } from "@/lib/api-client";
import type { AuditLog, ZohoAuditMonitoring, ZohoControlPanel } from "@/lib/api-client";
import { fmtDateTime } from "@/lib/date-utils";

const ACTION_COLOR: Record<string, string> = {
  create: "bg-green-500/10 text-green-400",
  update: "bg-blue-500/10 text-blue-400",
  delete: "bg-red-500/10 text-red-400",
  cancel: "bg-red-500/10 text-red-400",
  batch:  "bg-purple-500/10 text-purple-400",
  convert: "bg-amber-500/10 text-amber-400",
};

function actionColor(action: string): string {
  const key = Object.keys(ACTION_COLOR).find(k => action.startsWith(k));
  return key ? ACTION_COLOR[key] : "bg-muted/60 text-muted-foreground";
}

const SUBJECT_TYPES = ["", "Client", "Project", "Invoice", "Payment", "Quotation", "Employee", "Task", "LeaveRequest", "PayrollRun"];

export default function AuditLogs() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;

  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState<"activity" | "zoho" | "control">("activity");
  const [zoho, setZoho] = useState<ZohoAuditMonitoring | null>(null);
  const [zohoLoading, setZohoLoading] = useState(false);
  const [control, setControl] = useState<ZohoControlPanel | null>(null);
  const [controlLoading, setControlLoading] = useState(false);

  const [search, setSearch] = useState("");
  const [subjectType, setSubjectType] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const PER_PAGE = 50;

  const load = useCallback(async (pg: number, s: string, st: string, df: string, dt: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pg) });
      if (s) params.set("search", s);
      if (st) params.set("subject_type", st);
      if (df) params.set("date_from", df);
      if (dt) params.set("date_to", dt);
      const res = await api.getAuditLogs(params);
      setLogs(res.data ?? []);
      setTotal(res.total ?? 0);
      setLastPage(res.last_page ?? 1);
    } catch { /* forbidden or network error */ }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { load(1, search, subjectType, dateFrom, dateTo); }, []);

  const loadZoho = useCallback(async () => {
    setZohoLoading(true);
    try { setZoho(await api.getZohoAuditMonitoring()); } catch { setZoho(null); }
    finally { setZohoLoading(false); }
  }, []);

  useEffect(() => { if (view === "zoho") loadZoho(); }, [view, loadZoho]);

  const loadControl = useCallback(async () => {
    setControlLoading(true);
    try { setControl(await api.getZohoControlPanel()); } catch { setControl(null); }
    finally { setControlLoading(false); }
  }, []);

  useEffect(() => { if (view === "control") loadControl(); }, [view, loadControl]);

  function applyFilters() { setPage(1); load(1, search, subjectType, dateFrom, dateTo); }
  function goPage(pg: number) { setPage(pg); load(pg, search, subjectType, dateFrom, dateTo); }

  const inputCls = "h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  if (!["super_admin", "partner"].includes(role)) {
    return (
      <AppLayout>
        <Head title="Audit Log" />
        <div className="flex items-center justify-center min-h-[60vh]">
          <p className="text-muted-foreground">You do not have access to the audit log.</p>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title="Audit Log — MyIPStrategy" />
      <PageHeader
        eyebrow="Admin"
        title="Audit Log"
        description="Complete activity trail for all system mutations"
      />

      <div className="px-8 py-6 space-y-5">
        <div className="flex gap-2 border-b border-border">
          <button onClick={() => setView("activity")} className={`px-4 py-2 text-sm border-b-2 ${view === "activity" ? "border-gold text-foreground" : "border-transparent text-muted-foreground"}`}>Activity Log</button>
          <button onClick={() => setView("zoho")} className={`px-4 py-2 text-sm border-b-2 ${view === "zoho" ? "border-gold text-foreground" : "border-transparent text-muted-foreground"}`}>Zoho Sync Monitor</button>
          <button onClick={() => setView("control")} className={`px-4 py-2 text-sm border-b-2 ${view === "control" ? "border-gold text-foreground" : "border-transparent text-muted-foreground"}`}>Two-Way Control Panel</button>
        </div>

        {view === "control" ? (
          <ZohoControlPanelView data={control} loading={controlLoading} onRefresh={loadControl} />
        ) : view === "zoho" ? (
          <ZohoMonitor data={zoho} loading={zohoLoading} onRefresh={loadZoho} />
        ) : <>
        {/* Filters */}
        <div className="flex flex-wrap gap-3 items-end">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <input className={`${inputCls} pl-9 w-64`} placeholder="Search action, subject, metadata…" value={search}
              onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === "Enter" && applyFilters()} />
          </div>
          <select value={subjectType} onChange={e => setSubjectType(e.target.value)} className={`${inputCls} w-44`}>
            <option value="">All subjects</option>
            {SUBJECT_TYPES.filter(Boolean).map(t => <option key={t} value={t}>{t}</option>)}
          </select>
          <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className={`${inputCls} w-38`} placeholder="From date" />
          <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className={`${inputCls} w-38`} placeholder="To date" />
          <button onClick={applyFilters} className="h-9 px-4 rounded-md bg-gold text-black text-sm font-medium hover:bg-gold/90 transition-colors">
            Apply
          </button>
          <button onClick={() => { setSearch(""); setSubjectType(""); setDateFrom(""); setDateTo(""); setPage(1); load(1, "", "", "", ""); }}
            className="h-9 px-4 rounded-md border border-border text-sm text-muted-foreground hover:text-foreground transition-colors">
            Clear
          </button>
          <span className="text-xs text-muted-foreground ml-auto">{total.toLocaleString()} entries</span>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2">
                <ShieldCheck className="h-4 w-4 text-gold" />Audit Entries
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left w-36">When</th>
                    <th className="px-4 py-3 text-left">Who</th>
                    <th className="px-4 py-3 text-left">Action</th>
                    <th className="px-4 py-3 text-left">Subject</th>
                    <th className="px-4 py-3 text-left">Details</th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map((log) => (
                    <tr key={log.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-4 py-2.5 text-xs font-mono text-muted-foreground whitespace-nowrap">
                        {fmtDateTime(log.created_at)}
                      </td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center gap-2">
                          {log.user?.avatar_url ? (
                            <img src={log.user.avatar_url} className="h-6 w-6 rounded-full object-cover" alt="" />
                          ) : (
                            <div className="h-6 w-6 rounded-full bg-gold/20 flex items-center justify-center text-xs font-bold text-gold">
                              {(log.user?.name ?? "?")[0].toUpperCase()}
                            </div>
                          )}
                          <span className="text-sm">{log.user?.name ?? `User #${log.user_id}`}</span>
                        </div>
                      </td>
                      <td className="px-4 py-2.5">
                        <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${actionColor(log.action)}`}>{log.action.replace(/_/g, " ")}</span>
                      </td>
                      <td className="px-4 py-2.5 text-sm text-muted-foreground">
                        {log.subject_type}{log.subject_id ? ` #${log.subject_id}` : ""}
                      </td>
                      <td className="px-4 py-2.5">
                        {log.metadata && Object.keys(log.metadata).length > 0 ? (
                          <details className="cursor-pointer">
                            <summary className="text-xs text-muted-foreground hover:text-foreground list-none select-none">
                              {Object.entries(log.metadata).slice(0, 2).map(([k, v]) => `${k}: ${v}`).join(", ")}
                              {Object.keys(log.metadata).length > 2 ? "…" : ""}
                            </summary>
                            <pre className="mt-1 text-xs bg-muted/40 rounded p-2 max-w-xs overflow-auto text-muted-foreground">
                              {JSON.stringify(log.metadata, null, 2)}
                            </pre>
                          </details>
                        ) : <span className="text-xs text-muted-foreground">—</span>}
                      </td>
                    </tr>
                  ))}
                  {logs.length === 0 && (
                    <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground text-sm">No audit entries match your filters.</td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}

        {/* Pagination */}
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>Page {page} of {lastPage} · {total} total</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1}
              className="p-1.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronLeft className="h-4 w-4" /></button>
            <button onClick={() => goPage(page + 1)} disabled={page >= lastPage}
              className="p-1.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronRight className="h-4 w-4" /></button>
          </div>
        </div>
        </>}
      </div>
    </AppLayout>
  );
}

function ZohoMonitor({ data, loading, onRefresh }: { data: ZohoAuditMonitoring | null; loading: boolean; onRefresh: () => void }) {
  if (loading) return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>;
  if (!data) return <Card><CardContent className="py-10 text-center text-muted-foreground">Zoho monitoring data is unavailable.</CardContent></Card>;
  const cycleFailed = data.cycle?.pause_reason === "error";
  const cycleComplete = Boolean(data.cycle?.completed_at);
  return <div className="space-y-5">
    <div className="flex items-center justify-between">
      <div><p className="text-sm text-muted-foreground">Scope: <span className="font-mono text-foreground">{data.scope}</span></p><p className="text-xs text-muted-foreground">Snapshot backlog: {data.pending_snapshots.toLocaleString()} records</p></div>
      <button onClick={onRefresh} className="h-9 px-3 rounded-md border border-border text-sm flex items-center gap-2 hover:bg-muted/40"><RefreshCw className="h-4 w-4" />Refresh</button>
    </div>
    <div className="grid gap-4 md:grid-cols-4">
      {Object.entries(data.budget.pools).map(([name, pool]) => <Card key={name}><CardHeader className="pb-2"><CardTitle className="text-xs uppercase tracking-wider text-muted-foreground">{name.replace(/_/g, " ")}</CardTitle></CardHeader><CardContent><p className="text-2xl font-display">{pool.used.toLocaleString()} <span className="text-sm text-muted-foreground">/ {pool.limit.toLocaleString()}</span></p><p className="text-xs text-muted-foreground">{pool.remaining.toLocaleString()} remaining</p></CardContent></Card>)}
    </div>
    <Card><CardHeader><CardTitle className="font-display flex items-center justify-between"><span className="flex items-center gap-2">{cycleFailed ? <AlertTriangle className="h-4 w-4 text-red-400" /> : <CheckCircle2 className="h-4 w-4 text-green-400" />} Sync checkpoint</span><span className="text-xs font-normal text-muted-foreground">{cycleComplete ? "Completed" : "Pending / resumable"}</span></CardTitle></CardHeader><CardContent><div className="grid gap-3 md:grid-cols-3 text-sm"><div><span className="text-muted-foreground">Started</span><p>{fmtDateTime(data.cycle?.started_at)}</p></div><div><span className="text-muted-foreground">Completed</span><p>{fmtDateTime(data.cycle?.completed_at)}</p></div><div><span className="text-muted-foreground">Pause reason</span><p>{data.cycle?.pause_reason ?? "—"}</p></div></div></CardContent></Card>
    <Card><CardHeader><CardTitle className="font-display">Resource checkpoints</CardTitle></CardHeader><CardContent className="p-0"><table className="w-full text-sm"><thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr><th className="px-4 py-3 text-left">Resource</th><th className="px-4 py-3 text-left">Next page</th><th className="px-4 py-3 text-left">Completed</th></tr></thead><tbody>{data.progress.map(row => <tr key={row.resource} className="border-t border-border"><td className="px-4 py-2.5 font-mono">{row.resource}</td><td className="px-4 py-2.5">{row.next_page}</td><td className="px-4 py-2.5">{row.completed_at ? fmtDateTime(row.completed_at) : "Pending"}</td></tr>)}</tbody></table></CardContent></Card>
    <Card><CardHeader><CardTitle className="font-display">Recent sync runs</CardTitle></CardHeader><CardContent className="p-0"><div className="divide-y divide-border">{data.recent_runs.map(run => <div key={run.id} className="px-4 py-3 flex items-start justify-between gap-4"><div><span className={`text-xs font-medium px-2 py-0.5 rounded-full ${run.status === "ok" ? "bg-green-500/10 text-green-400" : "bg-red-500/10 text-red-400"}`}>{run.status}</span><p className="mt-1 text-sm">{run.summary ?? "—"}</p></div><span className="text-xs text-muted-foreground whitespace-nowrap">{fmtDateTime(run.created_at)}</span></div>)}</div></CardContent></Card>
  </div>;
}

function ZohoControlPanelView({ data, loading, onRefresh }: { data: ZohoControlPanel | null; loading: boolean; onRefresh: () => void }) {
  if (loading) return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>;
  if (!data) return <Card><CardContent className="py-10 text-center text-muted-foreground">Two-way Zoho control data is unavailable.</CardContent></Card>;
  const metricCards = [
    ["Waiting to sync", data.waiting.quote_exports + data.waiting.webhook_events, "text-amber-600"],
    ["Stale beyond SLA", data.stale_sla_records, data.stale_sla_records ? "text-red-600" : "text-green-600"],
    ["Conflicts", data.conflicts.length, data.conflicts.length ? "text-red-600" : "text-green-600"],
    ["Duplicate checks", data.duplicate_prevention_checks, "text-blue-600"],
  ] as const;
  return <div className="space-y-5">
    <div className="flex items-center justify-between"><div><p className="text-sm text-muted-foreground">Scope: <span className="font-mono text-foreground">{data.scope}</span></p><p className="text-xs text-muted-foreground">Standard plan mode · polling every {data.polling_interval_minutes} minutes</p></div><button onClick={onRefresh} className="h-9 px-3 rounded-md border border-border text-sm flex items-center gap-2 hover:bg-muted/40"><RefreshCw className="h-4 w-4" />Refresh</button></div>
    <div className="grid gap-4 md:grid-cols-4">{metricCards.map(([label, value, color]) => <Card key={label}><CardHeader className="pb-2"><CardTitle className="text-xs uppercase tracking-wider text-muted-foreground">{label}</CardTitle></CardHeader><CardContent><p className={`text-2xl font-display ${color}`}>{value.toLocaleString()}</p></CardContent></Card>)}</div>
    <Card><CardHeader><CardTitle className="font-display">Synchronization health</CardTitle></CardHeader><CardContent className="grid gap-3 md:grid-cols-4 text-sm"><div><span className="text-muted-foreground">Last successful poll</span><p>{data.last_successful_poll ?? "Not recorded"}</p></div><div><span className="text-muted-foreground">Pending quote exports</span><p>{data.waiting.quote_exports}</p></div><div><span className="text-muted-foreground">Pending inbound events</span><p>{data.waiting.webhook_events}</p></div><div><span className="text-muted-foreground">API calls remaining</span><p>{data.budget.remaining.toLocaleString()}</p></div></CardContent></Card>
    <Card><CardHeader><CardTitle className="font-display">Portal-to-Zoho events</CardTitle></CardHeader><CardContent className="p-0"><table className="w-full text-sm"><thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr><th className="px-4 py-3 text-left">When</th><th className="px-4 py-3 text-left">Event</th><th className="px-4 py-3 text-left">Record</th><th className="px-4 py-3 text-left">Details</th></tr></thead><tbody>{data.portal_events.map(event => <tr key={event.id} className="border-t border-border"><td className="px-4 py-2.5 text-xs text-muted-foreground">{fmtDateTime(event.created_at)}</td><td className="px-4 py-2.5">{event.action.replace(/_/g, " ")}</td><td className="px-4 py-2.5">{event.subject_type} #{event.subject_id ?? "—"}</td><td className="px-4 py-2.5 text-xs text-muted-foreground">{event.metadata ? JSON.stringify(event.metadata) : "—"}</td></tr>)}{data.portal_events.length === 0 && <tr><td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">No portal-to-Zoho events recorded.</td></tr>}</tbody></table></CardContent></Card>
    <Card><CardHeader><CardTitle className="font-display">Quote and invoice synchronization state</CardTitle></CardHeader><CardContent className="p-0"><table className="w-full text-sm"><thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr><th className="px-4 py-3 text-left">Reference</th><th className="px-4 py-3 text-left">Type</th><th className="px-4 py-3 text-left">Zoho ID</th><th className="px-4 py-3 text-left">State</th><th className="px-4 py-3 text-left">Updated</th></tr></thead><tbody>{data.quote_exports.map(row => <tr key={row.id} className="border-t border-border"><td className="px-4 py-2.5 font-mono text-xs">{row.reference}</td><td className="px-4 py-2.5">{row.source_type}</td><td className="px-4 py-2.5 font-mono text-xs">{row.estimate_id ?? "Pending"}</td><td className="px-4 py-2.5"><span className={`rounded-full px-2 py-0.5 text-xs ${row.state === "confirmed" ? "bg-green-500/10 text-green-600" : row.state === "rejected" ? "bg-red-500/10 text-red-600" : "bg-amber-500/10 text-amber-600"}`}>{row.state}</span></td><td className="px-4 py-2.5 text-xs text-muted-foreground">{fmtDateTime(row.updated_at)}</td></tr>)}{data.quote_exports.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">No synchronized quote records yet.</td></tr>}</tbody></table></CardContent></Card>
  </div>;
}
