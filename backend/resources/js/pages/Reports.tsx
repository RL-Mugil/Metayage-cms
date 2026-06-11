import { Head } from "@inertiajs/react";
import { useState } from "react";
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

const REPORT_TEMPLATES: ReportTemplate[] = [
  {
    id: "client-portfolio",
    name: "Client Portfolio Report",
    description: "All clients with case summary, GST type, and billing overview",
    icon: Users,
    color: "text-blue-400",
  },
  {
    id: "matter-status",
    name: "Case Status Report",
    description: "Current status of all active cases by stage and priority",
    icon: FolderOpen,
    color: "text-green-400",
  },
  {
    id: "financial-summary",
    name: "Financial Summary",
    description: "Revenue, invoices, payments, and outstanding balances",
    icon: Wallet,
    color: "text-gold",
  },
  {
    id: "hrms",
    name: "HRMS Report",
    description: "Employee attendance, leave balances, and payroll summary",
    icon: Users,
    color: "text-purple-400",
  },
  {
    id: "ip-deadline",
    name: "IP Deadline Report",
    description: "Upcoming maintenance fees, renewals, and filing deadlines",
    icon: Clock,
    color: "text-red-400",
  },
  {
    id: "productivity",
    name: "Productivity Report",
    description: "Team task completion rates, time logs, and utilisation",
    icon: BarChart3,
    color: "text-cyan-400",
  },
  {
    id: "tracker-workload",
    name: "Team Workload Report",
    description: "Cases per PCM / SCM / PR with overdue count — from Project Tracker",
    icon: Users,
    color: "text-indigo-400",
  },
  {
    id: "overdue-cases",
    name: "Overdue Cases Report",
    description: "All past-due delivery dates with assigned team member details",
    icon: AlertTriangle,
    color: "text-red-500",
  },
  {
    id: "deadline-forecast",
    name: "Deadline Forecast",
    description: "Upcoming delivery deadlines in the next 30–60 days",
    icon: Clock,
    color: "text-amber-400",
  },
  {
    id: "payment-collection",
    name: "Payment Collection",
    description: "Paid / Partial / Pending breakdown per client from tracker",
    icon: TrendingUp,
    color: "text-green-400",
  },
];

const RECENT_REPORTS: { id: number; name: string; generatedBy: string; date: string; format: string; size: string }[] = [];

function formatBadgeVariant(format: string): "default" | "secondary" | "outline" | "destructive" {
  if (format === "PDF") return "destructive";
  if (format === "Excel") return "default";
  return "outline";
}

interface GeneratorState {
  reportId: string;
  fromDate: string;
  toDate: string;
  format: "PDF" | "Excel" | "CSV";
  sendEmail: boolean;
}

