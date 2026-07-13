import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, X, FileText, ExternalLink, RefreshCw, MapPin } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";

// ── Lifecycle structure (mirrors the seeded project_stages names) ────────────
const PHASES: {
  n: number;
  topCard?: { stages: { name: string; label: string }[] };
  card: { stages: { name: string; label: string; sub?: string }[] };
  abandonedBelow?: boolean;
  abandonedRight?: boolean;
}[] = [
  {
    n: 1,
    card: {
      stages: [
        { name: "Invention Disclosure", label: "Invention disclosure" },
        { name: "Patent Search", label: "Patent Search", sub: "Search if the invention is patentable" },
        { name: "Search Report", label: "Search Report" },
      ],
    },
    abandonedBelow: true,
  },
  {
    n: 2,
    topCard: {
      stages: [
        { name: "Provisional or Complete Application", label: "Provisional or Complete Application" },
        { name: "Provisional Filing", label: "Filing Provisional or Complete Application" },
      ],
    },
    abandonedRight: true,
    card: {
      stages: [
        { name: "Patent Drafting", label: "Patent Drafting" },
        { name: "Applicant/Inventor Review", label: "Applicant/Inventor review" },
        { name: "Filing with Patent Office", label: "Filing with patent office" },
      ],
    },
  },
  {
    n: 3,
    card: {
      stages: [
        { name: "First Examination Report", label: "First examination report" },
        { name: "FER Response Preparation", label: "FER response Preparation" },
        { name: "FER Response Filing", label: "Filing with patent office" },
      ],
    },
  },
  {
    n: 4,
    card: {
      stages: [
        { name: "Hearing with Examiner", label: "Hearing with Examiner" },
        { name: "Hearing Response Preparation", label: "Hearing response Preparation" },
        { name: "Hearing Response Filing", label: "Filing with patent office" },
      ],
    },
    abandonedBelow: true,
  },
];

const TERMINAL_STAGES = [
  { name: "Granted", label: "Granted" },
  { name: "Renewal", label: "Renewal" },
];

function StageBox({
  label, sub, count, onClick,
}: {
  label: string; sub?: string; count: number; onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      className="w-full text-left rounded-lg bg-sky-100 dark:bg-sky-500/15 border border-sky-200 dark:border-sky-500/30 px-3 py-2.5 hover:bg-sky-200 dark:hover:bg-sky-500/25 hover:border-sky-400 transition-colors group"
    >
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <div className="text-xs font-medium text-slate-700 dark:text-sky-100 leading-tight">{label}</div>
          {sub && <div className="text-[10px] text-slate-500 dark:text-sky-200/60 mt-0.5 leading-tight">{sub}</div>}
        </div>
        {count > 0 && (
          <span className="flex-shrink-0 min-w-[20px] h-5 px-1 rounded-full bg-sky-500 text-white text-[10px] font-semibold flex items-center justify-center">
            {count}
          </span>
        )}
      </div>
    </button>
  );
}

function AbandonedCircle() {
  return (
    <div className="flex h-14 w-14 items-center justify-center rounded-full border-2 border-slate-300 dark:border-border bg-background text-[9px] font-medium text-muted-foreground text-center leading-tight">
      Abandoned
    </div>
  );
}

