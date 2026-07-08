import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
  FileText,
  Download,
  Calendar,
  BarChart3,
  Clock,
  Wallet,
  Users,
  FolderOpen,
  Loader2,
  AlertTriangle,
  TrendingUp,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";

interface ReportTemplate {
  id: string;
  name: string;
  description: string;
  icon: React.ElementType;
  color: string;
}

interface ReportHistoryItem {
  id: number;
  name: string;
  type: string;
  generated_by: string;
  generated_at: string;
  format: string;
  row_count: number;
  filters: Record<string, string | null>;
}

const REPORT_TEMPLATES: ReportTemplate[] = [
  { id: "client-portfolio", name: "Client Portfolio Report", description: "All clients with case summary, GST type, and billing overview", icon: Users, color: "text-blue-400" },
  { id: "matter-status", name: "Case Status Report", description: "Current status of all active cases by stage and priority", icon: FolderOpen, color: "text-green-400" },
  { id: "financial-summary", name: "Financial Summary", description: "Revenue, invoices, payments, and outstanding balances", icon: Wallet, color: "text-gold" },
  { id: "hrms", name: "HRMS Report", description: "Employee attendance, leave balances, and payroll summary", icon: Users, color: "text-purple-400" },
  { id: "ip-deadline", name: "IP Deadline Report", description: "Upcoming maintenance fees, renewals, and filing deadlines", icon: Clock, color: "text-red-400" },
  { id: "productivity", name: "Productivity Report", description: "Team task completion rates, time logs, and utilisation", icon: BarChart3, color: "text-cyan-400" },
  { id: "tracker-workload", name: "Team Workload Report", description: "Cases per PCM / SCM / PR with overdue count from Project Tracker", icon: Users, color: "text-indigo-400" },
  { id: "overdue-cases", name: "Overdue Cases Report", description: "All past-due delivery dates with assigned team member details", icon: AlertTriangle, color: "text-red-500" },
  { id: "deadline-forecast", name: "Deadline Forecast", description: "Upcoming delivery deadlines in the next 30 to 60 days", icon: Clock, color: "text-amber-400" },
  { id: "payment-collection", name: "Payment Collection", description: "Paid / Partial / Pending breakdown per client from tracker", icon: TrendingUp, color: "text-green-400" },
];

interface GeneratorState {
  reportId: string;
  fromDate: string;
  toDate: string;
  format: "PDF" | "Excel" | "CSV";
}

