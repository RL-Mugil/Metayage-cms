import { Head, router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Calendar, AlertTriangle, Layers } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";

// ── Stage → Column mapping (16 real stages → 4 columns) ─────────────────────
const STAGE_COLUMN: Record<string, string> = {
  "Invention Disclosure":      "Intake & Research",
  "Patent Search":             "Intake & Research",
  "Search Report":             "Intake & Research",
  "Provisional or Complete Application": "Drafting & Filing",
  "Provisional Filing":        "Drafting & Filing",
  "Patent Drafting":           "Drafting & Filing",
  "Applicant/Inventor Review": "Drafting & Filing",
  "Filing with Patent Office": "Drafting & Filing",
  "First Examination Report":          "Examination",
  "FER Response Preparation":          "Examination",
  "FER Response Filing":               "Examination",
  "Hearing with Examiner":             "Examination",
  "Hearing Response Preparation":      "Examination",
  "Hearing Response Filing":           "Examination",
  "Granted":  "Completed",
  "Renewal":  "Completed",
};

const COLUMNS = [
  { key: "Intake & Research", label: "Intake & Research", color: "border-blue-400",  bg: "bg-blue-400/10",  dot: "bg-blue-400"  },
  { key: "Drafting & Filing", label: "Drafting & Filing", color: "border-amber-400", bg: "bg-amber-400/10", dot: "bg-amber-400" },
  { key: "Examination",       label: "Examination",       color: "border-purple-400",bg: "bg-purple-400/10",dot: "bg-purple-400"},
  { key: "Completed",         label: "Completed",         color: "border-green-400", bg: "bg-green-400/10", dot: "bg-green-400" },
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
  // Fallback: highest completed stage
  const completed = stages.filter((s) => s.status === "Completed").sort((a, b) => b.sequence_order - a.sequence_order);
  return completed[0]?.stage_name ?? null;
}

function getColumn(stageName: string | null): string {
  if (!stageName) return "Intake & Research";
  return STAGE_COLUMN[stageName] ?? "Intake & Research";
}

export default function Kanban() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [projects, setProjects] = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [filterType, setFilterType] = useState("all");

  useEffect(() => {
    const rf = isAnalyst ? roleFilter : undefined;
    api.getProjects(undefined, rf)
      .then((data) => setProjects(Array.isArray(data) ? data : []))
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [roleFilter]);

  const projectTypes = Array.from(new Set(projects.map((p) => p.project_type).filter(Boolean)));

  const displayed = filterType === "all"
    ? projects
    : projects.filter((p) => p.project_type === filterType);

  const grouped = COLUMNS.reduce<Record<string, any[]>>((acc, col) => {
    acc[col.key] = displayed.filter((p) => getColumn(getActiveStage(p)) === col.key);
    return acc;
  }, {});

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
        description={`${displayed.length} matters auto-grouped by workflow stage`}
        actions={
          <div className="flex items-center gap-2">
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
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
          </div>
        }
      />

      <div className="px-6 py-6 overflow-x-auto">
        <div className="flex gap-4 min-w-max">
          {COLUMNS.map((col) => {
            const cards = grouped[col.key] ?? [];
            return (
              <div key={col.key} className="w-72 flex flex-col gap-3">
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
                      No matters here
                    </div>
                  )}
                  {cards.map((project) => {
                    const activeStageName = getActiveStage(project);
                    const overdue = isOverdue(project.hard_deadline) && getColumn(activeStageName) !== "Completed";
                    const clientName = project.client?.company_name ?? project.client?.legal_name ?? "—";
                    return (
                      <div
                        key={project.id}
                        className={`rounded-lg border border-border bg-card p-3 shadow-sm hover:shadow-md transition-shadow cursor-pointer ${overdue ? "border-red-400/40" : ""}`}
                        onClick={() => router.visit(`/projects?open=${project.id}`)}
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
    </AppLayout>
  );
}