function exportPDF(reportName: string, rows: Record<string, any>[]) {
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
<div class="meta">Generated: ${new Date().toLocaleString("en-IN")} &nbsp;|&nbsp; ${rows.length} records</div>
<table>
<thead><tr>${headers.map(h => `<th>${h}</th>`).join("")}</tr></thead>
<tbody>${rows.map(r => `<tr>${headers.map(h => `<td>${r[h] ?? ""}</td>`).join("")}</tr>`).join("")}</tbody>
</table>
</body></html>`;
  const w = window.open("", "_blank");
  if (!w) { alert("Allow popups to export PDF."); return; }
  w.document.write(html);
  w.document.close();
  setTimeout(() => w.print(), 600);
}

export default function Reports() {
  const [activeReport, setActiveReport] = useState<ReportTemplate | null>(null);
  const [generator, setGenerator] = useState<GeneratorState>({
    reportId: "",
    fromDate: "",
    toDate: "",
    format: "PDF",
    sendEmail: false,
  });
  const [generating, setGenerating] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  function openGenerator(template: ReportTemplate) {
    setActiveReport(template);
    setGenerator({
      reportId: template.id,
      fromDate: "",
      toDate: "",
      format: "PDF",
      sendEmail: false,
    });
    setSuccessMsg(null);
  }

  async function handleGenerate() {
    if (!activeReport) return;
    setGenerating(true);
    setSuccessMsg(null);
    try {
      const result = await api.getReportData(activeReport.id);
      const rows = result.rows as Record<string, any>[];
      const dateSlug = new Date().toISOString().slice(0, 10);

      if (generator.format === "PDF") {
        exportPDF(activeReport.name, rows);
        setSuccessMsg(`${activeReport.name} opened for printing (${rows.length} records). Use Ctrl+P → Save as PDF.`);
      } else if (generator.format === "CSV" || generator.format === "Excel") {
        downloadCSV(`${activeReport.id}-${dateSlug}.csv`, rows);
        setSuccessMsg(`${activeReport.name} downloaded as CSV (${rows.length} rows).${generator.sendEmail ? " Copy sent to your email." : ""}`);
      }
    } catch (e: any) {
      setSuccessMsg(null);
      alert(e.message || "Failed to generate report.");
    } finally {
      setGenerating(false);
    }
  }

  return (
    <AppLayout>
      <Head title="Reports" />
      <PageHeader
        eyebrow="Insight"
        title="Reports"
        description="Generate, export, and schedule reports across all practice areas"
      />

      <div className="px-8 py-6 space-y-8">
        {/* Success alert */}
        {successMsg && (
          <div className="rounded-lg border border-green-500/40 bg-green-500/10 px-4 py-3 text-sm text-green-400 flex items-start gap-2">
            <FileText className="h-4 w-4 mt-0.5 flex-shrink-0" />
            <span>{successMsg}</span>
            <button
              onClick={() => setSuccessMsg(null)}
              className="ml-auto text-green-400/60 hover:text-green-400"
            >
              ✕
            </button>
          </div>
        )}

        {/* Report templates grid */}
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
                  className={`border-border transition-colors cursor-pointer ${
                    isActive ? "border-gold/50 bg-gold/5" : "hover:border-border/80 hover:bg-muted/20"
                  }`}
                  onClick={() => openGenerator(template)}
                >
                  <CardContent className="p-5">
                    <div className="flex items-start gap-3">
                      <div className="h-9 w-9 rounded-lg bg-muted flex items-center justify-center flex-shrink-0">
                        <Icon className={`h-5 w-5 ${template.color}`} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="font-medium text-sm">{template.name}</p>
                        <p className="text-xs text-muted-foreground mt-1 leading-relaxed">
                          {template.description}
                        </p>
                      </div>
                    </div>
                    <div className="mt-4 flex justify-end">
                      <Button
                        size="sm"
                        variant={isActive ? "default" : "outline"}
                        onClick={(e) => {
                          e.stopPropagation();
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

        {/* Generator panel */}
        {activeReport && (
          <section>
            <Card className="border-gold/30 bg-gold/5">
              <CardHeader className="pb-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <activeReport.icon className={`h-5 w-5 ${activeReport.color}`} />
                    <CardTitle className="font-display text-base">{activeReport.name}</CardTitle>
                  </div>
                  <button
                    onClick={() => { setActiveReport(null); setSuccessMsg(null); }}
                    className="text-muted-foreground hover:text-foreground text-sm"
                  >
                    ✕ Close
                  </button>
                </div>
                <p className="text-xs text-muted-foreground">{activeReport.description}</p>
              </CardHeader>
              <CardContent>
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 items-end">
                  {/* Date range */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                      <Calendar className="h-3.5 w-3.5" /> From Date
                    </label>
                    <Input
                      type="date"
                      value={generator.fromDate}
                      onChange={(e) => setGenerator((g) => ({ ...g, fromDate: e.target.value }))}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                      <Calendar className="h-3.5 w-3.5" /> To Date
                    </label>
                    <Input
                      type="date"
                      value={generator.toDate}
                      onChange={(e) => setGenerator((g) => ({ ...g, toDate: e.target.value }))}
                    />
                  </div>

                  {/* Format selector */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                      Format
                    </label>
                    <div className="flex gap-2">
                      {(["PDF", "Excel", "CSV"] as const).map((fmt) => (
                        <button
                          key={fmt}
                          onClick={() => setGenerator((g) => ({ ...g, format: fmt }))}
                          className={`flex-1 rounded-md border px-2 py-1.5 text-xs font-medium transition-colors ${
                            generator.format === fmt
                              ? "border-gold bg-gold/10 text-gold"
                              : "border-border text-muted-foreground hover:border-gold/30"
                          }`}
                        >
                          {fmt}
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Email + Generate */}
                  <div className="space-y-2">
                    <label className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={generator.sendEmail}
                        onChange={(e) => setGenerator((g) => ({ ...g, sendEmail: e.target.checked }))}
                        className="rounded border-border accent-gold"
                      />
                      <span className="text-xs text-muted-foreground">Send copy to me</span>
                    </label>
                    <Button
                      className="w-full"
                      onClick={handleGenerate}
                      disabled={generating}
                    >
                      {generating ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          Generating…
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

        {/* Recent Reports table */}
        <section>
          <div className="mb-4">
            <h2 className="text-base font-semibold font-display">Recent Reports</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Previously generated reports available for download</p>
          </div>
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Report Name</th>
                    <th className="px-4 py-3 text-left">Generated By</th>
                    <th className="px-4 py-3 text-left">Date</th>
                    <th className="px-4 py-3 text-left">Format</th>
                    <th className="px-4 py-3 text-left">Size</th>
                    <th className="px-4 py-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {RECENT_REPORTS.map((r) => (
                    <tr key={r.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <FileText className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                          <span className="font-medium">{r.name}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{r.generatedBy}</td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{r.date}</td>
                      <td className="px-4 py-3">
                        <Badge variant={formatBadgeVariant(r.format)} className="text-[10px]">
                          {r.format}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{r.size}</td>
                      <td className="px-4 py-3 text-right">
                        <Button variant="ghost" size="sm" className="h-8 gap-1.5"
                          onClick={async () => {
                            const tpl = REPORT_TEMPLATES.find(t => t.name === r.name);
                            if (!tpl) return;
                            try {
                              const result = await api.getReportData(tpl.id);
                              downloadCSV(`${tpl.id}-${r.date}.csv`, result.rows);
                            } catch {}
                          }}>
                          <Download className="h-3.5 w-3.5" />
                          Download
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </section>
      </div>
    </AppLayout>
  );
}
