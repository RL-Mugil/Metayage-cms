import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Package, CheckSquare, ChevronRight, Download, Archive, Send, Users, FolderOpen, FileText, Check } from "lucide-react";
import { downloadCSV } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

type EntityType = "Clients" | "Projects" | "Tasks" | "Invoices" | "Employees";
type BulkAction = "Export CSV" | "Change Status" | "Assign To" | "Archive" | "Send Notification";

const mockData: Record<EntityType, { id: number; col1: string; col2: string; col3: string }[]> = {
  Clients: [
    { id: 1, col1: "Acme Corporation", col2: "Enterprise", col3: "Active" },
    { id: 2, col1: "TechSolutions Pvt Ltd", col2: "SME", col3: "Active" },
    { id: 3, col1: "GlobalTech Ltd", col2: "Enterprise", col3: "Active" },
    { id: 4, col1: "BioVentures Pvt Ltd", col2: "Startup", col3: "Prospect" },
    { id: 5, col1: "StrataTech Inc", col2: "SME", col3: "Inactive" },
    { id: 6, col1: "NovaMed Sciences", col2: "Enterprise", col3: "Active" },
    { id: 7, col1: "StellarBrands Ltd", col2: "SME", col3: "Active" },
    { id: 8, col1: "InnovateCo Pvt Ltd", col2: "Startup", col3: "Active" },
    { id: 9, col1: "MediCare Holdings", col2: "Enterprise", col3: "Prospect" },
    { id: 10, col1: "FutureTech Ventures", col2: "Startup", col3: "Active" },
  ],
  Projects: [
    { id: 1, col1: "PAT-2024-001 — Biotech Device", col2: "Patent", col3: "Active" },
    { id: 2, col1: "TM-2024-015 — Brand Logo", col2: "Trademark", col3: "Pending" },
    { id: 3, col1: "PAT-2024-022 — AI Algorithm", col2: "Patent", col3: "Active" },
    { id: 4, col1: "CP-2024-008 — Software Suite", col2: "Copyright", col3: "Completed" },
    { id: 5, col1: "TM-2024-031 — Product Name", col2: "Trademark", col3: "Active" },
    { id: 6, col1: "PAT-2024-044 — Medical Device", col2: "Patent", col3: "On Hold" },
    { id: 7, col1: "TM-2024-052 — Service Mark", col2: "Trademark", col3: "Active" },
    { id: 8, col1: "PAT-2024-061 — Clean Energy", col2: "Patent", col3: "Active" },
    { id: 9, col1: "CP-2024-019 — Training Material", col2: "Copyright", col3: "Completed" },
    { id: 10, col1: "TM-2024-068 — Trade Dress", col2: "Trademark", col3: "Pending" },
  ],
  Tasks: [
    { id: 1, col1: "Draft patent claims — NovaMed", col2: "High", col3: "In Progress" },
    { id: 2, col1: "Office action response — US2024-0891", col2: "High", col3: "Pending" },
    { id: 3, col1: "Prior art search — AI algorithm", col2: "Medium", col3: "In Progress" },
    { id: 4, col1: "IDS filing — PAT-2024-001", col2: "High", col3: "Overdue" },
    { id: 5, col1: "Client review meeting prep", col2: "Low", col3: "Pending" },
    { id: 6, col1: "Trademark watch report", col2: "Medium", col3: "Completed" },
    { id: 7, col1: "Renewal deadline check", col2: "High", col3: "Pending" },
    { id: 8, col1: "PCT filing preparation", col2: "High", col3: "In Progress" },
    { id: 9, col1: "EPO examination response", col2: "Medium", col3: "Pending" },
    { id: 10, col1: "Maintenance fee payment", col2: "High", col3: "Pending" },
  ],
  Invoices: [
    { id: 1, col1: "INV-2026-044", col2: "Acme Corp", col3: "Paid" },
    { id: 2, col1: "INV-2026-043", col2: "TechSolutions", col3: "Overdue" },
    { id: 3, col1: "INV-2026-042", col2: "GlobalTech", col3: "Sent" },
    { id: 4, col1: "INV-2026-041", col2: "NovaMed", col3: "Draft" },
    { id: 5, col1: "INV-2026-040", col2: "StrataTech", col3: "Paid" },
    { id: 6, col1: "INV-2026-039", col2: "BioVentures", col3: "Sent" },
    { id: 7, col1: "INV-2026-038", col2: "StrataTech", col3: "Overdue" },
    { id: 8, col1: "INV-2026-037", col2: "StellarBrands", col3: "Paid" },
    { id: 9, col1: "INV-2026-036", col2: "MediCare", col3: "Draft" },
    { id: 10, col1: "INV-2026-035", col2: "FutureTech", col3: "Sent" },
  ],
  Employees: [
    { id: 1, col1: "Priya Sharma", col2: "Patent Attorney", col3: "Active" },
    { id: 2, col1: "Rahul Menon", col2: "IP Paralegal", col3: "Active" },
    { id: 3, col1: "Kavya Nair", col2: "Technical Writer", col3: "Active" },
    { id: 4, col1: "Arjun Patel", col2: "Associate Attorney", col3: "Active" },
    { id: 5, col1: "Divya Krishnan", col2: "HR Executive", col3: "Active" },
    { id: 6, col1: "Sanjay Reddy", col2: "Business Dev", col3: "Active" },
    { id: 7, col1: "Meera Iyer", col2: "Patent Analyst", col3: "On Leave" },
    { id: 8, col1: "Vikram Singh", col2: "Legal Counsel", col3: "Active" },
    { id: 9, col1: "Ananya Gupta", col2: "Trademark Specialist", col3: "Active" },
    { id: 10, col1: "Ravi Kumar", col2: "IP Researcher", col3: "Active" },
  ],
};