function exportPDF(reportName: string, rows: Record<string, unknown>[]) {
  if (!rows.length) return;
  const headers = Object.keys(rows[0]);
  const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>${reportName}</title>
<style>
  body{font-family:Arial,sans-serif;font-size:11px;margin:24px;color:#111}
  h1{font-size:17px;font-weight:700;margin:0 0 4px}
  .meta{color:#666;font-size:10px;margin-bottom:14px}
  table{width:100%;border-collapse:collapse}
  th{background:#f4f4f4;font-weight:600;text-align:left;padding:6px 8px;border:1px solid #ddd;font-size:10px;text-transform:uppercase;letter-spacing:.05em}
  td{padding:5px 8px;border:1px solid #eee;vertical-align:top;font-size:11px}
  tr:nth-child(even) td{background:#fafafa}
  @media print{body{margin:0}@page{margin:1.2cm}}
</style>
</head>
<body>
<h1>${reportName}</h1>
<div class="meta">Generated: ${new Date().toLocaleString("en-IN")} | ${rows.length} records</div>
<table>
<thead><tr>${headers.map((header) => `<th>${header}</th>`).join("")}</tr></thead>
<tbody>${rows.map((row) => `<tr>${headers.map((header) => `<td>${row[header] ?? ""}</td>`).join("")}</tr>`).join("")}</tbody>
</table>
</body></html>`;
  const win = window.open("", "_blank");
  if (!win) {
    alert("Allow popups to export PDF.");
    return;
  }
  win.document.write(html);
  win.document.close();
  setTimeout(() => win.print(), 600);
}

function formatBadgeVariant(format: string): "default" | "secondary" | "outline" | "destructive" {
  if (format === "PDF") return "destructive";
  if (format === "EXCEL") return "default";
  return "outline";
}

export default function Reports() {
  const [activeReport, setActiveReport] = useState<ReportTemplate | null>(null);
  const [generator, setGenerator] = useState<GeneratorState>({
    reportId: "",
    fromDate: "",
    toDate: "",
    format: "PDF",
  });
  const [generating, setGenerating] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [history, setHistory] = useState<ReportHistoryItem[]>([]);
  const [historyLoading, setHistoryLoading] = useState(true);

  function loadHistory() {
    setHistoryLoading(true);
    api.getReportHistory()
      .then(setHistory)
      .catch(() => setHistory([]))
      .finally(() => setHistoryLoading(false));
  }

  useEffect(() => {
    loadHistory();
  }, []);

  function openGenerator(template: ReportTemplate) {
    setActiveReport(template);
    setGenerator({
      reportId: template.id,
      fromDate: "",
      toDate: "",
      format: "PDF",
    });
    setSuccessMsg(null);
  }

  async function handleGenerate() {
    if (!activeReport) return;
    setGenerating(true);
    setSuccessMsg(null);

    try {
      const result = await api.generateReport({
        type: activeReport.id,
        format: generator.format,
        fromDate: generator.fromDate,
        toDate: generator.toDate,
      });

      if (generator.format === "PDF") {
        exportPDF(activeReport.name, result.rows as Record<string, unknown>[]);
        setSuccessMsg(`${activeReport.name} generated and saved to report history.`);
      } else {
        downloadCSV(`${activeReport.id}-${new Date().toISOString().slice(0, 10)}.csv`, result.rows as Record<string, any>[]);
        setSuccessMsg(`${activeReport.name} exported and saved to report history.`);
      }

      loadHistory();
    } catch (e: any) {
      setSuccessMsg(null);
      alert(e.message || "Failed to generate report.");
    } finally {
      setGenerating(false);
    }
  }

  async function downloadHistoryItem(item: ReportHistoryItem) {
    const saved = await api.getReportHistoryItem(item.id);
    if (saved.format === "PDF") {
      exportPDF(saved.name, saved.rows);
      return;
    }

    downloadCSV(`${saved.type}-${item.generated_at.slice(0, 10)}.csv`, saved.rows as Record<string, any>[]);
  }

  return (
    <AppLayout>
      <Head title="Reports" />
      <PageHeader eyebrow="Insight" title="Reports" description="Generate and export reports across all practice areas" />

      <div className="px-8 py-6 space-y-8">
        {successMsg && (
          <div className="rounded-lg border border-green-500/40 bg-green-500/10 px-4 py-3 text-sm text-green-400 flex items-start gap-2">
            <FileText className="h-4 w-4 mt-0.5 flex-shrink-0" />
            <span>{successMsg}</span>
            <button onClick={() => setSuccessMsg(null)} className="ml-auto text-green-400/60 hover:text-green-400">
              x
            </button>
          </div>
        )}

        <section>
          <div className="mb-4">
            <h2 className="text-base font-semibold font-display">Report Templates</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Select a template to configure and generate</p>
          </div>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {REPORT_TEMPLATES.map((template) => {
              const Icon = template.icon;
              const isActive = activeReport?.id === template.id;
              return (
                <Card
                  key={template.id}
                  className={`border-border transition-colors cursor-pointer ${isActive ? "border-gold/50 bg-gold/5" : "hover:border-border/80 hover:bg-muted/20"}`}
                  onClick={() => openGenerator(template)}
                >
                  <CardContent className="p-5">
                    <div className="flex items-start gap-3">
                      <div className="h-9 w-9 rounded-lg bg-muted flex items-center justify-center flex-shrink-0">
                        <Icon className={`h-5 w-5 ${template.color}`} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-sm">{template.name}</p>
                        <p className="text-xs text-muted-foreground mt-1 leading-relaxed">{template.description}</p>
                      </div>
                    </div>
                    <div className="mt-4 flex justify-end">
                      <Button
                        size="sm"
                        variant={isActive ? "default" : "outline"}
                        onClick={(event) => {
                          event.stopPropagation();
                          openGenerator(template);
                        }}
                      >
                        Generate
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </section>

        {activeReport && (
          <section>
            <Card className="border-gold/30 bg-gold/5">
              <CardHeader className="pb-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <activeReport.icon className={`h-5 w-5 ${activeReport.color}`} />
                    <CardTitle className="font-display text-base">{activeReport.name}</CardTitle>
                  </div>
                  <button onClick={() => { setActiveReport(null); setSuccessMsg(null); }} className="text-muted-foreground hover:text-foreground text-sm">
                    x Close
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">{activeReport.description}</p>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 items-end">
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                      <Calendar className="h-3.5 w-3.5" /> From Date
                    </label>
                    <Input type="date" value={generator.fromDate} onChange={(e) => setGenerator((g) => ({ ...g, fromDate: e.target.value }))} />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                      <Calendar className="h-3.5 w-3.5" /> To Date
                    </label>
                    <Input type="date" value={generator.toDate} onChange={(e) => setGenerator((g) => ({ ...g, toDate: e.target.value }))} />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Format</label>
                    <div className="flex gap-2">
                      {(["PDF", "Excel", "CSV"] as const).map((format) => (
                        <button
                          key={format}
                          onClick={() => setGenerator((g) => ({ ...g, format }))}
                          className={`flex-1 rounded-md border px-2 py-1.5 text-xs font-medium transition-colors ${generator.format === format ? "border-gold bg-gold/10 text-gold" : "border-border text-muted-foreground hover:border-gold/30"}`}
                        >
                          {format}
                        </button>
                      ))}
                    </div>
                  </div>
                  <div className="space-y-2">
                    <div className="text-xs text-muted-foreground">Each export is stored as a point-in-time snapshot in report history.</div>
                    <Button className="w-full" onClick={handleGenerate} disabled={generating}>
                      {generating ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Generating...
                        </>
                      ) : (
                        <>
                          <FileText className="h-4 w-4 mr-2" />
                          Generate Report
                        </>
                      )}
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          </section>
        )}

        <section>
          <div className="mb-4">
            <h2 className="text-base font-semibold font-display">Recent Reports</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Saved point-in-time report snapshots</p>
          </div>
          <Card className="border-border">
            <CardContent className="p-0">
              {historyLoading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="h-5 w-5 animate-spin text-gold" />
                </div>
              ) : history.length === 0 ? (
                <div className="px-6 py-10 text-sm text-muted-foreground">No saved report exports yet.</div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 text-left">Report Name</th>
                      <th className="px-4 py-3 text-left">Generated By</th>
                      <th className="px-4 py-3 text-left">Date</th>
                      <th className="px-4 py-3 text-left">Format</th>
                      <th className="px-4 py-3 text-left">Rows</th>
                      <th className="px-4 py-3 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {history.map((item) => (
                      <tr key={item.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-medium">{item.name}</td>
                        <td className="px-4 py-3 text-muted-foreground">{item.generated_by}</td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{item.generated_at}</td>
                        <td className="px-4 py-3">
                          <Badge variant={formatBadgeVariant(item.format)} className="text-[10px]">
                            {item.format}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{item.row_count}</td>
                        <td className="px-4 py-3 text-right">
                          <Button variant="ghost" size="sm" className="h-8 gap-1.5" onClick={() => downloadHistoryItem(item)}>
                            <Download className="h-3.5 w-3.5" />
                            Download
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>
        </section>
      </div>
    </AppLayout>
  );
}
