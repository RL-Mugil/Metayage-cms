import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Package, CheckSquare, ChevronRight, Download, Archive, Send, Users, FolderOpen, FileText, Check, Loader2 } from "lucide-react";
import { api, downloadCSV } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

type EntityType = "Clients" | "Projects" | "Tasks" | "Invoices" | "Employees";
type BulkAction = "Export CSV" | "Change Status" | "Archive" | "Send Notification";

interface Row { id: number; col1: string; col2: string; col3: string }

const headers: Record<EntityType, [string, string, string]> = {
  Clients: ["Name", "GST Type", "Status"],
  Projects: ["Matter", "Type", "Status"],
  Tasks: ["Title", "Priority", "Status"],
  Invoices: ["Invoice #", "Client", "Status"],
  Employees: ["Name", "Role", "Status"],
};

// Entities the backend allows mutations on; the rest are export-only.
const MUTABLE: Record<EntityType, string | null> = {
  Clients: "clients", Projects: "projects", Tasks: "tasks", Invoices: null, Employees: null,
};

const STATUS_OPTIONS: Record<string, string[]> = {
  clients: ["Active", "Inactive", "Prospect", "On Hold"],
  projects: ["Active", "On Hold", "Completed", "Archived"],
  tasks: ["Pending", "In Progress", "Completed", "Archived"],
};

async function fetchRows(entity: EntityType): Promise<Row[]> {
  const arr = (v: any) => (Array.isArray(v) ? v : v?.data || []);
  switch (entity) {
    case "Clients": {
      const params = new URLSearchParams(); params.set("per_page", "500");
      return arr(await api.getClients(params)).map((c: any) => ({
        id: c.id, col1: c.legal_name ?? c.company_name ?? "—", col2: c.gst_type ?? c.client_type ?? "—", col3: c.status ?? "Active",
      }));
    }
    case "Projects":
      return arr(await api.getProjects()).map((p: any) => ({
        id: p.id, col1: p.project_code ? `${p.project_code} — ${p.project_name}` : p.project_name, col2: p.project_type ?? "—", col3: p.status ?? "—",
      }));
    case "Tasks":
      return arr(await api.getTasks()).map((t: any) => ({
        id: t.id, col1: t.title, col2: t.priority ?? "—", col3: t.status ?? "—",
      }));
    case "Invoices":
      return arr(await api.getInvoices()).map((i: any) => ({
        id: i.id, col1: i.invoice_code ?? `INV-${i.id}`, col2: i.client?.company_name ?? "—", col3: i.status ?? "—",
      }));
    case "Employees":
      return arr(await api.getEmployees().catch(() => [])).map((e: any) => ({
        id: e.id, col1: e.full_name ?? e.name ?? "—", col2: e.designation?.title ?? e.role ?? "—", col3: e.status ?? "Active",
      }));
  }
}

