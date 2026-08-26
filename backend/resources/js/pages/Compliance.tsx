import { Head } from "@inertiajs/react";
import { Fragment, useEffect, useState, useCallback } from "react";
import { Shield, AlertTriangle, CheckCircle, Clock, Globe, Download, Loader2, X, Search, ChevronLeft, ChevronRight, Plus } from "lucide-react";
import { api, downloadCSV } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

type AlertLevel = "Critical" | "At Risk" | "On Track" | "Compliant";
type MatterType = "Patent" | "Trademark" | "Copyright";
type Jurisdiction = "USPTO" | "EPO" | "WIPO" | "IPO India" | "EUIPO";

interface ComplianceItem {
  id: number;
  matter: string;
  type: MatterType;
  jurisdiction: Jurisdiction;
  deadline: string;
  daysLeft: number;
  status: AlertLevel;
  action: string;
  assignee: string | null;
  assignee_id: number | null;
  source_type: string;
  client: { id: number; client_code: string; legal_name?: string; company_name?: string } | null;
  project: { id: number; project_code: string; docket_number?: string; application_number?: string } | null;
  notes: { text: string; by: string; at: string }[];
}

const statusConfig: Record<AlertLevel, { color: string; bg: string; icon: React.ElementType }> = {
  Critical: { color: "text-red-600", bg: "bg-red-50 border-red-200", icon: AlertTriangle },
  "At Risk": { color: "text-amber-600", bg: "bg-amber-50 border-amber-200", icon: Clock },
  "On Track": { color: "text-blue-600", bg: "bg-blue-50 border-blue-200", icon: Clock },
  Compliant: { color: "text-green-600", bg: "bg-green-50 border-green-200", icon: CheckCircle },
};

const daysColor = (d: number) => d <= 30 ? "text-red-600 font-bold" : d <= 90 ? "text-amber-600 font-semibold" : "text-green-600";

interface ComplianceKpiDef { label: AlertLevel; key: string; color: string; bg: string; icon: React.ElementType }

const COMPLIANCE_KPI_DEFS: ComplianceKpiDef[] = [
  { label: "Critical", key: "critical", color: "text-red-600",   bg: "border-red-200 bg-red-50",    icon: AlertTriangle },
  { label: "At Risk",  key: "at_risk",  color: "text-amber-600", bg: "border-amber-200 bg-amber-50",icon: Clock },
  { label: "On Track", key: "on_track", color: "text-blue-600",  bg: "border-blue-200 bg-blue-50",  icon: Clock },
  { label: "Compliant",key: "compliant",color: "text-green-600", bg: "border-green-200 bg-green-50",icon: CheckCircle },
];

