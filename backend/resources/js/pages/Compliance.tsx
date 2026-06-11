import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Shield, AlertTriangle, CheckCircle, Clock, Globe, FileText, Download, X } from "lucide-react";
import { downloadCSV } from "@/lib/api-client";
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
  assignee: string;
}

const items: ComplianceItem[] = [
  { id: 1, matter: "US9876543 — Biotech Device Patent", type: "Patent", jurisdiction: "USPTO", deadline: "2026-06-18", daysLeft: 14, status: "Critical", action: "3.5-year maintenance fee", assignee: "Priya Sharma" },
  { id: 2, matter: "CTM-2024-00891 — GlobalTech Logo", type: "Trademark", jurisdiction: "EUIPO", deadline: "2026-06-25", daysLeft: 21, status: "Critical", action: "Trademark renewal filing", assignee: "Rahul Menon" },
  { id: 3, matter: "EP3456789 — Clean Energy System", type: "Patent", jurisdiction: "EPO", deadline: "2026-07-10", daysLeft: 36, status: "At Risk", action: "Annual renewal fee (Year 4)", assignee: "Kavya Nair" },
  { id: 4, matter: "IN202441087 — AI Algorithm Patent", type: "Patent", jurisdiction: "IPO India", deadline: "2026-07-22", daysLeft: 48, status: "At Risk", action: "Examination request deadline", assignee: "Priya Sharma" },
  { id: 5, matter: "PCT/US2024/12345 — Medical Device", type: "Patent", jurisdiction: "WIPO", deadline: "2026-08-05", daysLeft: 62, status: "At Risk", action: "National phase entry deadline", assignee: "Arjun Patel" },
  { id: 6, matter: "TM-ACME-BRAND — StellarBrands", type: "Trademark", jurisdiction: "USPTO", deadline: "2026-08-30", daysLeft: 87, status: "On Track", action: "Section 8 & 15 filing", assignee: "Rahul Menon" },
  { id: 7, matter: "US8765432 — Software Patent", type: "Patent", jurisdiction: "USPTO", deadline: "2026-09-15", daysLeft: 103, status: "On Track", action: "7.5-year maintenance fee", assignee: "Vikram Singh" },
  { id: 8, matter: "NovaMed Pharma — Class 5 TM", type: "Trademark", jurisdiction: "IPO India", deadline: "2026-10-01", daysLeft: 119, status: "On Track", action: "Trademark renewal (10 years)", assignee: "Kavya Nair" },
  { id: 9, matter: "EP2345678 — Semiconductor Device", type: "Patent", jurisdiction: "EPO", deadline: "2026-10-20", daysLeft: 138, status: "On Track", action: "Annual renewal fee (Year 6)", assignee: "Priya Sharma" },
  { id: 10, matter: "US7654321 — Optical System", type: "Patent", jurisdiction: "USPTO", deadline: "2026-11-12", daysLeft: 161, status: "Compliant", action: "11.5-year maintenance fee", assignee: "Arjun Patel" },
  { id: 11, matter: "EUIPO-TM-5678 — FutureTech Mark", type: "Trademark", jurisdiction: "EUIPO", deadline: "2026-12-01", daysLeft: 180, status: "Compliant", action: "Trademark renewal (10 years)", assignee: "Rahul Menon" },
  { id: 12, matter: "WO2024/09876 — IoT Platform", type: "Patent", jurisdiction: "WIPO", deadline: "2027-01-15", daysLeft: 225, status: "Compliant", action: "PCT Chapter II demand", assignee: "Vikram Singh" },
  { id: 13, matter: "US6543210 — Network Protocol", type: "Patent", jurisdiction: "USPTO", deadline: "2027-02-28", daysLeft: 269, status: "Compliant", action: "11.5-year maintenance fee", assignee: "Priya Sharma" },
  { id: 14, matter: "IN202312456 — Agri-Tech Patent", type: "Patent", jurisdiction: "IPO India", deadline: "2027-03-10", daysLeft: 279, status: "Compliant", action: "Annual renewal fee (Year 3)", assignee: "Kavya Nair" },
  { id: 15, matter: "CTM-2020-00234 — StrataTech Logo", type: "Trademark", jurisdiction: "EUIPO", deadline: "2027-04-22", daysLeft: 322, status: "Compliant", action: "Trademark renewal (10 years)", assignee: "Arjun Patel" },
];

const statusConfig: Record<AlertLevel, { color: string; bg: string; icon: React.ElementType }> = {
  Critical: { color: "text-red-600", bg: "bg-red-50 border-red-200", icon: AlertTriangle },
  "At Risk": { color: "text-amber-600", bg: "bg-amber-50 border-amber-200", icon: Clock },
  "On Track": { color: "text-blue-600", bg: "bg-blue-50 border-blue-200", icon: Clock },
  Compliant: { color: "text-green-600", bg: "bg-green-50 border-green-200", icon: CheckCircle },
};

const daysColor = (d: number) => d <= 30 ? "text-red-600 font-bold" : d <= 90 ? "text-amber-600 font-semibold" : "text-green-600";

export default function Compliance() {
  const [filterStatus, setFilterStatus] = useState<AlertLevel | "All">("All");
  const [filterType, setFilterType] = useState<MatterType | Jurisdiction | "All">("All");
  const [actionItem, setActionItem] = useState<number | null>(null);

  const critical = items.filter((i) => i.status === "Critical").length;
  const atRisk = items.filter((i) => i.status === "At Risk").length;
  const onTrack = items.filter((i) => i.status === "On Track").length;
  const compliant = items.filter((i) => i.status === "Compliant").length;

  const filtered = items.filter((i) => {
    if (filterStatus !== "All" && i.status !== filterStatus) return false;
    if (filterType !== "All" && i.type !== filterType && i.jurisdiction !== filterType) return false;
    return true;
  });

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
                    <>
                      <tr key={item.id} className="border-t border-border hover:bg-muted/30">
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
                        <tr key={`action-${item.id}`} className="border-t border-dashed border-gold/30 bg-gold/5">
                          <td colSpan={8} className="px-6 py-4">
                            <div className="flex flex-wrap items-center gap-3">
                              <div className="flex flex-wrap gap-2">
                                <Button size="sm" className="h-7 text-xs" onClick={() => alert(`Reminder set for ${item.matter} on ${item.deadline}`)}>Set Reminder</Button>
                                <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => alert(`Assigned to: ${item.assignee}`)}>Assign Attorney</Button>
                                <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => {
                                  const note = prompt("Enter note for this matter:");
                                  if (note) alert(`Note logged: "${note}" for ${item.matter}`);
                                }}>Log Note</Button>
                                <Button size="sm" variant="outline" className="h-7 text-xs border-green-200 text-green-600" onClick={() => setActionItem(null)}>Mark Resolved</Button>
                              </div>
                              <span className="text-xs text-muted-foreground">Deadline: <strong>{item.deadline}</strong> · {item.daysLeft} days remaining</span>
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
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
