import { Head } from "@inertiajs/react";
import { Fragment, useEffect, useState } from "react";
import { Shield, AlertTriangle, CheckCircle, Clock, Globe, Download, Loader2 } from "lucide-react";
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
  notes: { text: string; by: string; at: string }[];
}

const statusConfig: Record<AlertLevel, { color: string; bg: string; icon: React.ElementType }> = {
  Critical: { color: "text-red-600", bg: "bg-red-50 border-red-200", icon: AlertTriangle },
  "At Risk": { color: "text-amber-600", bg: "bg-amber-50 border-amber-200", icon: Clock },
  "On Track": { color: "text-blue-600", bg: "bg-blue-50 border-blue-200", icon: Clock },
  Compliant: { color: "text-green-600", bg: "bg-green-50 border-green-200", icon: CheckCircle },
};

const daysColor = (d: number) => d <= 30 ? "text-red-600 font-bold" : d <= 90 ? "text-amber-600 font-semibold" : "text-green-600";

export default function Compliance() {
  const [items, setItems] = useState<ComplianceItem[]>([]);
  const [users, setUsers] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatus, setFilterStatus] = useState<AlertLevel | "All">("All");
  const [filterType, setFilterType] = useState<MatterType | Jurisdiction | "All">("All");
  const [actionItem, setActionItem] = useState<number | null>(null);
  const [noteText, setNoteText] = useState("");
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Record<number, string>>({});

  const load = () => api.getCompliance().then(setItems).catch(() => {}).finally(() => setLoading(false));

  useEffect(() => {
    load();
    api.getUsers().then(setUsers).catch(() => {});
  }, []);

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

  async function assignAttorney(item: ComplianceItem, name: string) {
    if (!name) return;
    setBusy(true);
    try { await api.updateCompliance(item.id, { assignee: name }); say(item.id, `Assigned to ${name}.`); load(); }
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

  const critical = items.filter((i) => i.status === "Critical").length;
  const atRisk = items.filter((i) => i.status === "At Risk").length;
  const onTrack = items.filter((i) => i.status === "On Track").length;
  const compliant = items.filter((i) => i.status === "Compliant").length;

  const filtered = items.filter((i) => {
    if (filterStatus !== "All" && i.status !== filterStatus) return false;
    if (filterType !== "All" && i.type !== filterType && i.jurisdiction !== filterType) return false;
    return true;
  });

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
        actions={<Button variant="outline" size="sm" onClick={() => {
          const rows = filtered.map(i => ({ Matter: i.matter, Type: i.type, Jurisdiction: i.jurisdiction, Deadline: i.deadline, DaysLeft: i.daysLeft, Status: i.status, Action: i.action, Assignee: i.assignee }));
          downloadCSV(`compliance-report-${new Date().toISOString().slice(0,10)}.csv`, rows);
        }}><Download className="h-4 w-4 mr-2" />Export Report</Button>}
      />
      <div className="px-8 py-6 space-y-6">
        {/* Alert summary */}
        <div className="grid grid-cols-4 gap-4">
          {([
            { label: "Critical", count: critical, color: "border-red-200 bg-red-50", text: "text-red-600", icon: AlertTriangle },
            { label: "At Risk", count: atRisk, color: "border-amber-200 bg-amber-50", text: "text-amber-600", icon: Clock },
            { label: "On Track", count: onTrack, color: "border-blue-200 bg-blue-50", text: "text-blue-600", icon: Shield },
            { label: "Compliant", count: compliant, color: "border-green-200 bg-green-50", text: "text-green-600", icon: CheckCircle },
          ]).map(({ label, count, color, text, icon: Icon }) => (
            <Card key={label} className={`border ${color} cursor-pointer`} onClick={() => setFilterStatus(filterStatus === label as AlertLevel ? "All" : label as AlertLevel)}>
              <CardContent className="p-4 flex items-center gap-3">
                <Icon className={`h-8 w-8 ${text}`} />
                <div>
                  <div className={`text-2xl font-bold ${text}`}>{count}</div>
                  <div className="text-xs text-muted-foreground">{label}</div>
                </div>
              </CardContent>
            </Card>
          ))}
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
                                  {users.map((u) => <option key={u.id} value={u.name}>{u.name} ({u.role})</option>)}
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
              </tbody>
            </table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
