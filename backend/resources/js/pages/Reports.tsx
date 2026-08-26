import { Head, usePage } from "@inertiajs/react";
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
  Check,
  ChevronsUpDown,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { api, downloadCSV } from "@/lib/api-client";
import { cn } from "@/lib/utils";
import type { Client, ReportResponse } from "@/types";

interface ReportTemplate {
  id: string;
  name: string;
  description: string;
  icon: React.ElementType;
  color: string;
  roles: string[];
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
  { id: "client-portfolio", name: "Client Portfolio Report", description: "All clients with case summary, GST type, billed, received, and outstanding totals", icon: Users, color: "text-blue-400", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "matter-status", name: "Case Status Report", description: "Current status of all active cases by stage and priority", icon: FolderOpen, color: "text-green-400", roles: ["super_admin", "partner", "manager", "finance", "galvanizer"] },
  { id: "financial-summary", name: "Financial Summary", description: "Invoices, amounts received, and outstanding balances", icon: Wallet, color: "text-gold", roles: ["super_admin", "partner", "manager", "finance", "galvanizer"] },
  { id: "hrms", name: "HRMS Report", description: "Employee attendance, date-adjusted leave, and latest payroll summary", icon: Users, color: "text-purple-400", roles: ["super_admin", "partner", "hr"] },
  { id: "ip-deadline", name: "IP Deadline Report", description: "Upcoming matter, statutory docket, and patent renewal deadlines", icon: Clock, color: "text-red-400", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "productivity", name: "Productivity Report", description: "Scoped time logs, billability, approval status, and work descriptions", icon: BarChart3, color: "text-cyan-400", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "tracker-workload", name: "Team Workload Report", description: "Cases per PCM / SCM / PR with overdue count from Project Tracker", icon: Users, color: "text-indigo-400", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "overdue-cases", name: "Overdue Cases Report", description: "All past-due delivery dates with assigned team member details", icon: AlertTriangle, color: "text-red-500", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "deadline-forecast", name: "Deadline Forecast", description: "Upcoming delivery deadlines in the next 60 days by default", icon: Clock, color: "text-amber-400", roles: ["super_admin", "partner", "manager", "galvanizer"] },
  { id: "payment-collection", name: "Payment Collection", description: "Paid / Partial / Pending breakdown per client from tracker", icon: TrendingUp, color: "text-green-400", roles: ["super_admin", "partner", "manager", "finance", "galvanizer"] },
  { id: "zoho-summary", name: "Zoho Books Summary", description: "Synced Zoho invoices and estimates with balances and case matches", icon: Wallet, color: "text-teal-400", roles: ["super_admin", "partner", "manager", "finance", "galvanizer"] },
];

const CLIENT_FILTER_REPORTS = new Set([
  "client-portfolio",
  "matter-status",
  "financial-summary",
  "ip-deadline",
  "overdue-cases",
  "deadline-forecast",
  "payment-collection",
  "zoho-summary",
]);

const SAMPLE_COLUMNS: Record<string, string[]> = {
  "client-portfolio": ["client_code", "company_name", "entity_type", "primary_jurisdiction", "gst_type", "status", "projects_count", "active_projects_count", "total_billed", "total_received", "total_outstanding", "account_manager"],
  "matter-status": ["project_code", "client_code", "application_number", "project_name", "client", "project_type", "status", "urgency", "current_stage", "hard_deadline", "manager"],
  "financial-summary": ["invoice_code", "client_code", "project_code", "application_number", "client", "issue_date", "due_date", "total_amount", "amount_received", "balance_due", "status", "currency"],
  hrms: ["employee_code", "full_name", "department", "designation", "employment_status", "present_days", "half_days", "absent_days", "approved_leave_days", "latest_payroll_period", "latest_payroll_status", "latest_net_pay", "lop_days"],
  "ip-deadline": ["source", "project_code", "client_code", "application_number", "project_name", "client", "project_type", "deadline", "days_left", "urgency", "status"],
  productivity: ["entry_date", "employee", "project_code", "client_code", "application_number", "title", "task_title", "hours_logged", "billable", "status", "approved_by", "description"],
  "tracker-workload": ["Team Member", "Total Cases", "PCM Cases", "SCM Cases", "PR Cases", "Overdue"],
  "overdue-cases": ["Docket #", "Client Code", "Case Code", "Title", "Application Number", "Client", "Record Type", "PCM", "SCM", "PR", "Due Date", "Days Overdue", "Status", "Payment"],
  "deadline-forecast": ["Docket #", "Client Code", "Case Code", "Title", "Application Number", "Client", "Record Type", "PCM", "Due Date", "Days Left", "Status", "% Complete", "Payment"],
  "payment-collection": ["Client", "Total Cases", "Paid", "Partial", "Pending", "Not Set"],
  "zoho-summary": ["number", "type", "client_code", "project_code", "application_number", "client", "docket_number", "date", "status", "total", "balance", "currency", "synced_at"],
};

