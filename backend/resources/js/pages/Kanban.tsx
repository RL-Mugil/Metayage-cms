import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Calendar, AlertTriangle, Layers, RefreshCw } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import { ProjectDetailPanel } from "@/components/project-detail-panel";

// ── Stage → Column mapping (active/present-tense stages only) ──────────────
// Stages not in this map are terminal/past-tense and are excluded from Kanban.
const STAGE_COLUMN: Record<string, string> = {
  // Research / Search
  "Matter Created":                                      "Research",
  "Inventor / Technology Disclosure Requested":          "Research",
  "Inventor Disclosure Requested":                       "Research",
  "Disclosure Received":                                 "Research",
  "Inventor Disclosure Received":                        "Research",
  "Search Parameters Defined":                           "Research",
  "Prior Art Search In Progress":                        "Research",
  "Prior Art Search (Optional)":                         "Research",
  "Search Report Drafted":                               "Research",
  "Search Report Reviewed Internally":                   "Research",
  // Filing prep / national phase
  "Priority Application Documents Received":             "Research",
  "Priority Date Verified":                              "Research",
  "12-Month Deadline Confirmed":                         "Research",
  "31-Month National Phase Deadline Verified":           "Research",
  "National Phase Entry Decision Confirmed":             "Research",
  "PCT Application Documents Received":                  "Research",
  "Parent Application Identified":                       "Research",
  "Claims to Divide Identified":                         "Research",
  "Parent Patent Identified":                            "Research",
  "Improvement / Addition Defined":                      "Research",
  // Drafting — work is at the firm
  "Draft Started":                                       "Drafting",
  "Draft Completed":                                     "Drafting",
  "Specification Drafting Started":                      "Drafting",
  "Claims Drafted":                                      "Drafting",
  "Claims Drafted (adapted for Indian law)":             "Drafting",
  "Divisional Claims Drafted":                           "Drafting",
  "Addition Claims Drafted":                             "Drafting",
  "Claims Reviewed Internally":                          "Drafting",
  "International Application Drafted":                   "Drafting",
  "National Phase Entry Application Drafted":            "Drafting",
  "Specification Drafted":                               "Drafting",
  "Specification Prepared":                              "Drafting",
  "Translation Prepared (if required)":                  "Drafting",
  "Claims Adapted for Indian Law":                       "Drafting",
  "Internal Review":                                     "Drafting",
  "Corrections Incorporated":                            "Drafting",
  "Partner Review":                                      "Drafting",
  "Inventor Disclosure Reviewed":                        "Drafting",
  "Controller Objection / Invitation Noted":             "Drafting",
  // Client Review — ball is in client's court
  "Claims Shared with Client":                           "Client Review",
  "Draft Shared with Client":                            "Client Review",
  "Client Review of ISR / Written Opinion":              "Client Review",
  "Client Feedback Received":                            "Client Review",
  "Revised Draft Completed":                             "Client Review",
  "Client Approval":                                     "Client Review",
  "Client Approved":                                     "Client Review",
  "Claims Approved by Client":                           "Client Review",
  // Filing — preparing and submitting
  "Forms Prepared (Form 1, 2, 3)":                       "Filing",
  "Forms Prepared (Form 1, 2, 3, 4 — Priority)":        "Filing",
  "Forms Prepared (Form 1, 2, 3 — National Phase)":     "Filing",
  "Forms Prepared (Form 1, 2)":                          "Filing",
  "Forms Prepared (Form 1, 2 — Addition)":               "Filing",
  "Government Fees Calculated":                          "Filing",
  "Government Fees Paid":                                "Filing",
  "Government Fee Calculated":                           "Filing",
  "International Fees Calculated":                       "Filing",
  "Receiving Office Selected (RO/IN or others)":         "Filing",
  "Examination Request Decision Made":                   "Filing",
  "Form 18 Prepared":                                    "Filing",
  "Form 18A Prepared":                                   "Filing",
  "Grounds for Acceleration Prepared":                   "Filing",
  // Examination — responding to patent office
  "Examination Report Received":                         "Examination",
  "Objections Analyzed":                                 "Examination",
  "Response Strategy Formulated":                        "Examination",
  "Claims Amended / Arguments Drafted":                  "Examination",
  "Client Communicated":                                 "Examination",
  "Hearing Notice Received":                             "Examination",
  "Hearing Date Set":                                    "Examination",
  "Arguments Prepared":                                  "Examination",
  "Prior Art / Documents Compiled":                      "Examination",
  "Written Arguments / Counter-Statement Filed":         "Examination",
  "Hearing Attended":                                    "Examination",
  "Awaiting Hearing Order":                              "Examination",
  "International Search Report (ISR) Received":          "Examination",
  "Written Opinion Received":                            "Examination",
  "Chapter II Examination (Optional)":                   "Examination",
};

