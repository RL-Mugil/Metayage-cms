import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { UserMinus, CheckSquare, Square, Calendar, Shield, FileText, Package, Key, Plus, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const checklistItems = [
  "Exit interview scheduled",
  "Knowledge transfer document prepared",
  "Handover to replacement complete",
  "IT assets returned (laptop, phone, access card)",
  "System access revoked (email, CRM, VPN)",
  "Final payroll processed",
  "Experience letter issued",
  "PF/gratuity settlement initiated",
];

interface Case {
  id: number;
  employee: string;
  dept: string;
  lastDay: string;
  exitType: "Resignation" | "Retirement" | "Termination";
  checklist: boolean[];
  assignedHR: string;
  status: "In Progress" | "Completed" | "Scheduled";
}

const exitColors: Record<Case["exitType"], string> = {
  Resignation: "text-amber-600 bg-amber-50 border-amber-200",
  Retirement: "text-blue-600 bg-blue-50 border-blue-200",
  Termination: "text-red-600 bg-red-50 border-red-200",
};

const checkIcons = [UserMinus, FileText, Package, Shield, Key, FileText, FileText, FileText];

export default function HRMSOffboarding() {
  const [selected, setSelected] = useState<number | null>(null);
  const [cases, setCases] = useState<Case[]>([]);
  const [completedCases, setCompletedCases] = useState<any[]>([]);
  const [checks, setChecks] = useState<Record<number, boolean[]>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showInitiate, setShowInitiate] = useState(false);
  const [showCompleted, setShowCompleted] = useState(false);
  const [form, setForm] = useState({ employee: "", last_day: "", exit_type: "Resignation", assigned_hr: "", dept: "" });

  const load = () => api.getOffboarding()
    .then((d) => {
      const cases = d.cases as unknown as Case[];
      setCases(cases);
      setCompletedCases(d.completed);
      setChecks(Object.fromEntries(cases.map((c) => [c.id, c.checklist])));
    })
    .catch(() => {})
    .finally(() => setLoading(false));

  useEffect(() => { load(); }, []);

  const toggleCheck = (caseId: number, idx: number) => {
    const next = (checks[caseId] || []).map((v, i) => (i === idx ? !v : v));
    setChecks((prev) => ({ ...prev, [caseId]: next }));
    api.updateOffboardingChecklist(caseId, next)
      .then((res) => {
        // A finished checklist moves the case to Completed; refresh the lists.
        if (res.status === "Completed") load();
      })
      .catch(() => load());
  };

  async function initiateCase() {
    if (!form.employee.trim() || !form.last_day.trim()) return;
    setSaving(true);
    try {
      await api.createOffboarding(form);
      setForm({ employee: "", last_day: "", exit_type: "Resignation", assigned_hr: "", dept: "" });
      setShowInitiate(false);
      load();
    } catch { /* keep form open */ }
    finally { setSaving(false); }
  }

  const getProgress = (caseId: number) => {
    const list = checks[caseId] || [];
    return Math.round((list.filter(Boolean).length / checklistItems.length) * 100);
  };

  if (loading) return (
    <AppLayout>
      <Head title="Offboarding" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Offboarding" />
      <PageHeader eyebrow="HRMS" title="Offboarding"
        description="Exit checklist, knowledge transfer, and clearance management"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowInitiate(true)}><Plus className="h-4 w-4 mr-2" />Initiate Offboarding</Button>}
      />
      <div className="px-8 py-6 space-y-6">
        {/* Initiate form */}
        {showInitiate && (
          <Card className="border-gold/30 bg-gold/5">
            <CardHeader className="pb-3"><CardTitle className="font-display text-base">Initiate Offboarding</CardTitle></CardHeader>
            <CardContent className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Employee</label>
                <input value={form.employee} onChange={(e) => setForm((p) => ({ ...p, employee: e.target.value }))}
                  placeholder="Employee name"
                  className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Last Working Day</label>
                <input type="date" value={form.last_day} onChange={(e) => setForm((p) => ({ ...p, last_day: e.target.value }))}
                  className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Exit Type</label>
                <select value={form.exit_type} onChange={(e) => setForm((p) => ({ ...p, exit_type: e.target.value }))}
                  className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                  {["Resignation", "Retirement", "Termination"].map((t) => <option key={t}>{t}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Assigned HR</label>
                <input value={form.assigned_hr} onChange={(e) => setForm((p) => ({ ...p, assigned_hr: e.target.value }))}
                  placeholder="HR person handling"
                  className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Department</label>
                <input value={form.dept} onChange={(e) => setForm((p) => ({ ...p, dept: e.target.value }))}
                  placeholder="e.g. Engineering"
                  className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div className="col-span-2 space-y-2">
                {(!form.employee.trim() || !form.last_day) && (
                  <p className="text-xs text-amber-500">
                    {!form.employee.trim() && !form.last_day ? "Employee name and last working day are required." : !form.employee.trim() ? "Employee name is required." : "Last working day is required."}
                  </p>
                )}
                <div className="flex gap-2">
                  <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={saving || !form.employee.trim() || !form.last_day} onClick={initiateCase}>
                    {saving ? <><Loader2 className="h-3 w-3 mr-1 animate-spin" />Creating…</> : "Create Offboarding Case"}
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setShowInitiate(false)}>Cancel</Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Active cases */}
        <div>
          <h3 className="text-sm font-semibold mb-3">Active Offboarding Cases ({cases.length})</h3>
          <div className="space-y-3">
            {cases.map((c) => {
              const prog = getProgress(c.id);
              return (
                <Card key={c.id} className={`border-border cursor-pointer transition-all ${selected === c.id ? "ring-2 ring-gold" : ""}`}>
                  <CardContent className="p-4">
                    <div className="flex items-start gap-4">
                      <div className="h-10 w-10 rounded-full bg-gold/20 flex items-center justify-center text-gold font-bold text-sm flex-shrink-0">
                        {c.employee.charAt(0)}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-3 flex-wrap">
                          <span className="font-semibold">{c.employee}</span>
                          <span className="text-xs text-muted-foreground">{c.dept}</span>
                          <span className={`px-2 py-0.5 rounded text-xs font-medium border ${exitColors[c.exitType]}`}>{c.exitType}</span>
                          <Badge variant="outline" className={c.status === "Completed" ? "text-green-600 border-green-200 bg-green-50 text-[10px]" : c.status === "Scheduled" ? "text-blue-600 border-blue-200 bg-blue-50 text-[10px]" : "text-[10px]"}>
                            {c.status}
                          </Badge>
                        </div>
                        <div className="flex items-center gap-4 mt-1.5 text-xs text-muted-foreground">
                          <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />Last day: {c.lastDay}</span>
                          <span>HR: {c.assignedHR}</span>
                        </div>
                        <div className="flex items-center gap-3 mt-2">
                          <div className="flex-1 h-1.5 bg-muted rounded-full">
                            <div className="h-full rounded-full transition-all" style={{ width: `${prog}%`, background: prog === 100 ? "#22c55e" : "#C8971D" }} />
                          </div>
                          <span className="text-xs text-muted-foreground whitespace-nowrap">{prog}% · {checks[c.id]?.filter(Boolean).length}/{checklistItems.length}</span>
                        </div>
                      </div>
                      <Button size="sm" variant="outline" className="h-7 text-xs flex-shrink-0"
                        onClick={() => setSelected(selected === c.id ? null : c.id)}>
                        {selected === c.id ? "Hide" : "View Checklist"}
                      </Button>
                    </div>

                    {/* Checklist */}
                    {selected === c.id && (
                      <div className="mt-4 pt-4 border-t border-border grid grid-cols-2 gap-2">
                        {checklistItems.map((item, idx) => {
                          const done = checks[c.id]?.[idx] ?? false;
                          const Icon = checkIcons[idx];
                          return (
                            <button key={idx} onClick={() => toggleCheck(c.id, idx)}
                              className={`flex items-center gap-3 p-2.5 rounded-lg border text-sm text-left transition-all ${done ? "bg-green-50 border-green-200 text-green-700" : "border-border hover:border-gold/50 hover:bg-muted/30"}`}>
                              {done ? <CheckSquare className="h-4 w-4 text-green-600 flex-shrink-0" /> : <Square className="h-4 w-4 text-muted-foreground flex-shrink-0" />}
                              <span className={done ? "line-through opacity-60" : ""}>{item}</span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </div>

        {/* Completed cases */}
        <div>
          <button className="flex items-center gap-2 text-sm font-semibold text-muted-foreground hover:text-foreground mb-3"
            onClick={() => setShowCompleted(!showCompleted)}>
            <span>{showCompleted ? "▾" : "▸"}</span> Completed Cases ({completedCases.length})
          </button>
          {showCompleted && (
            <Card className="border-border">
              <CardContent className="p-0">
                <table className="w-full text-sm">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 text-left">Employee</th>
                      <th className="px-4 py-3 text-left">Department</th>
                      <th className="px-4 py-3 text-left">Last Day</th>
                      <th className="px-4 py-3 text-left">Exit Type</th>
                      <th className="px-4 py-3 text-left">Completed On</th>
                    </tr>
                  </thead>
                  <tbody>
                    {completedCases.map((c) => (
                      <tr key={c.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-medium">{c.employee}</td>
                        <td className="px-4 py-3 text-muted-foreground">{c.dept}</td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">{c.lastDay}</td>
                        <td className="px-4 py-3"><span className={`px-2 py-0.5 rounded text-xs font-medium border ${exitColors[c.exitType as Case["exitType"]]}`}>{c.exitType}</span></td>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1.5 text-green-600 text-xs">
                            <CheckSquare className="h-3.5 w-3.5" />{c.completedDate}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