export default function Bulk() {
  const [step, setStep] = useState(1);
  const [entity, setEntity] = useState<EntityType | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [loadingRows, setLoadingRows] = useState(false);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [action, setAction] = useState<BulkAction | "">("");
  const [statusValue, setStatusValue] = useState("");
  const [notifyTitle, setNotifyTitle] = useState("");
  const [notifyMessage, setNotifyMessage] = useState("");
  const [executing, setExecuting] = useState(false);
  const [done, setDone] = useState(false);
  const [resultMsg, setResultMsg] = useState("");

  const pickEntity = (e: EntityType) => {
    setEntity(e); setSelected(new Set()); setRows([]); setLoadingRows(true); setStep(2);
    fetchRows(e).then(setRows).catch(() => setRows([])).finally(() => setLoadingRows(false));
  };

  const toggleRow = (id: number) => {
    setSelected((prev) => { const next = new Set(prev); next.has(id) ? next.delete(id) : next.add(id); return next; });
  };
  const toggleAll = () => {
    setSelected(selected.size === rows.length ? new Set() : new Set(rows.map((r) => r.id)));
  };
  const reset = () => { setStep(1); setEntity(null); setRows([]); setSelected(new Set()); setAction(""); setStatusValue(""); setNotifyTitle(""); setNotifyMessage(""); setDone(false); setResultMsg(""); };

  const availableActions: { a: BulkAction; icon: any; desc: string }[] = [
    { a: "Export CSV", icon: Download, desc: "Download as CSV file" },
    ...(entity && MUTABLE[entity] ? [
      { a: "Change Status" as BulkAction, icon: CheckSquare, desc: "Update status for all selected" },
      { a: "Archive" as BulkAction, icon: Archive, desc: "Move to archive" },
      { a: "Send Notification" as BulkAction, icon: Send, desc: "Queue an in-app notification" },
    ] : []),
  ];

  async function execute() {
    if (!entity) return;
    if (action === "Export CSV") {
      const selectedRows = rows.filter((r) => selected.has(r.id));
      const [h1, h2, h3] = headers[entity];
      downloadCSV(`${entity.toLowerCase()}-export-${new Date().toISOString().slice(0, 10)}.csv`,
        selectedRows.map((r) => ({ [h1]: r.col1, [h2]: r.col2, [h3]: r.col3 })));
      setResultMsg(`Exported ${selected.size} records to CSV`);
      setDone(true);
      return;
    }
    const backendEntity = MUTABLE[entity];
    if (!backendEntity) return;
    setExecuting(true);
    try {
      const res = await api.bulkExecute({
        entity: backendEntity,
        ids: Array.from(selected),
        action: action === "Change Status" ? "change_status" : action === "Archive" ? "archive" : "notify",
        ...(action === "Change Status" ? { status: statusValue } : {}),
        ...(action === "Send Notification" ? { notify_title: notifyTitle || "Bulk Notification", notify_message: notifyMessage } : {}),
      } as any);
      setResultMsg(`${res.affected} records updated`);
      setDone(true);
    } catch (e: any) {
      setResultMsg(e.message || "Operation failed");
      setDone(true);
    } finally { setExecuting(false); }
  }

  const steps = ["Select Entity", "Choose Records", "Pick Action", "Execute"];

  return (
    <AppLayout>
      <Head title="Bulk Operations" />
      <PageHeader eyebrow="Operations" title="Bulk Operations" description="Select and act on multiple records at once"
        actions={<Button variant="outline" size="sm" onClick={reset}>Reset</Button>} />
      <div className="px-8 py-6 space-y-6">
        {/* Stepper */}
        <div className="flex items-center">
          {steps.map((s, i) => (
            <div key={s} className="flex items-center">
              <div className={`flex items-center gap-2 px-3 py-1.5 text-sm ${step > i+1 ? "text-green-600" : step === i+1 ? "text-gold font-semibold" : "text-muted-foreground"}`}>
                <span className={`h-6 w-6 rounded-full flex items-center justify-center text-xs font-bold border-2 ${step > i+1 ? "border-green-500 bg-green-50 text-green-600" : step === i+1 ? "border-gold bg-gold/10 text-gold" : "border-border text-muted-foreground"}`}>
                  {step > i+1 ? <Check className="h-3 w-3" /> : i+1}
                </span>
                {s}
              </div>
              {i < steps.length - 1 && <ChevronRight className="h-4 w-4 text-muted-foreground" />}
            </div>
          ))}
        </div>

        {done ? (
          <Card className="border-green-200 bg-green-50">
            <CardContent className="p-8 text-center">
              <div className="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
                <Check className="h-6 w-6 text-green-600" />
              </div>
              <div className="text-lg font-semibold text-green-700">{resultMsg}</div>
              <div className="text-sm text-green-600 mt-1">Action: <strong>{action}</strong> applied to <strong>{entity}</strong></div>
              <Button className="mt-5" variant="outline" onClick={reset}>Run Another Operation</Button>
            </CardContent>
          </Card>
        ) : (
          <>
            {step === 1 && (
              <div>
                <p className="text-sm text-muted-foreground mb-4">Choose the type of records you want to operate on</p>
                <div className="grid grid-cols-5 gap-3">
                  {([
                    { e: "Clients" as EntityType, icon: Users },
                    { e: "Projects" as EntityType, icon: FolderOpen },
                    { e: "Tasks" as EntityType, icon: CheckSquare },
                    { e: "Invoices" as EntityType, icon: FileText },
                    { e: "Employees" as EntityType, icon: Users },
                  ]).map(({ e, icon: Icon }) => (
                    <button key={e} onClick={() => pickEntity(e)}
                      className="p-5 rounded-xl border-2 border-border hover:border-gold hover:bg-gold/5 transition-all flex flex-col items-center gap-2 text-sm font-medium">
                      <Icon className="h-8 w-8 text-gold" />{e}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {step === 2 && entity && (
              <div>
                <div className="flex items-center justify-between mb-3">
                  <p className="text-sm text-muted-foreground"><span className="text-gold font-semibold">{selected.size}</span> of {rows.length} selected</p>
                  <Button size="sm" disabled={selected.size === 0} onClick={() => setStep(3)}>Continue <ChevronRight className="h-4 w-4 ml-1" /></Button>
                </div>
                <Card className="border-border">
                  <CardContent className="p-0">
                    {loadingRows ? (
                      <div className="flex items-center justify-center py-12">
                        <Loader2 className="h-6 w-6 animate-spin text-gold" />
                      </div>
                    ) : (
                      <table className="w-full text-sm">
                        <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                          <tr>
                            <th className="px-4 py-3 w-10"><input type="checkbox" checked={rows.length > 0 && selected.size === rows.length} onChange={toggleAll} /></th>
                            {headers[entity].map((h) => <th key={h} className="px-4 py-3 text-left">{h}</th>)}
                          </tr>
                        </thead>
                        <tbody>
                          {rows.map((row) => (
                            <tr key={row.id} onClick={() => toggleRow(row.id)}
                              className={`border-t border-border cursor-pointer ${selected.has(row.id) ? "bg-gold/5" : "hover:bg-muted/30"}`}>
                              <td className="px-4 py-3"><input type="checkbox" checked={selected.has(row.id)} onChange={() => {}} /></td>
                              <td className="px-4 py-3 font-medium">{row.col1}</td>
                              <td className="px-4 py-3 text-muted-foreground">{row.col2}</td>
                              <td className="px-4 py-3"><Badge variant="outline">{row.col3}</Badge></td>
                            </tr>
                          ))}
                          {rows.length === 0 && (
                            <tr><td colSpan={4} className="px-4 py-10 text-center text-sm text-muted-foreground">No records found.</td></tr>
                          )}
                        </tbody>
                      </table>
                    )}
                  </CardContent>
                </Card>
              </div>
            )}

            {step === 3 && (
              <div>
                <p className="text-sm text-muted-foreground mb-4">Choose action for {selected.size} {entity} records</p>
                <div className="grid grid-cols-3 gap-3">
                  {availableActions.map(({ a, icon: Icon, desc }) => (
                    <button key={a} onClick={() => { setAction(a); setStatusValue(""); setStep(4); }}
                      className="p-4 rounded-xl border-2 border-border hover:border-gold hover:bg-gold/5 text-left transition-all">
                      <Icon className="h-6 w-6 text-gold mb-2" />
                      <div className="font-medium text-sm">{a}</div>
                      <div className="text-xs text-muted-foreground mt-0.5">{desc}</div>
                    </button>
                  ))}
                </div>
                {entity && !MUTABLE[entity] && (
                  <p className="text-xs text-muted-foreground mt-3">{entity} are export-only — status changes must go through their own module.</p>
                )}
              </div>
            )}

            {step === 4 && (
              <Card className="border-border max-w-md">
                <CardHeader><CardTitle className="font-display">Confirm & Execute</CardTitle></CardHeader>
                <CardContent className="space-y-4">
                  <div className="rounded-lg bg-muted/40 p-4 space-y-2 text-sm">
                    <div className="flex justify-between"><span className="text-muted-foreground">Entity</span><span className="font-medium">{entity}</span></div>
                    <div className="flex justify-between"><span className="text-muted-foreground">Records</span><span className="font-medium">{selected.size} selected</span></div>
                    <div className="flex justify-between"><span className="text-muted-foreground">Action</span><span className="font-medium">{action}</span></div>
                  </div>
                  {action === "Change Status" && entity && MUTABLE[entity] && (
                    <div>
                      <label className="block text-xs text-muted-foreground mb-1">New status</label>
                      <select value={statusValue} onChange={(e) => setStatusValue(e.target.value)}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                        <option value="">Select status…</option>
                        {STATUS_OPTIONS[MUTABLE[entity]!].map((s) => <option key={s}>{s}</option>)}
                      </select>
                    </div>
                  )}
                  {action === "Send Notification" && (
                    <div className="space-y-2">
                      <div>
                        <label className="block text-xs text-muted-foreground mb-1">Notification Title</label>
                        <input value={notifyTitle} onChange={(e) => setNotifyTitle(e.target.value)}
                          placeholder="e.g. Action Required"
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <label className="block text-xs text-muted-foreground mb-1">Message Body</label>
                        <textarea value={notifyMessage} onChange={(e) => setNotifyMessage(e.target.value)}
                          rows={3} placeholder="Enter the notification message…"
                          className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                  )}
                  <div className="flex gap-3">
                    <Button className="flex-1" disabled={executing || (action === "Change Status" && !statusValue) || (action === "Send Notification" && !notifyMessage.trim())} onClick={execute}>
                      {executing ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Package className="h-4 w-4 mr-2" />}
                      Execute on {selected.size} Records
                    </Button>
                    <Button variant="outline" onClick={() => setStep(3)}>Back</Button>
                  </div>
                </CardContent>
              </Card>
            )}
          </>
        )}
      </div>
    </AppLayout>
  );
}