const COLUMNS = [
  { key: "Research",      label: "Research",       color: "border-blue-400",    bg: "bg-blue-400/10",    dot: "bg-blue-400"    },
  { key: "Drafting",      label: "Drafting",       color: "border-amber-400",   bg: "bg-amber-400/10",   dot: "bg-amber-400"   },
  { key: "Client Review", label: "Client Review",  color: "border-rose-400",    bg: "bg-rose-400/10",    dot: "bg-rose-400"    },
  { key: "Filing",        label: "Filing",         color: "border-teal-400",    bg: "bg-teal-400/10",    dot: "bg-teal-400"    },
  { key: "Examination",   label: "Examination",    color: "border-purple-400",  bg: "bg-purple-400/10",  dot: "bg-purple-400"  },
];

const URGENCY_COLOR: Record<string, string> = {
  Critical: "bg-red-500/10 text-red-400 border-red-500/20",
  High:     "bg-orange-500/10 text-orange-400 border-orange-500/20",
  Medium:   "bg-amber-500/10 text-amber-400 border-amber-500/20",
  Low:      "bg-slate-500/10 text-slate-400 border-slate-500/20",
};

function fmtDate(d: string | null | undefined) {
  if (!d) return null;
  const [y, m, day] = d.split("T")[0].split("-");
  return `${day}-${m}-${y}`;
}

function isOverdue(d: string | null | undefined) {
  if (!d) return false;
  return new Date(d) < new Date();
}

function getActiveStage(project: any): string | null {
  const stages: any[] = project.stages ?? [];
  const inProgress = stages.find((s) => s.status === "In Progress");
  if (inProgress) return inProgress.stage_name;
  const completed = stages.filter((s) => s.status === "Completed").sort((a, b) => b.sequence_order - a.sequence_order);
  return completed[0]?.stage_name ?? null;
}

// Returns null for terminal/excluded stages → project is filtered out
function getColumn(stageName: string | null): string | null {
  if (!stageName) return null;
  return STAGE_COLUMN[stageName] ?? null;
}