const headers: Record<EntityType, [string, string, string]> = {
  Clients: ["Name", "Tier", "Status"],
  Projects: ["Matter", "Type", "Status"],
  Tasks: ["Title", "Priority", "Status"],
  Invoices: ["Invoice #", "Client", "Status"],
  Employees: ["Name", "Role", "Status"],
};

export default function Bulk() {
  const [step, setStep] = useState(1);
  const [entity, setEntity] = useState<EntityType | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [action, setAction] = useState<BulkAction | "">("");
  const [done, setDone] = useState(false);

  const toggleRow = (id: number) => {
    setSelected((prev) => { const next = new Set(prev); next.has(id) ? next.delete(id) : next.add(id); return next; });
  };
  const toggleAll = () => {
    if (!entity) return;
    setSelected(selected.size === mockData[entity].length ? new Set() : new Set(mockData[entity].map((r) => r.id)));
  };
  const reset = () => { setStep(1); setEntity(null); setSelected(new Set()); setAction(""); setDone(false); };

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
              <div className="text-lg font-semibold text-green-700">Successfully processed {selected.size} records</div>
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
                    <button key={e} onClick={() => { setEntity(e); setSelected(new Set()); setStep(2); }}
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
                  <p className="text-sm text-muted-foreground"><span className="text-gold font-semibold">{selected.size}</span> of {mockData[entity].length} selected</p>
                  <Button size="sm" disabled={selected.size === 0} onClick={() => setStep(3)}>Continue <ChevronRight className="h-4 w-4 ml-1" /></Button>
                </div>
                <Card className="border-border">
                  <CardContent className="p-0">
                    <table className="w-full text-sm">
                      <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                        <tr>
                          <th className="px-4 py-3 w-10"><input type="checkbox" checked={selected.size === mockData[entity].length} onChange={toggleAll} /></th>
                          {headers[entity].map((h) => <th key={h} className="px-4 py-3 text-left">{h}</th>)}
                        </tr>
                      </thead>
                      <tbody>
                        {mockData[entity].map((row) => (
                          <tr key={row.id} onClick={() => toggleRow(row.id)}
                            className={`border-t border-border cursor-pointer ${selected.has(row.id) ? "bg-gold/5" : "hover:bg-muted/30"}`}>
                            <td className="px-4 py-3"><input type="checkbox" checked={selected.has(row.id)} onChange={() => {}} /></td>
                            <td className="px-4 py-3 font-medium">{row.col1}</td>
                            <td className="px-4 py-3 text-muted-foreground">{row.col2}</td>
                            <td className="px-4 py-3"><Badge variant="outline">{row.col3}</Badge></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </CardContent>
                </Card>
              </div>
            )}

            {step === 3 && (
              <div>
                <p className="text-sm text-muted-foreground mb-4">Choose action for {selected.size} {entity} records</p>
                <div className="grid grid-cols-3 gap-3">
                  {([
                    { a: "Export CSV" as BulkAction, icon: Download, desc: "Download as CSV file" },
                    { a: "Change Status" as BulkAction, icon: CheckSquare, desc: "Update status for all selected" },
                    { a: "Assign To" as BulkAction, icon: Users, desc: "Reassign to a team member" },
                    { a: "Archive" as BulkAction, icon: Archive, desc: "Move to archive" },
                    { a: "Send Notification" as BulkAction, icon: Send, desc: "Notify clients or assignees" },
                  ]).map(({ a, icon: Icon, desc }) => (
                    <button key={a} onClick={() => { setAction(a); setStep(4); }}
                      className="p-4 rounded-xl border-2 border-border hover:border-gold hover:bg-gold/5 text-left transition-all">
                      <Icon className="h-6 w-6 text-gold mb-2" />
                      <div className="font-medium text-sm">{a}</div>
                      <div className="text-xs text-muted-foreground mt-0.5">{desc}</div>
                    </button>
                  ))}
                </div>
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
                  <div className="flex gap-3">
                    <Button className="flex-1" onClick={() => {
                      if (action === "Export CSV" && entity) {
                        const selectedRows = mockData[entity].filter(r => selected.has(r.id));
                        const [h1, h2, h3] = headers[entity];
                        const rows = selectedRows.map(r => ({ [h1]: r.col1, [h2]: r.col2, [h3]: r.col3 }));
                        downloadCSV(`${entity.toLowerCase()}-export-${new Date().toISOString().slice(0,10)}.csv`, rows);
                      }
                      setDone(true);
                    }}>
                      <Package className="h-4 w-4 mr-2" /> Execute on {selected.size} Records
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