function ComplianceKpiModal({ kpi, onClose }: { kpi: ComplianceKpiDef; onClose: () => void }) {
  const [result, setResult]   = useState<{ data: any[]; total: number }>({ data: [], total: 0 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(false);
  const [page, setPage]       = useState(1);
  const PER_PAGE = 15;

  const fetchPage = useCallback(async (pg: number, q: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pg), status: kpi.label });
      if (q) params.set("search", q);
      const res: any = await api.getCompliancePaged(params);
      setResult({ data: Array.isArray(res) ? res : (res?.data ?? []), total: res?.total ?? 0 });
    } finally { setLoading(false); }
  }, [kpi]);

  useEffect(() => { fetchPage(1, ""); }, [fetchPage]);
  function handleSearch(q: string) { setSearch(q); setPage(1); fetchPage(1, q); }
  function goPage(pg: number) { setPage(pg); fetchPage(pg, search); }
  const totalPages = Math.max(1, Math.ceil(result.total / PER_PAGE));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className={`font-display text-lg font-semibold ${kpi.color}`}>{kpi.label}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{result.total} compliance item{result.total !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search matter…" value={search} onChange={(e) => handleSearch(e.target.value)} />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Matter</th>
                  <th className="px-4 py-3 text-left">Type</th>
                  <th className="px-4 py-3 text-left">Jurisdiction</th>
                  <th className="px-4 py-3 text-right">Days Left</th>
                  <th className="px-4 py-3 text-left">Deadline</th>
                  <th className="px-4 py-3 text-left">Assignee</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((item) => (
                  <tr key={item.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-2.5 font-medium max-w-[200px] truncate">{item.matter}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{item.type}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{item.jurisdiction}</td>
                    <td className={`px-4 py-2.5 text-xs font-mono text-right ${daysColor(item.daysLeft)}`}>{item.daysLeft}d</td>
                    <td className="px-4 py-2.5 text-xs font-mono text-muted-foreground">{fmtDate(item.deadline)}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{item.assignee ?? "—"}</td>
                  </tr>
                ))}
                {result.data.length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">No items found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <div className="flex items-center justify-between px-6 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
          <span>Showing {result.data.length} of {result.total}</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronLeft className="h-4 w-4" /></button>
            <span className="px-2">{page} / {totalPages}</span>
            <button onClick={() => goPage(page + 1)} disabled={page >= totalPages} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronRight className="h-4 w-4" /></button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function Compliance() {
  const [items, setItems] = useState<ComplianceItem[]>([]);
  const [users, setUsers] = useState<any[]>([]);
  const [clients, setClients] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatus, setFilterStatus] = useState<AlertLevel | "All">("All");
  const [filterType, setFilterType] = useState<MatterType | Jurisdiction | "All">("All");
  const [actionItem, setActionItem] = useState<number | null>(null);
  const [noteText, setNoteText] = useState("");
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Record<number, string>>({});
  const [complianceStats, setComplianceStats] = useState({ critical: 0, at_risk: 0, on_track: 0, compliant: 0 });
  const [kpiModal, setKpiModal] = useState<ComplianceKpiDef | null>(null);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [clientId, setClientId] = useState("");
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [showCreate, setShowCreate] = useState(false);
  const [newItem, setNewItem] = useState({ matter: "", type: "Patent", jurisdiction: "IPO India", deadline: "", action_required: "", client_id: "", assignee_id: "", note: "" });

  const load = useCallback(() => {
    setLoading(true); setError("");
    const params = new URLSearchParams({ per_page: "100" });
    if (search.trim()) params.set("search", search.trim());
    if (clientId) params.set("client_id", clientId);
    if (fromDate) params.set("from_date", fromDate);
    if (toDate) params.set("to_date", toDate);
    if (filterType !== "All") params.set((['USPTO','EPO','WIPO','IPO India','EUIPO'].includes(filterType) ? "jurisdiction" : "type"), filterType);
    if (filterStatus !== "All") params.set("status", filterStatus);
    return api.getCompliancePaged(params).then((d) => setItems((d?.data ?? []) as unknown as ComplianceItem[]))
      .catch((e: Error) => setError(e.message || "Compliance data could not be loaded."))
      .finally(() => setLoading(false));
  }, [search, clientId, fromDate, toDate, filterType, filterStatus]);

  useEffect(() => {
    load();
    api.getUsers().then(setUsers).catch((e: Error) => setError(e.message));
    api.getAllClients().then(setClients).catch((e: Error) => setError(e.message));
    api.getComplianceStats().then(setComplianceStats).catch(() => {});
  }, [load]);

  async function createItem() {
    setBusy(true); setError("");
    try {
      await api.createCompliance({ ...newItem, client_id: newItem.client_id ? Number(newItem.client_id) : null, assignee_id: newItem.assignee_id ? Number(newItem.assignee_id) : null });
      setShowCreate(false);
      setNewItem({ matter: "", type: "Patent", jurisdiction: "IPO India", deadline: "", action_required: "", client_id: "", assignee_id: "", note: "" });
      await load();
      const stats = await api.getComplianceStats(); setComplianceStats(stats);
    } catch (e) { setError(e instanceof Error ? e.message : "Compliance item could not be created."); }
    finally { setBusy(false); }
  }

  const say = (id: number, msg: string) => {
    setFeedback((p) => ({ ...p, [id]: msg }));
    setTimeout(() => setFeedback((p) => ({ ...p, [id]: "" })), 3000);
  };

  async function setReminder(item: ComplianceItem) {
    setBusy(true);
    try { await api.remindCompliance(item.id); say(item.id, "Reminder created — see Reminders page."); }
    catch (e: any) { say(item.id, e.message || "Failed."); }
    finally { setBusy(false); }
  }

  async function assignAttorney(item: ComplianceItem, userId: string) {
    if (!userId) return;
    const user = users.find((candidate) => String(candidate.id) === userId);
    setBusy(true);
    try { await api.updateCompliance(item.id, { assignee_id: Number(userId) }); say(item.id, `Assigned to ${user?.name ?? "selected user"}.`); load(); }
    catch (e: any) { say(item.id, e.message || "Failed."); }
    finally { setBusy(false); }
  }

  async function logNote(item: ComplianceItem) {
    if (!noteText.trim()) return;
    setBusy(true);
    try { await api.updateCompliance(item.id, { note: noteText.trim() }); setNoteText(""); say(item.id, "Note logged."); load(); }
    catch (e: any) { say(item.id, e.message || "Failed."); }
    finally { setBusy(false); }
  }

  async function markResolved(item: ComplianceItem) {
    setBusy(true);
    try { await api.updateCompliance(item.id, { resolved: true }); setActionItem(null); load(); }
    catch (e: any) { say(item.id, e.message || "Failed."); }
    finally { setBusy(false); }
  }

  const filtered = items;

  if (loading) return (
    <AppLayout>
      <Head title="Compliance" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Compliance" />
      <PageHeader
        eyebrow="Operations"
        title="Compliance & IP Deadlines"
        description="Track maintenance fees, renewals, and regulatory deadlines"
        actions={<div className="flex gap-2"><Button size="sm" onClick={() => setShowCreate(true)}><Plus className="h-4 w-4 mr-2" />Add obligation</Button><Button variant="outline" size="sm" onClick={() => {
          const rows = filtered.map(i => ({ Matter: i.matter, Type: i.type, Jurisdiction: i.jurisdiction, Deadline: i.deadline, DaysLeft: i.daysLeft, Status: i.status, Action: i.action, Assignee: i.assignee }));
          downloadCSV(`compliance-report-${new Date().toISOString().slice(0,10)}.csv`, rows);
        }}><Download className="h-4 w-4 mr-2" />Export Report</Button></div>}
      />
      <div className="px-8 py-6 space-y-6">
        {kpiModal && <ComplianceKpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} />}
        {showCreate && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <form className="w-full max-w-xl rounded-xl border border-border bg-background p-6 shadow-2xl space-y-4" onSubmit={(e) => { e.preventDefault(); createItem(); }}>
            <div className="flex justify-between"><div><h2 className="font-display text-lg font-semibold">Add compliance obligation</h2><p className="text-xs text-muted-foreground">Create a deadline not already generated from a case or renewal.</p></div><button type="button" onClick={() => setShowCreate(false)}><X className="h-5 w-5" /></button></div>
            <div className="grid grid-cols-2 gap-3">
              <input required className="col-span-2 h-9 rounded border bg-background px-3 text-sm" placeholder="Matter / reference" value={newItem.matter} onChange={e => setNewItem({...newItem, matter:e.target.value})} />
              <select className="h-9 rounded border bg-background px-3 text-sm" value={newItem.type} onChange={e => setNewItem({...newItem,type:e.target.value})}>{["Patent","Trademark","Copyright","Design","Other"].map(v=><option key={v}>{v}</option>)}</select>
              <input required className="h-9 rounded border bg-background px-3 text-sm" placeholder="Jurisdiction" value={newItem.jurisdiction} onChange={e => setNewItem({...newItem,jurisdiction:e.target.value})} />
              <input required type="date" className="h-9 rounded border bg-background px-3 text-sm" value={newItem.deadline} onChange={e => setNewItem({...newItem,deadline:e.target.value})} />
              <select className="h-9 rounded border bg-background px-3 text-sm" value={newItem.client_id} onChange={e => setNewItem({...newItem,client_id:e.target.value})}><option value="">No client</option>{clients.map(c=><option key={c.id} value={c.id}>{c.client_code} — {c.company_name || c.legal_name}</option>)}</select>
              <textarea required className="col-span-2 rounded border bg-background px-3 py-2 text-sm" placeholder="Action required" value={newItem.action_required} onChange={e => setNewItem({...newItem,action_required:e.target.value})} />
              <select className="h-9 rounded border bg-background px-3 text-sm" value={newItem.assignee_id} onChange={e => setNewItem({...newItem,assignee_id:e.target.value})}><option value="">Unassigned</option>{users.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select>
              <input className="h-9 rounded border bg-background px-3 text-sm" placeholder="Initial note (optional)" value={newItem.note} onChange={e => setNewItem({...newItem,note:e.target.value})} />
            </div>
            <div className="flex justify-end gap-2"><Button type="button" variant="outline" onClick={() => setShowCreate(false)}>Cancel</Button><Button disabled={busy} type="submit">{busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Create</Button></div>
          </form>
        </div>}
        {error && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error} <button className="ml-2 underline" onClick={() => load()}>Retry</button></div>}
        {/* Alert summary */}
        <div className="grid grid-cols-4 gap-4">
          {COMPLIANCE_KPI_DEFS.map((kpi) => {
            const Icon = kpi.icon;
            const count = complianceStats[kpi.key as keyof typeof complianceStats] ?? 0;
            return (
              <button key={kpi.key}
                onClick={() => setKpiModal(kpi)}
                className={`rounded-xl border ${kpi.bg} p-4 text-left transition-all hover:shadow-md hover:scale-[1.01] cursor-pointer`}
              >
                <div className="flex items-center gap-3">
                  <Icon className={`h-8 w-8 ${kpi.color}`} />
                  <div>
                    <div className={`text-2xl font-bold ${kpi.color}`}>{count}</div>
                    <div className="text-xs text-muted-foreground">{kpi.label}</div>
                  </div>
                </div>
                <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
              </button>
            );
          })}
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">Filter:</span>
          {(["All", "Patent", "Trademark", "USPTO", "EPO", "WIPO", "IPO India", "EUIPO"] as const).map((f) => (
            <button key={f} onClick={() => setFilterType(f as any)}
              className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${filterType === f ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"}`}>
              {f}
            </button>
          ))}
          <div className="relative ml-auto"><Search className="absolute left-2.5 top-2 h-4 w-4 text-muted-foreground"/><input className="h-8 w-52 rounded border bg-background pl-8 pr-2 text-xs" placeholder="Code, client, case, action" value={search} onChange={e=>setSearch(e.target.value)} /></div>
          <select className="h-8 max-w-56 rounded border bg-background px-2 text-xs" value={clientId} onChange={e=>setClientId(e.target.value)}><option value="">All clients</option>{clients.map(c=><option key={c.id} value={c.id}>{c.client_code} — {c.company_name || c.legal_name}</option>)}</select>
          <input aria-label="Deadline from" type="date" className="h-8 rounded border bg-background px-2 text-xs" value={fromDate} onChange={e=>setFromDate(e.target.value)} />
          <input aria-label="Deadline to" type="date" className="h-8 rounded border bg-background px-2 text-xs" value={toDate} onChange={e=>setToDate(e.target.value)} />
        </div>

        {/* Table */}
        <Card className="border-border">
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">IP Matter</th>
                  <th className="px-4 py-3 text-left">Type</th>
                  <th className="px-4 py-3 text-left">Jurisdiction</th>
                  <th className="px-4 py-3 text-left">Deadline</th>
                  <th className="px-4 py-3 text-left">Days Left</th>
                  <th className="px-4 py-3 text-left">Status</th>
                  <th className="px-4 py-3 text-left">Action Required</th>
                  <th className="px-4 py-3 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((item) => {
                  const { color, icon: Icon } = statusConfig[item.status];
                  return (
                    <Fragment key={item.id}>
                      <tr className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="font-medium text-xs">{item.matter}</div>
                          <div className="text-xs text-muted-foreground">{item.assignee}</div>
                        </td>
                        <td className="px-4 py-3"><Badge variant="outline" className="text-xs">{item.type}</Badge></td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Globe className="h-3 w-3" />{item.jurisdiction}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-xs font-mono">{fmtDate(item.deadline)}</td>
                        <td className={`px-4 py-3 text-sm ${daysColor(item.daysLeft)}`}>{item.daysLeft}d</td>
                        <td className="px-4 py-3">
                          <div className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium border ${statusConfig[item.status].bg} ${color}`}>
                            <Icon className="h-3 w-3" />{item.status}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">{item.action}</td>
                        <td className="px-4 py-3">
                          <Button size="sm" variant="outline" className="h-7 text-xs px-2"
                            onClick={() => setActionItem(actionItem === item.id ? null : item.id)}>
                            Take Action
                          </Button>
                        </td>
                      </tr>
                      {actionItem === item.id && (
                        <tr className="border-t border-dashed border-gold/30 bg-gold/5">
                          <td colSpan={8} className="px-6 py-4">
                            <div className="space-y-3">
                              <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" className="h-7 text-xs" disabled={busy} onClick={() => setReminder(item)}>Set Reminder</Button>
                                <select className="h-7 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                                  defaultValue="" disabled={busy}
                                  onChange={(e) => { assignAttorney(item, e.target.value); e.target.value = ""; }}>
                                  <option value="" disabled>Assign attorney…</option>
                                  {users.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.role})</option>)}
                                </select>
                                <Button size="sm" variant="outline" className="h-7 text-xs border-green-200 text-green-600" disabled={busy}
                                  onClick={() => markResolved(item)}>Mark Resolved</Button>
                                <span className="text-xs text-muted-foreground ml-auto">Deadline: <strong>{item.deadline}</strong> · {item.daysLeft} days remaining</span>
                              </div>
                              <div className="flex items-center gap-2">
                                <input value={noteText} onChange={(e) => setNoteText(e.target.value)}
                                  placeholder="Add a note for this matter…"
                                  className="flex-1 h-7 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                                <Button size="sm" variant="outline" className="h-7 text-xs" disabled={busy || !noteText.trim()}
                                  onClick={() => logNote(item)}>Log Note</Button>
                              </div>
                              {item.notes.length > 0 && (
                                <div className="space-y-1">
                                  {item.notes.map((n, i) => (
                                    <div key={i} className="text-xs text-muted-foreground">
                                      <span className="font-medium text-foreground">{n.by}</span> · {n.at}: {n.text}
                                    </div>
                                  ))}
                                </div>
                              )}
                              {feedback[item.id] && <div className="text-xs font-medium text-green-600">{feedback[item.id]}</div>}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
                {filtered.length === 0 && <tr><td colSpan={8} className="px-6 py-16 text-center"><Shield className="mx-auto mb-3 h-8 w-8 text-muted-foreground"/><div className="font-medium">No active compliance deadlines match these filters.</div><div className="mt-1 text-xs text-muted-foreground">Change the filters or add a manual obligation.</div></td></tr>}
              </tbody>
            </table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