const PROTECTED_COLUMNS = new Set([
  "client code", "client_code", "case code", "case_code", "project code", "project_code",
  "docket #", "docket_number", "title", "project name", "project_name",
  "application number", "application_number", "invoice code", "invoice_code", "employee code", "employee_code",
]);

interface ReportsPageProps {
  [key: string]: unknown;
  auth?: { user?: { role?: string } };
}

interface GeneratorState {
  reportId: string;
  fromDate: string;
  toDate: string;
  clientCode: string;
  format: "PDF" | "Excel" | "CSV";
}

function exportPDF(reportName: string, rows: Record<string, unknown>[]) {
  if (!rows.length) return;
  const headers = Object.keys(rows[0]);
  const escapeHtml = (value: unknown) => String(value ?? "")
    .replaceAll("&", "&amp;").replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
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
<h1>${escapeHtml(reportName)}</h1>
<div class="meta">Generated: ${new Date().toLocaleString("en-IN")} | ${rows.length} records</div>
<table>
<thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join("")}</tr></thead>
<tbody>${rows.map((row) => `<tr>${headers.map((header) => `<td>${escapeHtml(row[header])}</td>`).join("")}</tr>`).join("")}</tbody>
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

function exportExcel(reportName: string, rows: Record<string, unknown>[]) {
  if (!rows.length) return;
  const headers = Object.keys(rows[0]);
  const escapeXml = (value: unknown) => String(value ?? "")
    .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");
  const table = `<table><thead><tr>${headers.map((h) => `<th>${escapeXml(h)}</th>`).join("")}</tr></thead>`
    + `<tbody>${rows.map((row) => `<tr>${headers.map((h) => `<td>${escapeXml(row[h])}</td>`).join("")}</tr>`).join("")}</tbody></table>`;
  const blob = new Blob([`<html><head><meta charset="utf-8"></head><body>${table}</body></html>`], {
    type: "application/vnd.ms-excel;charset=utf-8",
  });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${reportName.toLowerCase().replace(/[^a-z0-9]+/g, "-")}.xls`;
  link.click();
  URL.revokeObjectURL(url);
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : "Failed to generate report.";
}

function formatBadgeVariant(format: string): "default" | "secondary" | "outline" | "destructive" {
  if (format === "PDF") return "destructive";
  if (format === "EXCEL") return "default";
  return "outline";
}

function isProtectedColumn(column: string): boolean {
  return PROTECTED_COLUMNS.has(column.trim().toLowerCase());
}

function downloadSample(template: ReportTemplate) {
  const columns = [...(SAMPLE_COLUMNS[template.id] ?? [])];
  if (!columns.some((column) => column.toLowerCase().replaceAll("_", " ") === "application number")) {
    columns.push("Application Number");
  }
  columns.push("Notes");
  const blankRow = Object.fromEntries(columns.map((column) => [column, ""]));
  downloadCSV(`${template.id}-sample-template.csv`, [blankRow]);
}

export default function Reports() {
  const { props } = usePage<ReportsPageProps>();
  const role = props.auth?.user?.role ?? "";
  const availableTemplates = REPORT_TEMPLATES.filter((template) => template.roles.includes(role));
  const [activeReport, setActiveReport] = useState<ReportTemplate | null>(null);
  const [generator, setGenerator] = useState<GeneratorState>({
    reportId: "",
    fromDate: "",
    toDate: "",
    clientCode: "",
    format: "PDF",
  });
  const [generating, setGenerating] = useState(false);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  const [history, setHistory] = useState<ReportHistoryItem[]>([]);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [preview, setPreview] = useState<ReportResponse | null>(null);
  const [editableRows, setEditableRows] = useState<Record<string, unknown>[]>([]);
  const [previewName, setPreviewName] = useState("");
  const [clients, setClients] = useState<Client[]>([]);
  const [clientsLoading, setClientsLoading] = useState(false);
  const [clientPickerOpen, setClientPickerOpen] = useState(false);

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

  useEffect(() => {
    if (!activeReport || !CLIENT_FILTER_REPORTS.has(activeReport.id) || clients.length > 0) return;

    setClientsLoading(true);
    api.getClients(new URLSearchParams({ per_page: "2000" }))
      .then((response) => setClients(response.data))
      .catch(() => setClients([]))
      .finally(() => setClientsLoading(false));
  }, [activeReport, clients.length]);

  function openGenerator(template: ReportTemplate) {
    setActiveReport(template);
    setGenerator({
      reportId: template.id,
      fromDate: "",
      toDate: "",
      clientCode: "",
      format: "PDF",
    });
    setSuccessMsg(null);
    setPreview(null);
    setClientPickerOpen(false);
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
        clientCode: generator.clientCode,
      });
      setPreview(result);
      setEditableRows(result.rows.map((row) => ({ ...row })));
      setPreviewName(activeReport.name);
      setActiveReport(null);
      setSuccessMsg(`${activeReport.name} generated. Review and edit the preview before downloading.`);

      loadHistory();
    } catch (e: unknown) {
      setSuccessMsg(null);
      alert(errorMessage(e));
    } finally {
      setGenerating(false);
    }
  }

  function updatePreviewCell(rowIndex: number, column: string, value: string) {
    if (isProtectedColumn(column)) return;
    setEditableRows((rows) => rows.map((row, index) => index === rowIndex ? { ...row, [column]: value } : row));
  }

  function downloadEditedPreview(format: "PDF" | "Excel" | "CSV") {
    if (!preview || !previewName) return;
    if (format === "PDF") {
      exportPDF(previewName, editableRows);
    } else if (format === "Excel") {
      exportExcel(previewName, editableRows);
    } else {
      downloadCSV(`${preview.type}-${new Date().toISOString().slice(0, 10)}.csv`, editableRows);
    }
  }

  async function downloadHistoryItem(item: ReportHistoryItem) {
    const saved = await api.getReportHistoryItem(item.id);
    if (saved.format === "PDF") {
      exportPDF(saved.name, saved.rows);
      return;
    }

    if (saved.format === "EXCEL") {
      exportExcel(saved.name, saved.rows);
      return;
    }
    downloadCSV(`${saved.type}-${item.generated_at.slice(0, 10)}.csv`, saved.rows);
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
            {availableTemplates.map((template) => {
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
                    <div className="mt-4 flex justify-end gap-2">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={(event) => {
                          event.stopPropagation();
                          downloadSample(template);
                        }}
                      >
                        <Download className="mr-1.5 h-3.5 w-3.5" />
                        Sample
                      </Button>
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

        <Dialog open={activeReport !== null} onOpenChange={(open) => { if (!open && !generating) setActiveReport(null); }}>
          {activeReport && (
            <DialogContent className="max-w-xl">
              <DialogHeader>
                <div className="flex items-center gap-2 pr-8">
                  <activeReport.icon className={`h-5 w-5 ${activeReport.color}`} />
                  <DialogTitle className="font-display">Generate {activeReport.name}</DialogTitle>
                </div>
                <DialogDescription>{activeReport.description}</DialogDescription>
              </DialogHeader>

              <div className="grid gap-4 py-2 sm:grid-cols-2">
                {CLIENT_FILTER_REPORTS.has(activeReport.id) && (
                  <div className="space-y-1.5 sm:col-span-2">
                    <label className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                      Client <span className="normal-case font-normal">(optional)</span>
                    </label>
                    <Popover open={clientPickerOpen} onOpenChange={setClientPickerOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          role="combobox"
                          aria-expanded={clientPickerOpen}
                          className="w-full justify-between font-normal"
                          disabled={clientsLoading}
                        >
                          <span className="truncate">
                            {clientsLoading
                              ? "Loading clients..."
                              : generator.clientCode
                                ? (() => {
                                    const selected = clients.find((client) => client.client_code === generator.clientCode);
                                    return selected ? `${selected.client_code} — ${selected.company_name ?? selected.legal_name ?? "Unnamed client"}` : generator.clientCode;
                                  })()
                                : "All clients"}
                          </span>
                          {clientsLoading ? <Loader2 className="ml-2 h-4 w-4 animate-spin" /> : <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />}
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] p-0">
                        <Command>
                          <CommandInput autoFocus placeholder="Search by company name or client code..." />
                          <CommandList>
                            <CommandEmpty>No matching client found.</CommandEmpty>
                            <CommandGroup>
                              <CommandItem
                                value="all clients"
                                onSelect={() => {
                                  setGenerator((state) => ({ ...state, clientCode: "" }));
                                  setClientPickerOpen(false);
                                }}
                              >
                                <Check className={cn("h-4 w-4", generator.clientCode === "" ? "opacity-100" : "opacity-0")} />
                                <span>All clients</span>
                              </CommandItem>
                              {clients.map((client) => (
                                <CommandItem
                                  key={client.id}
                                  value={`${client.client_code} ${client.company_name ?? ""} ${client.legal_name ?? ""}`}
                                  onSelect={() => {
                                    setGenerator((state) => ({ ...state, clientCode: client.client_code }));
                                    setClientPickerOpen(false);
                                  }}
                                >
                                  <Check className={cn("h-4 w-4", generator.clientCode === client.client_code ? "opacity-100" : "opacity-0")} />
                                  <span className="font-mono text-xs text-gold">{client.client_code}</span>
                                  <span className="truncate">{client.company_name ?? client.legal_name ?? "Unnamed client"}</span>
                                </CommandItem>
                              ))}
                            </CommandGroup>
                          </CommandList>
                        </Command>
                      </PopoverContent>
                    </Popover>
                    <p className="text-xs text-muted-foreground">Search by company name or code, then select the exact client. Choose All clients for the complete report.</p>
                  </div>
                )}
                <div className="space-y-1.5">
                  <label className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    <Calendar className="h-3.5 w-3.5" /> From Date
                  </label>
                  <Input type="date" value={generator.fromDate} onChange={(event) => setGenerator((state) => ({ ...state, fromDate: event.target.value }))} />
                </div>
                <div className="space-y-1.5">
                  <label className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    <Calendar className="h-3.5 w-3.5" /> To Date
                  </label>
                  <Input type="date" value={generator.toDate} onChange={(event) => setGenerator((state) => ({ ...state, toDate: event.target.value }))} />
                </div>
                <div className="space-y-1.5 sm:col-span-2">
                  <label className="text-xs font-medium uppercase tracking-wider text-muted-foreground">Export Format</label>
                  <div className="grid grid-cols-3 gap-2">
                    {(["PDF", "Excel", "CSV"] as const).map((format) => (
                      <button
                        key={format}
                        type="button"
                        onClick={() => setGenerator((state) => ({ ...state, format }))}
                        className={`rounded-md border px-3 py-2 text-xs font-medium transition-colors ${generator.format === format ? "border-gold bg-gold/10 text-gold" : "border-border text-muted-foreground hover:border-gold/30"}`}
                      >
                        {format}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              <DialogFooter className="gap-2 sm:space-x-0">
                <Button variant="outline" onClick={() => setActiveReport(null)} disabled={generating}>Cancel</Button>
                <Button onClick={handleGenerate} disabled={generating}>
                  {generating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <FileText className="mr-2 h-4 w-4" />}
                  {generating ? "Generating..." : "Generate Report"}
                </Button>
              </DialogFooter>
            </DialogContent>
          )}
        </Dialog>

        {preview && (
          <section>
            <div className="mb-4 flex items-end justify-between gap-4">
              <div>
                <h2 className="text-base font-semibold font-display">Generated Report Preview</h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {preview.total.toLocaleString("en-IN")} records generated at {preview.generated_at}
                </p>
              </div>
              <div className="flex flex-wrap justify-end gap-2">
                {(["PDF", "Excel", "CSV"] as const).map((format) => (
                  <Button key={format} size="sm" variant={generator.format === format ? "default" : "outline"} onClick={() => downloadEditedPreview(format)} disabled={editableRows.length === 0}>
                    <Download className="mr-1.5 h-3.5 w-3.5" />
                    {format}
                  </Button>
                ))}
              </div>
            </div>
            <p className="mb-3 text-xs text-muted-foreground">
              Edit any unlocked cell before downloading. Client code, case code, docket number, title, invoice/employee code, and application number are protected.
            </p>
            <Card className="border-border overflow-hidden">
              <CardContent className="p-0 overflow-x-auto">
                {editableRows.length === 0 ? (
                  <div className="px-6 py-10 text-sm text-muted-foreground">No records match the selected date range.</div>
                ) : (
                  <table className="w-full min-w-max text-sm">
                    <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                      <tr>{Object.keys(editableRows[0]).map((header) => <th key={header} className="px-4 py-3 text-left">{header.replaceAll("_", " ")}</th>)}</tr>
                    </thead>
                    <tbody>
                      {editableRows.map((row, index) => (
                        <tr key={index} className="border-t border-border hover:bg-muted/30">
                          {Object.keys(editableRows[0]).map((header) => (
                            <td key={header} className="min-w-40 px-2 py-2 text-muted-foreground">
                              <Input
                                value={String(row[header] ?? "")}
                                readOnly={isProtectedColumn(header)}
                                aria-label={`${header} row ${index + 1}`}
                                onChange={(event) => updatePreviewCell(index, header, event.target.value)}
                                className={cn("h-8 min-w-36", isProtectedColumn(header) && "cursor-not-allowed bg-muted/50 font-mono text-xs")}
                              />
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
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