export default function Kanban() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isClientUser = ["client", "client_admin"].includes(role);
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [projects, setProjects] = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [filterType, setFilterType] = useState("all");
  const [detailProjectId, setDetailProjectId] = useState<number | null>(null);

  function load(isRefresh = false) {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    const rf = isAnalyst ? roleFilter : undefined;
    api.getProjects(undefined, rf)
      .then((data) => setProjects(Array.isArray(data) ? data : []))
      .catch(() => {})
      .finally(() => { setLoading(false); setRefreshing(false); });
  }

  useEffect(() => { load(); }, [roleFilter]);

  const projectTypes = Array.from(new Set(projects.map((p) => p.project_type).filter(Boolean)));

  // Only show projects whose active stage is in the active-work columns
  const active = projects.filter((p) => getColumn(getActiveStage(p)) !== null);
  const displayed = filterType === "all"
    ? active
    : active.filter((p) => p.project_type === filterType);

  const grouped = COLUMNS.reduce<Record<string, any[]>>((acc, col) => {
    acc[col.key] = displayed.filter((p) => getColumn(getActiveStage(p)) === col.key);
    return acc;
  }, {});

  const hiddenCount = projects.length - active.length;

  if (loading) return (
    <AppLayout>
      <Head title="Kanban Board" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Kanban Board" />
      <PageHeader
        eyebrow="Practice"
        title="Kanban Board"
        description={`${displayed.length} active matters${hiddenCount > 0 ? ` · ${hiddenCount} completed/filed hidden` : ""}`}
        actions={
          <div className="flex items-center gap-2">
            {!isClientUser && <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />}
            <select
              value={filterType}
              onChange={(e) => setFilterType(e.target.value)}
              className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
            >
              <option value="all">All types</option>
              {projectTypes.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
            <Button variant="outline" size="sm" onClick={() => load(true)} disabled={refreshing}>
              <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? "animate-spin" : ""}`} />
            </Button>
          </div>
        }
      />

      <div className="px-6 py-6 overflow-x-auto">
        <div className="flex gap-4 min-w-max">
          {COLUMNS.map((col) => {
            const cards = grouped[col.key] ?? [];
            return (
              <div key={col.key} className="w-64 flex flex-col gap-3">
                {/* Column header */}
                <div className={`flex items-center justify-between px-3 py-2 rounded-lg border ${col.color} ${col.bg}`}>
                  <div className="flex items-center gap-2">
                    <span className={`h-2 w-2 rounded-full ${col.dot}`} />
                    <span className="font-semibold text-sm">{col.label}</span>
                  </div>
                  <Badge variant="outline" className="text-[10px] h-5 px-1.5">{cards.length}</Badge>
                </div>

                {/* Cards */}
                <div className="flex flex-col gap-2 min-h-[200px]">
                  {cards.length === 0 && (
                    <div className="rounded-lg border border-dashed border-border p-4 text-center text-xs text-muted-foreground">
                      No active matters
                    </div>
                  )}
                  {cards.map((project) => {
                    const activeStageName = getActiveStage(project);
                    const overdue = isOverdue(project.hard_deadline);
                    const clientName = project.client?.company_name ?? project.client?.legal_name ?? "—";
                    const clickable = !isClientUser;
                    return (
                      <div
                        key={project.id}
                        className={`rounded-lg border border-border bg-card p-3 shadow-sm transition-shadow ${clickable ? "hover:shadow-md cursor-pointer" : ""} ${overdue ? "border-red-400/40" : ""}`}
                        onClick={clickable ? () => setDetailProjectId(project.id) : undefined}
                      >
                        {/* Docket + type */}
                        <div className="flex items-start justify-between gap-2 mb-1.5">
                          <span className="text-xs font-mono text-gold font-semibold truncate">
                            {project.docket_number ?? project.project_code}
                          </span>
                          {project.project_type && (
                            <Badge variant="outline" className="text-[9px] h-4 px-1 py-0 shrink-0">
                              {project.project_type}
                            </Badge>
                          )}
                        </div>

                        {/* Client name */}
                        <p className="text-sm font-medium leading-snug line-clamp-1 mb-2">{clientName}</p>

                        {/* Current stage pill */}
                        {activeStageName && (
                          <div className="flex items-center gap-1 mb-2">
                            <Layers className="h-3 w-3 text-muted-foreground shrink-0" />
                            <span className="text-[10px] text-muted-foreground line-clamp-1">{activeStageName}</span>
                          </div>
                        )}

                        {/* Urgency + deadline */}
                        <div className="flex items-center gap-1.5 flex-wrap">
                          {project.urgency && (
                            <Badge variant="outline" className={`text-[10px] h-4 px-1 py-0 ${URGENCY_COLOR[project.urgency] ?? ""}`}>
                              {project.urgency}
                            </Badge>
                          )}
                          {project.hard_deadline && (
                            <Badge
                              variant="outline"
                              className={`text-[10px] h-4 px-1 py-0 flex items-center gap-0.5 ${overdue ? "border-red-400/40 text-red-400" : "text-muted-foreground"}`}
                            >
                              {overdue && <AlertTriangle className="h-2.5 w-2.5" />}
                              <Calendar className="h-2.5 w-2.5" />
                              {fmtDate(project.hard_deadline)}
                            </Badge>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {detailProjectId !== null && (
        <ProjectDetailPanel projectId={detailProjectId} onClose={() => setDetailProjectId(null)} />
      )}
    </AppLayout>
  );
}