export default function PatentLifecycle() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);

  // Stage popup
  const [openStage, setOpenStage] = useState<{ name: string; label: string } | null>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [projLoading, setProjLoading] = useState(false);

  const load = (rf?: string) => {
    setLoading(true);
    const effectiveRf = isAnalyst ? (rf ?? roleFilter) : undefined;
    api.getLifecycleStats(effectiveRf).then(setCounts).catch(() => {}).finally(() => setLoading(false));
  };
  useEffect(() => { load(roleFilter); }, [roleFilter]);
  useEffect(() => { load(); }, []);

  async function openStagePopup(stage: { name: string; label: string }) {
    setOpenStage(stage);
    setProjLoading(true);
    setProjects([]);
    try {
      const params = new URLSearchParams({ lifecycle_stage: stage.name, per_page: "200" });
      if (isAnalyst && roleFilter !== 'all') params.set('role_filter', roleFilter);
      const res = await api.getProjectsPaged(params) as any;
      setProjects(Array.isArray(res) ? res : res?.data ?? []);
    } catch { /* show empty */ }
    finally { setProjLoading(false); }
  }

  const c = (name: string) => counts[name] ?? 0;

  return (
    <AppLayout>
      <Head title="Patent Process Lifecycle" />
      <PageHeader
        eyebrow="Practice"
        title="Patent Process Lifecycle"
        description="End-to-end patent workflow. Click any stage to see the cases currently in it."
        actions={
          <div className="flex items-center gap-2">
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
            <button onClick={() => load(roleFilter)}
              className="flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors">
              <RefreshCw className="h-3.5 w-3.5" /> Refresh
            </button>
          </div>
        }
      />

      {/* Full-screen stage popup */}
      {openStage && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm p-4 md:p-8">
          <div className="bg-background border border-border rounded-2xl shadow-2xl w-full h-full flex flex-col overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-xl font-semibold">{openStage.label}</h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {projLoading ? "Loading…" : `${projects.length} case${projects.length !== 1 ? "s" : ""} currently in this stage`}
                </p>
              </div>
              <button onClick={() => setOpenStage(null)}
                className="p-2 rounded-md hover:bg-muted transition-colors">
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            <div className="flex-1 overflow-auto">
              {projLoading ? (
                <div className="flex items-center justify-center h-full">
                  <Loader2 className="h-8 w-8 animate-spin text-gold" />
                </div>
              ) : projects.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-full gap-2 text-muted-foreground">
                  <FileText className="h-10 w-10 opacity-30" />
                  <p className="text-sm">No cases in "{openStage.label}" right now.</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-muted/70 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-5 py-3 text-left">Docket / UIN</th>
                      <th className="px-5 py-3 text-left">Case Name</th>
                      <th className="px-5 py-3 text-left">Client</th>
                      <th className="px-5 py-3 text-left">Office</th>
                      <th className="px-5 py-3 text-left">Urgency</th>
                      <th className="px-5 py-3 text-left">Hard Deadline</th>
                      <th className="px-5 py-3 text-left">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {projects.map((p: any) => (
                      <tr key={p.id} className="border-t border-border hover:bg-muted/20 transition-colors group">
                        <td className="px-5 py-3">
                          <a href={`/projects/${p.id}`}
                            className="flex items-center gap-1.5 font-mono font-medium text-foreground group-hover:text-gold transition-colors">
                            {p.docket_number ?? p.project_code}
                            <ExternalLink className="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" />
                          </a>
                        </td>
                        <td className="px-5 py-3 max-w-[260px] truncate" title={p.project_name}>{p.project_name}</td>
                        <td className="px-5 py-3 text-muted-foreground">{p.client?.company_name ?? p.client?.legal_name ?? "—"}</td>
                        <td className="px-5 py-3">
                          <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <MapPin className="h-3 w-3" />{p.patent_office_code ?? "—"}
                          </span>
                        </td>
                        <td className="px-5 py-3">
                          <Badge variant={p.urgency === "Critical" || p.urgency === "High" ? "destructive" : "secondary"}
                            className="text-[10px]">
                            {p.urgency ?? "—"}
                          </Badge>
                        </td>
                        <td className="px-5 py-3 text-xs font-mono text-muted-foreground">
                          {p.hard_deadline ? new Date(p.hard_deadline).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—"}
                        </td>
                        <td className="px-5 py-3"><Badge variant="outline" className="text-[10px]">{p.status}</Badge></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6">
        {loading ? (
          <div className="flex items-center justify-center py-24">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <div className="overflow-x-auto pb-4">
            <div className="min-w-[1100px]">
              {/* Numbered phase circles + connector line */}
              <div className="relative grid grid-cols-4 gap-6 mb-2">
                <div className="absolute top-4 left-[12.5%] right-[12.5%] h-px bg-slate-300 dark:bg-border" />
                {PHASES.map((ph) => (
                  <div key={ph.n} className="flex justify-center relative z-10">
                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-sky-500 text-white text-sm font-semibold shadow">
                      {ph.n}
                    </div>
                  </div>
                ))}
              </div>

              {/* Vertical drop from each circle */}
              <div className="grid grid-cols-4 gap-6">
                {PHASES.map((ph) => (
                  <div key={ph.n} className="flex justify-center">
                    <div className="w-px h-5 bg-slate-300 dark:bg-border" />
                  </div>
                ))}
              </div>

              {/* Phase columns */}
              <div className="grid grid-cols-4 gap-6 items-start">
                {PHASES.map((ph) => (
                  <div key={ph.n} className="space-y-3">
                    {/* Optional top card (provisional branch) */}
                    {ph.topCard && (
                      <div className="flex items-center gap-2">
                        <div className="flex-1 rounded-xl border border-slate-200 dark:border-border bg-card shadow-sm p-2.5 space-y-2">
                          {ph.topCard.stages.map((s) => (
                            <StageBox key={s.name} label={s.label} count={c(s.name)}
                              onClick={() => openStagePopup(s)} />
                          ))}
                        </div>
                        {ph.abandonedRight && <AbandonedCircle />}
                      </div>
                    )}

                    {/* Main phase card */}
                    <div className="rounded-xl border border-slate-200 dark:border-border bg-card shadow-sm p-2.5 space-y-2">
                      {ph.card.stages.map((s) => (
                        <StageBox key={s.name} label={s.label} sub={s.sub} count={c(s.name)}
                          onClick={() => openStagePopup(s)} />
                      ))}
                    </div>

                    {/* Abandoned below */}
                    {ph.abandonedBelow && (
                      <div className="flex flex-col items-center">
                        <div className="w-px h-4 bg-slate-300 dark:bg-border" />
                        <AbandonedCircle />
                      </div>
                    )}
                  </div>
                ))}
              </div>

              {/* Terminal row: Granted → Renewal */}
              <div className="mt-8 flex items-center justify-end gap-3 pr-2">
                <div className="text-xs text-muted-foreground uppercase tracking-wider mr-1">Outcome</div>
                {TERMINAL_STAGES.map((s) => (
                  <button
                    key={s.name}
                    onClick={() => openStagePopup(s)}
                    className="flex items-center gap-2 rounded-lg border border-green-300 dark:border-green-500/40 bg-green-50 dark:bg-green-500/10 px-4 py-2 text-sm font-medium text-green-700 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-500/20 transition-colors"
                  >
                    {s.label}
                    {c(s.name) > 0 && (
                      <span className="min-w-[20px] h-5 px-1 rounded-full bg-green-500 text-white text-[10px] font-semibold flex items-center justify-center">
                        {c(s.name)}
                      </span>
                    )}
                  </button>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
