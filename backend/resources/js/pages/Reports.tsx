import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import {
  FileText,
  Download,
  BarChart3,
  Clock,
  Wallet,
  Users,
  FolderOpen,
  Loader2,
  AlertTriangle,
  TrendingUp,
  PenLine,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { fmtDateTime } from "@/lib/date-utils";
import { ReportVerifyDialog } from "@/components/reports/report-verify";
import { ReportDownloadDialog } from "@/components/reports/report-download-dialog";

interface ReportTemplate {
  id: string;
  name: string;
  description: string;
  icon: React.ComponentType<{ className?: string }>;
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

/** Firm staff: each report shows the cases the person may see (their CM / SCM cases, or all). */
const STAFF_ROLES = ["super_admin", "partner", "director", "manager", "hr", "finance", "associate", "paralegal", "galvanizer"];
/** Finance reports also need finance access (Settings → Financial access for a paralegal). */
const FINANCE_REPORTS = new Set(["financial-summary", "payment-collection", "zoho-summary"]);

const REPORT_TEMPLATES: ReportTemplate[] = [
  { id: "client-portfolio", name: "Client Portfolio Report", description: "All clients with case summary, GST type, billed, received, and outstanding totals", icon: Users, color: "text-blue-400", roles: STAFF_ROLES },
  { id: "matter-status", name: "Case Status Report", description: "Current status of all active cases by stage and priority", icon: FolderOpen, color: "text-green-400", roles: STAFF_ROLES },
  { id: "financial-summary", name: "Financial Summary", description: "Every invoice with billed, received, waived and balance", icon: Wallet, color: "text-gold", roles: STAFF_ROLES },
  { id: "hrms", name: "HRMS Report", description: "Employee attendance, date-adjusted leave, and latest payroll summary", icon: Users, color: "text-purple-400", roles: ["super_admin", "partner", "hr"] },
  { id: "ip-deadline", name: "IP Deadline Report", description: "Upcoming matter, statutory docket, and patent renewal deadlines", icon: Clock, color: "text-red-400", roles: STAFF_ROLES },
  { id: "productivity", name: "Productivity Report", description: "Scoped time logs, billability, approval status, and work descriptions", icon: BarChart3, color: "text-cyan-400", roles: STAFF_ROLES },
  { id: "tracker-workload", name: "Team Workload Report", description: "Cases per PCM / SCM / PR with overdue count from Project Tracker", icon: Users, color: "text-indigo-400", roles: STAFF_ROLES },
  { id: "overdue-cases", name: "Overdue Cases Report", description: "All past-due delivery dates with assigned team member details", icon: AlertTriangle, color: "text-red-500", roles: STAFF_ROLES },
  { id: "deadline-forecast", name: "Deadline Forecast", description: "Upcoming delivery deadlines in the next 60 days by default", icon: Clock, color: "text-amber-400", roles: STAFF_ROLES },
  { id: "payment-collection", name: "Payment Collection", description: "Pending follow-up with client contacts, payments, waivers and ageing per client", icon: TrendingUp, color: "text-green-400", roles: STAFF_ROLES },
  { id: "zoho-summary", name: "Zoho Books Summary", description: "Synced Zoho invoices and estimates with balances and case matches", icon: Wallet, color: "text-teal-400", roles: STAFF_ROLES },
  { id: "payroll-cost-summary", name: "Payroll Cost Summary", description: "Gross, deductions, and net totals per payroll run", icon: Wallet, color: "text-gold", roles: ["super_admin", "partner", "finance"] },
  { id: "tds-pf-esi-summary", name: "TDS / PF / ESI Summary", description: "Statutory withholding totals per payroll run", icon: TrendingUp, color: "text-purple-400", roles: ["super_admin", "partner", "finance"] },
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
  "client-portfolio": ["client_code", "company_name", "entity_type", "primary_jurisdiction", "gst_type", "gstin_verification_status", "cin_verification_status", "status", "projects_count", "active_projects_count", "total_billed", "total_received", "total_outstanding", "account_manager"],
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
  "payroll-cost-summary": ["period", "status", "employee_count", "gross_total", "deductions_total", "net_total"],
  "tds-pf-esi-summary": ["period", "status", "employee_count", "total_tds", "total_pf", "total_esi", "total_professional_tax", "total_educational_cess"],
};

interface ReportsPageProps {
  [key: string]: unknown;
  auth?: { user?: { role?: string; access?: { finance?: boolean } } };
}

function formatBadgeVariant(format: string): "default" | "secondary" | "outline" | "destructive" {
  if (format === "PDF") return "destructive";
  if (format === "EXCEL") return "default";
  return "outline";
}

function fileFor(format: string): "pdf" | "xlsx" | "csv" {
  const f = format.toUpperCase();
  return f === "PDF" ? "pdf" : f === "CSV" ? "csv" : "xlsx";
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
  const canFinance = props.auth?.user?.access?.finance ?? false;
  const availableTemplates = REPORT_TEMPLATES.filter((template) => template.roles.includes(role) && (!FINANCE_REPORTS.has(template.id) || canFinance));
  // Every report: one form, then the file downloads straight away (letterhead, signature seal).
  const [activeReport, setActiveReport] = useState<ReportTemplate | null>(null);
  const [verifyCode, setVerifyCode] = useState<string | null>(() => typeof window === "undefined" ? null : new URLSearchParams(window.location.search).get("verify"));
  const [history, setHistory] = useState<ReportHistoryItem[]>([]);
  const [historyLoading, setHistoryLoading] = useState(true);
  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  function loadHistory() {
    api.getReportHistory()
      .then(setHistory)
      .catch(() => setHistory([]))
      .finally(() => setHistoryLoading(false));
  }

  useEffect(() => {
    loadHistory();
  }, []);

  /** Recent Reports: the same file again from its saved snapshot, keeping its signature. */
  async function downloadHistoryItem(item: ReportHistoryItem) {
    setDownloadingId(item.id);
    try {
      await api.downloadReportHistory(item.id, fileFor(String(item.format)));
    } catch (e) {
      toast.error((e as Error).message);
    } finally {
      setDownloadingId(null);
    }
  }

  return (
    <AppLayout>
      <Head title="Reports" />
      <PageHeader eyebrow="Insight" title="Reports" description="Download any report on the company letterhead, signed with your signature PIN" />
      {activeReport && (
        <ReportDownloadDialog
          report={{ ...activeReport, clientFilter: CLIENT_FILTER_REPORTS.has(activeReport.id) }}
          onClose={() => setActiveReport(null)}
          onDownloaded={loadHistory}
        />
      )}
      {verifyCode && <ReportVerifyDialog code={verifyCode} onClose={() => { setVerifyCode(null); window.history.replaceState(window.history.state, "", "/reports"); }} />}

      <div className="px-8 py-6 space-y-8">
        <section>
          <div className="mb-4">
            <h2 className="text-base font-semibold font-display">Report Templates</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Choose a report, set the client and dates, and download — PDF, Excel or CSV</p>
          </div>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {availableTemplates.map((template) => {
              const Icon = template.icon;
              return (
                <Card
                  key={template.id}
                  className="group border-border cursor-pointer transition hover:-translate-y-0.5 hover:border-gold/40 hover:shadow-lg"
                  onClick={() => setActiveReport(template)}
                >
                  <CardContent className="p-3.5">
                    <div className="flex items-start gap-3">
                      <div className="shadow-3d h-9 w-9 rounded-lg bg-muted flex items-center justify-center flex-shrink-0">
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
                        className="bg-gold text-black hover:bg-gold/90"
                        onClick={(event) => {
                          event.stopPropagation();
                          setActiveReport(template);
                        }}
                      >
                        <PenLine className="mr-1.5 h-3.5 w-3.5" />
                        Download
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </section>

        <section>
          <div className="mb-4">
            <h2 className="text-base font-semibold font-display">Recent Reports</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Saved point-in-time reports — downloads again exactly as saved, with its signature</p>
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
                  <tbody className="table-rows-3d">
                    {history.map((item) => (
                      <tr key={item.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-medium">{item.name}</td>
                        <td className="px-4 py-3 text-muted-foreground">{item.generated_by}</td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtDateTime(item.generated_at)}</td>
                        <td className="px-4 py-3">
                          <Badge variant={formatBadgeVariant(item.format)} className="text-[10px]">
                            {item.format}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{item.row_count}</td>
                        <td className="px-4 py-3 text-right">
                          <Button variant="ghost" size="sm" className="h-8 gap-1.5" disabled={downloadingId === item.id} onClick={() => downloadHistoryItem(item)}>
                            {downloadingId === item.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Download className="h-3.5 w-3.5" />}
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
